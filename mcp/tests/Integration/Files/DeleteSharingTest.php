<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserManager;
use OCA\Mcp\Tests\Integration\Support\NativeActorContext;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

/** Real owner/recipient requests, native DELETE without UPDATE, reservations and native trash behavior. */
final class DeleteSharingTest extends ExclusionTestCase {
	/** @return void Both disposable actors explicitly opt in. */
	protected function setUp(): void {
		parent::setUp();
		foreach ([self::ALICE, self::BOB] as $uid) { Server::get(GrantPolicy::class)->setGrant($uid, 'files', 'delete', true); }
	}

	/** @return void Revoke only these fixture grants. */
	protected function tearDown(): void {
		foreach ([self::ALICE, self::BOB] as $uid) { Server::get(GrantPolicy::class)->setGrant($uid, 'files', 'delete', false); }
		parent::tearDown();
	}

	/** @param Node $node Owner source. @param int $permissions Explicit share bitmask. @return IShare Native received share; no permission inferred from MCP. */
	private function share(Node $node, int $permissions = Constants::PERMISSION_READ | Constants::PERMISSION_DELETE): IShare {
		$share = $this->shareToUser($node, self::ALICE, self::BOB);
		$share = Server::get(IManager::class)->getShareById($share->getFullId());
		$share->setPermissions($permissions);
		Server::get(IManager::class)->updateShare($share);
		return $share;
	}

	/** @param string $uid Synthetic actor. @param callable():mixed $work Native API operation. @return mixed Its result with session/filesystem restored afterward. */
	private function asUser(string $uid, callable $work): mixed {
		return NativeActorContext::run($uid, $work);
	}

	/** @param string $uid Synthetic trash owner. @param string $name Known unique basename. @return object The single real native item. */
	private function trash(string $uid, string $name): object {
		return $this->asUser($uid, function () use ($uid, $name): object {
			$user = Server::get(IUserManager::class)->get($uid);
			$items = array_values(array_filter(Server::get('OCA\\Files_Trashbin\\Trash\\ITrashManager')->listTrashRoot($user), static fn ($item): bool => $item->getName() === $name));
			self::assertCount(1, $items);
			return $items[0];
		});
	}

	/** @return void A recipient may delete a descendant with DELETE but not UPDATE; roots and missing shared confirmation have no effect. */
	public function testReceivedFilesBasicAndBearerDeleteWithoutUpdateAndRestore(): void {
		foreach (['basic', 'bearer'] as $kind) {
			$name = 'fd3-' . $kind . '-' . $this->run . '.txt';
			$file = $this->makeFile('home', $kind . '/nested/' . $name, 'fd3 exact shared bytes');
			$root = $file->getParent()->getParent();
			$share = $this->share($root);
			$target = $share->getTarget();
			$path = $target . '/nested/' . $name;
			$client = new McpClient($kind === 'bearer' ? LoginFactory::bearer(self::BOB) : LoginFactory::basic(self::BOB));
			$read = $client->tool('files_read', ['path' => $path]);
			self::assertTrue($read->ok(), $read->describe());
			$metadata = json_decode($read->result()['content'][1]['text'], true);
			self::assertTrue($metadata['access']['permissions']['delete']);
			self::assertFalse($metadata['access']['permissions']['update']);
			self::assertTrue($client->write('files_delete', ['path' => $target, 'confirm_shared' => true])->isError());
			$plan = $client->tool('files_delete', ['path' => $path]);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertTrue($plan->structured()['shared']);
			self::assertArrayNotHasKey('owner', $plan->structured());
			self::assertArrayNotHasKey('recipients', $plan->structured());
			$request = $client->write('files_delete', ['path' => $path]);
			self::assertTrue($request->json()['requiresSharedConfirmation']);
			self::assertSame('fd3 exact shared bytes', $file->getContent());
			$reply = $client->write('files_delete', ['path' => $path, 'confirm_shared' => true, 'id' => $plan->structured()['id'], 'etag' => $plan->structured()['etag']]);
			self::assertTrue($reply->ok(), $reply->describe());
			self::assertSame('removed_destination_unconfirmed', $reply->json()['status']);
			self::assertFalse($this->exists(self::ALICE, $this->path('home', $kind . '/nested/' . $name)));
			self::assertFalse($this->exists(self::BOB, $path));
			$ownerItem = $this->trash(self::ALICE, $name);
			$actorItem = $this->trash(self::BOB, $name);
			// The native restore falls back to Bob's own root: the received destination has no CREATE.
			$this->asUser(self::BOB, fn () => Server::get('OCA\\Files_Trashbin\\Trash\\ITrashManager')->restoreItem($actorItem));
			$this->later(fn () => $this->deleteQuietly(self::BOB, $name));
			self::assertSame('fd3 exact shared bytes', $this->node(self::BOB, '/' . $name)->getContent());
			$this->asUser(self::ALICE, fn () => Server::get('OCA\\Files_Trashbin\\Trash\\ITrashManager')->restoreItem($ownerItem));
			self::assertSame('fd3 exact shared bytes', $this->node(self::ALICE, $this->path('home', $kind . '/nested/' . $name))->getContent());
		}
	}

