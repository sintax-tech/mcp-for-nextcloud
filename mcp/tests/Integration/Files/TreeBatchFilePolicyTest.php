<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Service\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Platform\FileAccess\{NodeAccessInfo, SharedWriteGuard};
use OCA\Mcp\Tools\Files\{CopyMoveContext, FileTypePreflight, ListedTreeCopy, MoveReport, Reorganization};
use OCP\Files\{Folder, IRootFolder};
use OCP\IAppConfig;
use OCP\Server;

/**
 * D10 B4/M1 against a second Apache WebDAV process, reusing the T15-0 protocol without locks or pauses copied
 * into the harness. Run unchanged in NC35 and NC32. Each fixture stays in its own disposable instance folder;
 * no production rollback or file removal is used. The pause is explicitly between production preflight and sink.
 */
final class TreeBatchFilePolicyTest extends IntegrationTestCase {
	/** Fixture root used by the same native Node APIs as the handlers. */
	private Folder $root;
	/** An independent authenticated Apache client, copied from the reviewed T15-0 proof. */
	private TreePolicySecondClient $other;
	/** Per-test unique folder; never reused or removed by this test. */
	private string $base;
	/** Native services with the real persisted policy. */
	private CopyMoveContext $context;

	/** Establish session A and B, seed only allowed descendants, and enable the real policy.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\OC_Util::tearDownFS();
		\OC_Util::setupFS(Fixtures::ALICE);
		$this->root = Server::get(IRootFolder::class)->getUserFolder(Fixtures::ALICE);
		$this->other = new TreePolicySecondClient(LoginFactory::basic(Fixtures::ALICE));
		$this->base = 't15-6-' . bin2hex(random_bytes(5));
		$folder = $this->root->newFolder($this->base);
		$source = $folder->newFolder('src.php');
		$source->newFile('a.txt', 'alpha');
		$source->newFolder('sub')->newFile('b.txt', 'beta');
		$users = Server::get(\OCP\IUserManager::class);
		$shares = Server::get(\OCP\Share\IManager::class);
		$access = new NodeAccessInfo($users, $shares);
		$report = new MoveReport(\OC::$server, $shares, Server::get(\OCP\App\IAppManager::class));
		$this->context = (new Reorganization($access, new SharedWriteGuard($access), $report, $users,
			fileTypes: Server::get(FileTypeGuard::class)))->copyMoveContext();
		Server::get(FileWritePolicy::class)->save('allowlist', ['txt'], false);
	}

	/** Restore only app configuration; the official harness destroys fixture containers and volumes.
	 * @return void
	 */
	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		$config->deleteKey('mcp', FileWritePolicy::KEY);
		$config->clearCache(false);
		parent::tearDown();
	}

	/** @return Folder The fresh source node in session A. */
	private function source(): Folder {
		$source = $this->root->get($this->base . '/src.php');
		self::assertInstanceOf(Folder::class, $source);
		return $source;
	}

	/** B4 positive control: every listed byte reaches the destination, including a dotted source folder.
	 * @return void
	 */
	public function testAllowedListedCopyPreservesAllBytes(): void {
		$source = $this->source();
		$to = '/' . $this->base . '/copy.php';
		$list = (new FileTypePreflight($this->context))->inspect($source, $to, $this->context->policy()->snapshot(), true);
		$out = (new ListedTreeCopy($this->context))->execute($this->root, Fixtures::ALICE, $source, $to, $list);
		self::assertFalse($out['partial']);
		self::assertCount(4, $out['created']);
		self::assertSame('alpha', $this->root->get($this->base . '/copy.php/a.txt')->getContent());
		self::assertSame('beta', $this->root->get($this->base . '/copy.php/sub/b.txt')->getContent());
	}

	/** B4: late PUT is excluded; late MOVE of a listed file is a conflict, never copying its new name.
	 * @return void
	 */
	public function testSecondClientCannotExpandTheValidatedCopyList(): void {
		$source = $this->source();
		$to = '/' . $this->base . '/copy';
		$list = (new FileTypePreflight($this->context))->inspect($source, $to, $this->context->policy()->snapshot(), true);
		self::assertContains($this->other->put($this->base . '/src.php/sub/late.php', 'intruder'), [201, 204]);
		self::assertContains($this->other->move($this->base . '/src.php/a.txt', $this->base . '/src.php/a.php'), [201, 204]);
		$out = (new ListedTreeCopy($this->context))->execute($this->root, Fixtures::ALICE, $source, $to, $list);
		self::assertTrue($out['partial']);
		self::assertNotEmpty($out['conflicts']);
		self::assertContains($to . '/a.txt', $out['not_copied']);
		self::assertFalse($this->root->nodeExists($this->base . '/copy/sub/late.php'));
		self::assertFalse($this->root->nodeExists($this->base . '/copy/a.php'));
		self::assertFalse($this->root->nodeExists($this->base . '/copy/a.txt'));
		self::assertSame('beta', $this->root->get($this->base . '/copy/sub/b.txt')->getContent());
		self::assertSame('alpha', $this->root->get($this->base . '/src.php/a.php')->getContent());
		self::assertSame('intruder', $this->root->get($this->base . '/src.php/sub/late.php')->getContent());
	}

	/** M1 accepted limit: a late PUT after the final check travels with the native folder move; never skip.
	 * @return void
	 */
	public function testSecondClientLateChildTravelsWithNativeMove(): void {
		$source = $this->source();
		$id = $source->getId();
		$to = '/' . $this->base . '/moved';
		$this->context->policy()->assertProjected($source, $to);
		$moved = $this->context->policy()->write($source, $to, function () use ($source, $to) {
			// Deliberately after the final whole-tree check and immediately before Node::move (M1).
			self::assertContains($this->other->put($this->base . '/src.php/sub/late.php', 'accepted M1 limit'), [201, 204]);
			return $source->move($this->context->absolute($this->root, $to));
		});
		self::assertSame($id, $moved->getId());
		self::assertFalse($this->root->nodeExists($this->base . '/src.php'));
		self::assertSame('accepted M1 limit', $this->root->get($this->base . '/moved/sub/late.php')->getContent());
		self::assertSame('alpha', $this->root->get($this->base . '/moved/a.txt')->getContent());
	}

	/** A child already forbidden at final preflight denies the whole move and creates no destination.
	 * @return void
	 */
	public function testForbiddenChildBeforeFinalBoundaryRefusesWholeMove(): void {
		self::assertContains($this->other->put($this->base . '/src.php/sub/blocked.php', 'blocked'), [201, 204]);
		try {
			$this->context->policy()->write($this->source(), '/' . $this->base . '/blocked',
				fn () => self::fail('The native sink must not run'));
			self::fail('A forbidden descendant must refuse the whole move');
		} catch (\OCA\Mcp\Tools\Common\FileTypeDenied) {
			self::assertFalse($this->root->nodeExists($this->base . '/blocked'));
			self::assertSame('blocked', $this->root->get($this->base . '/src.php/sub/blocked.php')->getContent());
		}
	}
}
