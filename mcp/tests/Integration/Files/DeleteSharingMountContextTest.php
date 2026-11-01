<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Constants;
use OCP\Server;
use OCP\Share\IManager;
use PHPUnit\Framework\Attributes\DataProvider;

/** Separates fresh received-share MCP lookup from native DAV mount initialization and restoration fixtures. */
final class DeleteSharingMountContextTest extends ExclusionTestCase {
	/**
	 * Provides both credential orders without a preparatory DAV request.
	 *
	 * @return iterable<string,array{list<string>}> Independent synthetic paths for both credential orders.
	 */
	public static function orders(): iterable {
		yield 'basic-first' => [['basic', 'bearer']];
		yield 'bearer-first' => [['bearer', 'basic']];
	}

	/**
	 * Reads two newly shared native mounts through Basic and Bearer without a prior DAV listing.
	 *
	 * @param list<string> $order Credential order, with a new native share for each request.
	 * @return void
	 */
	#[DataProvider('orders')]
	public function testFreshReceivedMountsWithoutDavWarmup(array $order): void {
		\OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setGrant(self::BOB, 'files', 'delete', true);
		foreach ($order as $kind) {
			$file = $this->makeFile('home', $kind . '-' . $this->run . '/nested/a.txt', 'fresh mount exact bytes');
			$file->getParent()->newFile('other.txt', 'different HTTP response bytes');
			$share = $this->shareToUser($file->getParent()->getParent(), self::ALICE, self::BOB);
			$manager = Server::get(IManager::class);
			$share = $manager->getShareById($share->getFullId());
			$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_DELETE);
			$manager->updateShare($share);
			$client = new McpClient($kind === 'basic' ? LoginFactory::basic(self::BOB) : LoginFactory::bearer(self::BOB));
			$read = $client->tool('files_read', ['path' => $share->getTarget() . '/nested/a.txt']);
			self::assertTrue($read->ok(), $kind . ' without DAV warmup: ' . $read->describe());
			self::assertSame('fresh mount exact bytes', $read->result()['content'][0]['text']);
			$meta = json_decode($read->result()['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($share->getTarget() . '/nested/a.txt', $meta['path']);
			self::assertSame(strlen('fresh mount exact bytes'), $meta['size']);
			self::assertSame((string)$file->getMimetype(), $meta['mime']);
			self::assertSame((string)$file->getEtag(), $meta['etag']);
			// This later preview checks identity coherence only; it does not attest the first read lookup.
			$plan = $client->tool('files_delete', ['path' => $share->getTarget() . '/nested/a.txt']);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertSame((int)$file->getId(), $plan->structured()['id']);
			self::assertSame('fresh mount exact bytes', $file->getContent());
		}
	}
}
