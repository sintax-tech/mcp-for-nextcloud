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
use OCP\Server;

/** Real own-Local folders: HTTP plans, confirmed native deletion, restoration and whole-tree refusal. */
final class DeleteFolderTest extends ExclusionTestCase {
	/** @return void Explicitly opt in only the disposable actor. */
	protected function setUp(): void {
		parent::setUp();
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', true);
	}

	/** @return void Revoke this fixture's grant. */
	protected function tearDown(): void {
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'files', 'delete', false);
		parent::tearDown();
	}

	/** @return void Basic and Bearer plans have no effects; native trash restores the complete tree and exact bytes. */
	public function testHttpNonemptyFolderAndNativeRestoration(): void {
		foreach (['basic', 'bearer'] as $kind) {
			$client = new McpClient($kind === 'bearer' ? LoginFactory::bearer(self::ALICE) : LoginFactory::basic(self::ALICE));
			$this->makeFile('home', $kind . '/nested/a.txt', 'exact nested bytes');
			$this->makeFile('home', $kind . '/b.txt', 'root bytes');
			$path = $this->path('home', $kind);
			$plan = $client->tool('files_delete', ['path' => $path]);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertSame('folder', $plan->structured()['nodeType']);
			self::assertSame(2, $plan->structured()['tree']['files']);
			self::assertTrue($this->exists(self::ALICE, $path . '/nested/a.txt'));
			$reply = $client->write('files_delete', ['path' => $path, 'id' => $plan->structured()['id'], 'etag' => $plan->structured()['etag']]);
			self::assertTrue($reply->ok(), $reply->describe());
			DeleteReceiptAssertions::observedInOwnTrash($reply->json());
			self::assertFalse($this->exists(self::ALICE, $path));
			$session = Server::get(\OCP\IUserSession::class);
			$before = $session->getUser();
			$user = Server::get(\OCP\IUserManager::class)->get(self::ALICE);
			$session->setUser($user);
			\OC_Util::setupFS(self::ALICE);
			try {
				$manager = Server::get('OCA\\Files_Trashbin\\Trash\\ITrashManager');
				$items = array_values(array_filter($manager->listTrashRoot($user), static fn ($item): bool => $item->getName() === $kind));
				self::assertCount(1, $items);
				$manager->restoreItem($items[0]);
			} finally {
				$session->setUser($before);
			}
			self::assertSame('exact nested bytes', $this->node(self::ALICE, $path . '/nested/a.txt')->getContent());
			self::assertSame('root bytes', $this->node(self::ALICE, $path . '/b.txt')->getContent());
		}
	}

	/** @return void Configured exclusions refuse whole folders and cannot disclose a descendant's name. */
	public function testExclusionConfigurationPreservesWholeTree(): void {
		$file = $this->makeFile('home', 'refuse/private-name.txt', 'private bytes');
		$this->tag($file);
		$this->exclude();
		foreach ([false, true] as $confirm) {
			$reply = $this->mcp()->tool('files_delete', ['path' => $this->path('home', 'refuse'), 'confirm' => $confirm]);
			self::assertTrue($reply->isError(), $reply->describe());
			self::assertStringNotContainsString('private-name', $reply->text());
			self::assertSame('private bytes', $file->getContent());
		}
	}

	/** @return void A shared descendant requires shared confirmation for its entire otherwise eligible tree. */
	public function testSharedDescendantPreservesWholeTree(): void {
		$file = $this->makeFile('home', 'shared/child.txt', 'keep');
		$this->shareToUser($file, self::ALICE, self::BOB);
		foreach ([false, true] as $confirm) {
			$reply = $this->mcp()->tool('files_delete', ['path' => $this->path('home', 'shared'), 'confirm' => $confirm]);
			self::assertTrue($confirm ? $reply->json()['requiresSharedConfirmation'] : $reply->structured()['shared']);
			self::assertSame('keep', $file->getContent());
		}
	}

	/** @return void Personal and selected scope roots are refused; a changed descendant invalidates a prepared ETag. */
	public function testRootsAndChangedTreeAreRefused(): void {
		$file = $this->makeFile('home', 'changed/child.txt', 'before');
		$path = $this->path('home', 'changed');
		$plan = $this->mcp()->tool('files_delete', ['path' => $path]);
		self::assertTrue($plan->ok(), $plan->describe());
		$file->putContent('after change');
		$reply = $this->mcp()->write('files_delete', ['path' => $path, 'etag' => $plan->structured()['etag'], 'id' => $plan->structured()['id']]);
		self::assertTrue($reply->isError(), $reply->describe());
		self::assertSame('after change', $this->node(self::ALICE, $path . '/child.txt')->getContent());
		self::assertTrue($this->mcp()->write('files_delete', ['path' => '/'])->isError());
		$writer = Server::get(\OCA\Mcp\Service\Policy\PolicyWriter::class);
		$state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
		$writer->setResourceScope($state->state()['revision'], 'admin', self::ALICE, 'files', [(string)$file->getParent()->getId()]);
		try {
			self::assertTrue($this->mcp()->write('files_delete', ['path' => $path])->isError());
			self::assertSame('after change', $file->getContent());
		} finally {
			$writer->setResourceScope($state->state()['revision'], 'admin', self::ALICE, 'files', null);
		}
	}
	/** @return void Public native listeners can veto or cause a controlled partial result without changing core/vendor. */
	public function testNativeVoidVetoAndPartialTreeAreMeasured(): void {
		$session = Server::get(\OCP\IUserSession::class);
		$before = $session->getUser();
		$session->setUser(Server::get(\OCP\IUserManager::class)->get(self::ALICE));
		\OC_Util::setupFS(self::ALICE);
		$dispatcher = Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
		$name = \OCP\Files\Events\Node\BeforeNodeDeletedEvent::class;
		try {
			foreach ([false, true] as $partial) {
				$leaf = $partial ? 'partial' : 'veto';
				$file = $this->makeFile('home', $leaf . '/child.txt', 'known bytes');
				$source = $file->getParent();
				$listener = static function ($event) use ($source, $file, $partial): void {
					if ($event->getNode()->getId() !== $source->getId()) { return; }
					if ($partial) { $file->delete(); }
					throw new \OCP\Exceptions\AbortedEventException('Disposable native veto control');
				};
				$dispatcher->addListener($name, $listener);
				try {
					$result = Server::get(GrantPolicy::class)->within(self::ALICE, fn () => Server::get(\OCA\Mcp\Mcp\Tools\ToolRegistry::class)->call('files_delete', ['path' => $this->path('home', $leaf), 'confirm' => true], self::ALICE));
					self::assertTrue($result['isError'] ?? false, json_encode($result));
					$data = json_decode($result['content'][0]['text'], true);
					self::assertSame($partial ? 'uncertain' : 'preserved', $data['status']);
					self::assertTrue($this->exists(self::ALICE, $this->path('home', $leaf)));
					self::assertSame(!$partial, $this->exists(self::ALICE, $this->path('home', $leaf . '/child.txt')));
				} finally {
					$dispatcher->removeListener($name, $listener);
				}
			}
		} finally {
			$session->setUser($before);
		}
	}

}
