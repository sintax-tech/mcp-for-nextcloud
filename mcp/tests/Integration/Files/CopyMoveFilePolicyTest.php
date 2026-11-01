<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Access\FileTypes\FileTypeMessages;
use OCP\IAppConfig;
use OCP\Server;

/** Real OAuth MCP calls, native copy/move identity, and policy changes across HTTP requests. */
final class CopyMoveFilePolicyTest extends ExclusionTestCase {
	private McpClient $client;

	/** Seed fixture files using the official Files API, before applying an active policy. */
	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->mcp(self::ALICE, 'bearer');
		$folder = $this->ensureFolder(self::ALICE, $this->run . '/folder.php');
		$folder->newFile('input.txt', 'original');
		$folder->newFile('legacy.php', 'legacy');
		$this->policy(true);
	}

	/** Restore the absent policy key; the harness removes this disposable fixture and its volumes. */
	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		$config->deleteKey('mcp', FileWritePolicy::KEY);
		$config->clearCache(false);
		parent::tearDown();
	}

	/**
	 * @param bool $active Whether only txt may be written.
	 * @return void
	 */
	private function policy(bool $active): void {
		Server::get(FileWritePolicy::class)->save($active ? 'allowlist' : 'off', ['txt'], false);
	}

	/**
	 * @param string $name File basename in the dotted fixture folder.
	 * @return string Absolute user-relative path for the MCP tools.
	 */
	private function file(string $name): string {
		return '/' . $this->run . '/folder.php/' . $name;
	}

	/** Plans and direct confirmations deny txt to php while allowing php to txt, preserving native IDs. */
	public function testFinalNameControlsBothToolsAndBothBoundaries(): void {
		foreach (['files_copy', 'files_move'] as $tool) {
			$args = ['from' => $this->file('input.txt'), 'to' => $this->file('blocked.php')];
			foreach ([false, true] as $confirm) {
				$reply = $this->client->tool($tool, $args + ['confirm' => $confirm, 'confirm_shared' => true]);
				self::assertTrue($reply->isError(), $reply->describe());
				self::assertStringContainsString(FileTypeMessages::denied(), $reply->text());
				self::assertFalse($this->exists(self::ALICE, $args['to']));
				self::assertSame('original', $this->node(self::ALICE, $args['from'])->getContent());
			}
			$args = ['from' => $this->file('legacy.php'), 'to' => $this->file($tool . '.txt')];
			$id = (int)$this->node(self::ALICE, $args['from'])->getId();
			$plan = $this->client->tool($tool, $args);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertFalse($this->exists(self::ALICE, $args['to']), 'Planning creates nothing');
			$reply = $this->client->write($tool, $args);
			self::assertTrue($reply->ok(), $reply->describe());
			$result = $reply->json();
			self::assertSame($id, $result['idBefore']);
			$tool === 'files_copy' ? self::assertNotSame($id, $result['idAfter']) : self::assertSame($id, $result['idAfter']);
			self::assertSame('legacy', $this->node(self::ALICE, $args['to'])->getContent());
		}
	}

	/** A valid first copy leaves the copied file intact when the second-step rename is forbidden. */
	public function testTwoStepsCannotBypassThePolicy(): void {
		$args = ['from' => $this->file('input.txt'), 'to' => $this->file('stage.txt')];
		$reply = $this->client->write('files_copy', $args);
		self::assertTrue($reply->ok(), $reply->describe());
		$id = (int)$this->node(self::ALICE, $args['to'])->getId();
		$reply = $this->client->write('files_move', ['from' => $args['to'], 'to' => $this->file('stage.php')]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertStringContainsString(FileTypeMessages::denied(), $reply->text());
		self::assertSame($id, (int)$this->node(self::ALICE, $args['to'])->getId());
		self::assertSame('original', $this->node(self::ALICE, $args['to'])->getContent());
		self::assertFalse($this->exists(self::ALICE, $this->file('stage.php')));
	}

	/** A fresh HTTP confirmation observes a policy enabled after the plan; off keeps native behavior. */
	public function testChangedPolicyAndOffAcrossRequests(): void {
		foreach (['files_copy', 'files_move'] as $tool) {
			$this->policy(false);
			$args = ['from' => $this->file('input.txt'), 'to' => $this->file($tool . '.php')];
			$plan = $this->client->tool($tool, $args);
			self::assertTrue($plan->ok(), $plan->describe());
			$this->policy(true);
			$reply = $this->client->write($tool, $args);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertStringContainsString(FileTypeMessages::denied(), $reply->text());
			self::assertFalse($this->exists(self::ALICE, $args['to']));
			$this->policy(false);
			$reply = $this->client->write($tool, $args);
			self::assertTrue($reply->ok(), $reply->describe());
			self::assertSame('original', $this->node(self::ALICE, $args['to'])->getContent());
		}
	}

	/** ETag conflicts continue to refuse without creating a destination. */
	public function testStaleEtagRemainsARefusal(): void {
		foreach (['files_copy', 'files_move'] as $tool) {
			$args = ['from' => $this->file('input.txt'), 'to' => $this->file('stale.txt'), 'etag' => 'stale'];
			$reply = $this->client->write($tool, $args);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertFalse($this->exists(self::ALICE, $args['to']));
			self::assertSame('original', $this->node(self::ALICE, $args['from'])->getContent());
		}
	}

	/** The existing team-folder confirmation is required for allowed names, never a type-policy bypass. */
	public function testSharedConfirmationIsPreservedAndDoesNotBypassPolicy(): void {
		$this->makeFile('groupfolder', 'shared.txt', 'team content');
		foreach (['files_copy', 'files_move'] as $tool) {
			$args = ['from' => $this->path('groupfolder', 'shared.txt'), 'to' => $this->path('groupfolder', $tool . '.php')];
			$reply = $this->client->tool($tool, $args + ['confirm' => true, 'confirm_shared' => true]);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertStringContainsString(FileTypeMessages::denied(), $reply->text());
			$args['to'] = $this->path('groupfolder', $tool . '.txt');
			$reply = $this->client->tool($tool, $args + ['confirm' => true]);
			self::assertTrue($reply->ok(), $reply->describe());
			self::assertTrue($reply->json()['requiresConfirmation'] ?? false, $reply->describe());
			self::assertFalse($this->exists(self::ALICE, $args['to']));
			$reply = $this->client->tool($tool, $args + ['confirm' => true, 'confirm_shared' => true]);
			self::assertTrue($reply->ok(), $reply->describe());
			self::assertSame('team content', $this->node(self::ALICE, $args['to'])->getContent());
		}
	}

	/** A real administrator uses the same file policy on both tools and both boundaries. */
	public function testAdministratorHasNoImplicitExemption(): void {
		$groups = Server::get(\OCP\IGroupManager::class);
		$admin = $groups->get('admin');
		$user = Server::get(\OCP\IUserManager::class)->get(self::ALICE);
		self::assertNotNull($admin);
		self::assertNotNull($user);
		self::assertFalse($admin->inGroup($user));
		$admin->addUser($user);
		try {
			self::assertTrue($groups->isAdmin(self::ALICE));
			foreach (['files_copy', 'files_move'] as $tool) {
				foreach ([false, true] as $confirm) {
					$args = ['from' => $this->file('input.txt'), 'to' => $this->file('admin.php'), 'confirm' => $confirm, 'confirm_shared' => true];
					$reply = $this->client->tool($tool, $args);
					self::assertTrue($reply->isError(), $reply->describe());
					self::assertStringContainsString(FileTypeMessages::denied(), $reply->text());
					self::assertFalse($this->exists(self::ALICE, $args['to']));
				}
			}
		} finally {
			$admin->removeUser($user);
		}
	}

}