	/** @return void An owner can delete the original shared folder; a received non-root subtree also uses actor DELETE only. */
	public function testOwnerOriginalAndReceivedNonemptySubfolder(): void {
		foreach ([false, true] as $received) {
			$folderName = $received ? 'fd3-sub-' . $this->run : 'fd3-owned-' . $this->run;
			$file = $this->makeFile('home', ($received ? 'incoming/' : '') . $folderName . '/nested/a.txt', 'whole shared tree');
			$source = $file->getParent()->getParent();
			$share = $this->share($received ? $source->getParent() : $source);
			$uid = $received ? self::BOB : self::ALICE;
			$path = $received ? $share->getTarget() . '/' . $folderName : $this->path('home', $folderName);
			$plan = $this->mcp($uid)->tool('files_delete', ['path' => $path]);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertTrue($plan->structured()['shared']);
			self::assertSame(1, $plan->structured()['tree']['files']);
			$ask = $this->mcp($uid)->write('files_delete', ['path' => $path]);
			self::assertTrue($ask->json()['requiresSharedConfirmation']);
			self::assertSame('whole shared tree', $file->getContent());
			$reply = $this->mcp($uid)->write('files_delete', ['path' => $path, 'confirm_shared' => true, 'id' => $plan->structured()['id'], 'etag' => $plan->structured()['etag']]);
			self::assertTrue($reply->ok(), $reply->describe());
			self::assertFalse($this->exists($uid, $path));
			$item = $this->trash(self::ALICE, $folderName);
			$this->asUser(self::ALICE, fn () => Server::get('OCA\\Files_Trashbin\\Trash\\ITrashManager')->restoreItem($item));
			self::assertSame('whole shared tree', $this->node(self::ALICE, $this->path('home', ($received ? 'incoming/' : '') . $folderName . '/nested/a.txt'))->getContent());
		}
	}

