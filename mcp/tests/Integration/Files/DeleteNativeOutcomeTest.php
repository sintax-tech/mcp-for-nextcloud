<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCP\Server;

/** Deliberately injected native outcomes, only in the explicit disposable fault cell; not frequency evidence. */
final class DeleteNativeOutcomeTest extends ExclusionTestCase {
	/** @return void Require explicit disposable instrumentation; ordinary unmodified cells cannot simulate these faults. */
	protected function setUp(): void {
		parent::setUp();
		if (!is_file('/tmp/mcp-fd1-faults')) {
			self::markTestSkipped('Requires the explicit FD-1 disposable-core fault fixture.');
		}
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', true);
	}

	/** @return void Revoke only the grant added by this fixture. */
	protected function tearDown(): void {
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', false);
		parent::tearDown();
	}

	/** @return void Node void after a native typed veto must not be reported as successful removal. */
	public function testNativeVoidVetoIsPreservedError(): void {
		$file = $this->makeFile('home', 'fd1-veto.txt', 'still here');
		$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'fd1-veto.txt')]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertSame('preserved', $reply->json()['status']);
		self::assertSame('still here', $file->getContent());
		self::assertStringNotContainsString('permanent deletion', $reply->text());
	}

	/** @return void A native exception after removal must be reported as uncertain, with no automatic retry. */
	public function testNativeExceptionAfterRemovalIsUncertain(): void {
		$this->makeFile('home', 'fd1-late.txt', 'known bytes');
		$reply = $this->mcp()->write('files_delete', ['path' => $this->path('home', 'fd1-late.txt')]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertSame('uncertain', $reply->json()['status']);
		self::assertFalse($this->exists(self::ALICE, $this->path('home', 'fd1-late.txt')));
		self::assertFalse($reply->json()['recoveryConfirmed']);
	}
}
