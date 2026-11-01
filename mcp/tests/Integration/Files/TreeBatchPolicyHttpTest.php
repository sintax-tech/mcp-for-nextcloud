<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Access\FileTypes\FileTypeMessages;
use OCA\Mcp\Tools\Files\{Batch, BatchStore};
use OCP\IAppConfig;
use OCP\Server;

/** Real OAuth batch/undo boundaries, including denial before a missing recorded parent can be recreated. */
final class TreeBatchPolicyHttpTest extends ExclusionTestCase {
	/** Seed API fixtures before enabling the policy used by the real MCP endpoint.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->makeFile('home', 'a.txt', 'alpha');
		$this->makeFile('home', 'b.txt', 'beta');
		Server::get(FileWritePolicy::class)->save('allowlist', ['txt'], false);
	}

	/** Restore the absent policy key; the base only cleans its own disposable integration fixtures.
	 * @return void
	 */
	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		$config->deleteKey('mcp', FileWritePolicy::KEY);
		$config->clearCache(false);
		parent::tearDown();
	}

	/** Planning and a direct confirmation both refuse a forbidden intermediate before any mkdir.
	 * @return void
	 */
	public function testBatchDeniedBeforeFoldersAndBytesChange(): void {
		$client = $this->mcp(self::ALICE, 'bearer');
		$parent = $this->path('home', 'new');
		$args = ['mkdirs' => [$parent], 'moves' => [
			['from' => $this->path('home', 'a.txt'), 'to' => $parent . '/stage.php'],
			['from' => $parent . '/stage.php', 'to' => $parent . '/final.txt'],
		]];
		$plan = $client->tool('files_move_batch', $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertFalse($plan->structured()['ok'], $plan->raw);
		self::assertContains(['from' => $this->path('home', 'a.txt'), 'to' => $parent . '/stage.php',
			'reason' => FileTypeMessages::denied()], $plan->structured()['denied']);
		self::assertFalse($this->exists(self::ALICE, $parent));
		self::assertSame('alpha', $this->node(self::ALICE, $this->path('home', 'a.txt'))->getContent());
		$reply = $client->write('files_move_batch', $args);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertFalse($this->exists(self::ALICE, $parent));
		self::assertSame('alpha', $this->node(self::ALICE, $this->path('home', 'a.txt'))->getContent());
	}

	/** D8: a refused inverse preserves the row, ID and bytes, and does not create its missing original parent.
	 * @return void
	 */
	public function testUndoDeniedBeforeRecreatingRecordedParent(): void {
		$store = Server::get(BatchStore::class);
		$parent = $this->path('home', 'missing');
		$node = $this->node(self::ALICE, $this->path('home', 'a.txt'));
		$id = $store->insert(new Batch(null, self::ALICE, time(), [[
			'from' => $parent . '/original.php', 'to' => $this->path('home', 'a.txt'), 'toId' => (int)$node->getId(),
		]], [$parent], null));
		$before = $store->find($id, self::ALICE)->toRow();
		$client = $this->mcp(self::ALICE, 'bearer');
		$plan = $client->tool('files_undo_batch', ['batch_id' => $id]);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertFalse($plan->structured()['ok'], $plan->raw);
		self::assertContains(FileTypeMessages::denied(), array_column($plan->structured()['conflicts'], 'reason'));
		self::assertSame($before, $store->find($id, self::ALICE)->toRow());
		self::assertFalse($this->exists(self::ALICE, $parent));
		self::assertSame('alpha', $this->node(self::ALICE, $this->path('home', 'a.txt'))->getContent());
		$reply = $client->write('files_undo_batch', ['batch_id' => $id]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame(0, $reply->json()['undone']);
		self::assertNotEmpty($reply->json()['conflicts']);
		self::assertSame($before, $store->find($id, self::ALICE)->toRow());
		self::assertFalse($this->exists(self::ALICE, $parent));
		self::assertSame('alpha', $this->node(self::ALICE, $this->path('home', 'a.txt'))->getContent());
		self::assertSame($node->getId(), $this->node(self::ALICE, $this->path('home', 'a.txt'))->getId());
	}

	/** An entirely allowed batch and inverse preserve the existing native identity and receipt.
	 * @return void
	 */
	public function testAllowedBatchAndUndoPreserveNativeIdentity(): void {
		$client = $this->mcp(self::ALICE, 'bearer');
		$from = $this->path('home', 'a.txt');
		$to = $this->path('home', 'moved.txt');
		$id = $this->node(self::ALICE, $from)->getId();
		$reply = $client->write('files_move_batch', ['moves' => [['from' => $from, 'to' => $to]]]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame($id, $this->node(self::ALICE, $to)->getId());
		$undo = $client->write('files_undo_batch', ['batch_id' => $reply->json()['batch_id']]);
		self::assertTrue($undo->ok(), $undo->describe());
		self::assertSame(1, $undo->json()['undone']);
		self::assertSame($id, $this->node(self::ALICE, $from)->getId());
		self::assertSame('alpha', $this->node(self::ALICE, $from)->getContent());
	}
}