	/** @return void DELETE revoked after preview and UPDATE without DELETE never authorize the sink. */
	public function testDeleteRevocationAndUpdateOnlyRefuse(): void {
		$file = $this->makeFile('home', 'revoked/a.txt', 'keep native bytes');
		$share = $this->share($file->getParent());
		$path = $share->getTarget() . '/a.txt';
		$plan = $this->mcp(self::BOB)->tool('files_delete', ['path' => $path]);
		self::assertTrue($plan->ok(), $plan->describe());
		$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		Server::get(IManager::class)->updateShare($share);
		$reply = $this->mcp(self::BOB)->write('files_delete', ['path' => $path, 'confirm_shared' => true, 'id' => $plan->structured()['id'], 'etag' => $plan->structured()['etag']]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertSame('keep native bytes', $file->getContent());
	}

	/** @return void Hidden ancestors outside the received root never leak through a local share alias. */
	public function testHiddenOwnerAncestorRefusesReceivedChild(): void {
		$file = $this->makeFile('home', 'private-parent/incoming/a.txt', 'hidden bytes');
		$share = $this->share($file->getParent());
		$this->tag($file->getParent()->getParent());
		$this->exclude();
		$reply = $this->mcp(self::BOB)->write('files_delete', ['path' => $share->getTarget() . '/a.txt', 'confirm_shared' => true]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertStringNotContainsString('private-parent', $reply->text());
		self::assertSame('hidden bytes', $file->getContent());
	}
	/** @return void An innocently named received alias cannot release a historical NFC owner backup reservation. */
	public function testHistoricalOwnerReservationRefusesAlias(): void {
		$config = Server::get(\OCP\IAppConfig::class);
		$before = Server::get(\OCA\Mcp\Platform\Backups\BackupSettingsDocument::class)->read();
		$writer = Server::get(\OCA\Mcp\Service\BackupSettingsWriter::class);
		$state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
		$name = 'FD3 Café ' . $this->run;
		try {
			$writer->save($state->state()['revision'], $name, true);
			$file = $this->ensureFolder(self::ALICE, $name . '/innocent-' . $this->run)->newFile('a.txt', 'reserved bytes');
			$this->later(fn () => $this->deleteQuietly(self::ALICE, $name));
			$share = $this->share($file->getParent());
			$writer->save($state->state()['revision'], 'FD3 current ' . $this->run, true);
			foreach ([false, true] as $confirm) {
				$reply = $this->mcp(self::BOB)->tool('files_delete', ['path' => $share->getTarget() . '/a.txt', 'confirm' => $confirm, 'confirm_shared' => true]);
				self::assertTrue($reply->isError(), $reply->describe());
				self::assertStringNotContainsString($name, $reply->text());
				self::assertSame('reserved bytes', $file->getContent());
			}
		} finally {
			$before === null ? $config->deleteKey('mcp', \OCA\Mcp\Platform\Backups\BackupLocation::KEY)
				: $config->setValueString('mcp', \OCA\Mcp\Platform\Backups\BackupLocation::KEY, $before, false);
			Server::get(\OCA\Mcp\Platform\Backups\BackupLocation::class)->invalidate();
		}
	}

	/** @return void A selected received scope root and a subtree containing a hidden child both refuse without deleting siblings. */
	public function testReceivedScopeRootAndHiddenChildRefuseWholeTree(): void {
		$file = $this->makeFile('home', 'scope/incoming/sub/a.txt', 'keep whole tree');
		$share = $this->share($file->getParent()->getParent()->getParent());
		$path = $share->getTarget() . '/incoming';
		$writer = Server::get(\OCA\Mcp\Service\Policy\PolicyWriter::class);
		$state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
		// A long-lived CLI fixture predates the new share; refresh its public mount setup before native scope admission.
		\OCA\Mcp\Tests\Integration\Support\FixtureFilesystem::tearDown();
		$this->asUser(self::BOB, fn () => $writer->setResourceScope($state->state()['revision'], 'admin', self::BOB, 'files', [(string)$file->getParent()->getParent()->getId()]));
		try {
			$reply = $this->mcp(self::BOB)->write('files_delete', ['path' => $path, 'confirm_shared' => true]);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertSame('keep whole tree', $file->getContent());
		} finally {
			$writer->setResourceScope($state->state()['revision'], 'admin', self::BOB, 'files', null);
		}
		$this->tag($file);
		$this->exclude();
		$reply = $this->mcp(self::BOB)->write('files_delete', ['path' => $path, 'confirm_shared' => true]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertStringNotContainsString('a.txt', $reply->text());
		self::assertSame('keep whole tree', $file->getContent());
	}

}
