<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLock;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Tools\Files\FileBackupPreparation;
use OCA\Mcp\Tools\Files\Reorganization;
use OCP\App\IAppManager;
use OCP\Files\Lock\ILock;
use PHPUnit\Framework\TestCase;

/** The early lock barriers themselves: later redundant checks must not mask their removal from the oracle. */
final class EarlyLockBoundaryEffectsContractTest extends TestCase {
	/** @return void no backup callback is entered when preparation finds the original locked */
	public function testBackupPreparationRefusesBeforeCallingTheCopy(): void {
		$tree = new FakeTree($this);
		$id = $tree->addFile('/alice/files/Docs/old.md', 'original', 'text/markdown');
		$locks = new FakeLockManager();
		$locks->put(new FakeLock($id, ILock::TYPE_USER, 'pedro'));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$preparation = new FileBackupPreparation($apps, FakeUsers::manager($this, FakeUsers::DEFAULTS), $locks->service($this), null, null);
		$copied = false;
		$t = new EffectTranscript($this, 'files.backup-early-lock');
		$refusal = null;
		try {
			$preparation->prepare($tree->node('/alice/files/Docs/old.md'), '/Docs/old.md', 'alice', null, false,
				function () use (&$copied): string {
					$copied = true;
					return '/backup-copy';
				});
		} catch (LockWriteFailure $error) {
			$refusal = ['class' => $error::class, 'message' => $error->getMessage()];
		}
		$t->record('before-any-backup', ['path' => '/Docs/old.md', 'lockedBy' => 'pedro'], $refusal,
			['copyEntered' => $copied, 'tree' => FilesEffectState::tree($tree)]);
		$this->assertNotNull($refusal, 'the early barrier itself must refuse, without relying on a later lock check');
		$this->assertFalse($copied);
		$this->assertSame([], $tree->ops);
		$t->assertGolden();
	}

	/** @return void the planning/reorganization precondition refuses before the native move callback is possible */
	public function testTheReorganizationPreconditionRefusesTheLock(): void {
		$gate = new EffectsGate($this);
		$tree = new FakeTree($this);
		$id = $tree->addFile('/alice/files/Docs/old.md', 'original', 'text/markdown');
		$wiring = new FilesFlowWiring($this, $gate, $tree);
		$wiring->locks->put(new FakeLock($id, ILock::TYPE_USER, 'pedro'));
		$reorganization = (new \ReflectionMethod($wiring, 'resolve'))->invoke($wiring, Reorganization::class);
		$t = new EffectTranscript($this, 'files.reorganization-early-lock');
		$refusal = null;
		try {
			$reorganization->assertUnlocked($tree->node('/alice/files/Docs/old.md'), 'alice');
		} catch (LockWriteFailure $error) {
			$refusal = ['class' => $error::class, 'message' => $error->getMessage()];
		}
		$t->record('before-move-dispatch', ['path' => '/Docs/old.md', 'lockedBy' => 'pedro'], $refusal, FilesEffectState::tree($tree));
		$this->assertNotNull($refusal, 'the precondition itself is part of the frozen contract');
		$this->assertSame([], $tree->ops);
		$t->assertGolden();
	}
}
