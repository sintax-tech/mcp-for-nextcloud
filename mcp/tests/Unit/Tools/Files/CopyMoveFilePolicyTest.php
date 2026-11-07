<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeMessages;
use PHPUnit\Framework\Attributes\DataProvider;

/** End-to-end module behavior for the single-file copy/move policy boundaries. */
final class CopyMoveFilePolicyTest extends FilesToolsTestCase {
	/** Populate both permitted and legacy prohibited sources, with a dotted destination folder. */
	protected function setUp(): void {
		parent::setUp();
		$this->tree->addFile('/alice/files/input.txt', 'original', 'text/plain');
		$this->tree->addFile('/alice/files/legacy.php', 'legacy', 'text/plain');
		$this->tree->addFolder('/alice/files/folder.php');
		CopyMovePolicyFixture::forTest($this)->value = self::policy();
	}

	/** @return array<string, mixed> Active policy allowing only txt. */
	private static function policy(): array {
		return array_replace(FileWritePolicy::defaults(), ['mode' => 'allowlist', 'extensions' => ['txt']]);
	}

	/** @return iterable<string, array{string, bool}> Tool name and planning flag. */
	public static function operations(): iterable {
		foreach (['files_copy', 'files_move'] as $tool) {
			yield $tool . '-plan' => [$tool, true];
			yield $tool . '-confirm' => [$tool, false];
		}
	}

	/**
	 * @param string $tool Tool to exercise.
	 * @param bool $plan Whether to invoke the plan boundary.
	 * @return void
	 */
	#[DataProvider('operations')]
	public function testFinalDestinationIsDeniedWithoutEffects(string $tool, bool $plan): void {
		$before = $this->tree->nodes;
		try {
			$args = ['from' => '/input.txt', 'to' => '/folder.php/output.php', 'confirm_shared' => true];
			$plan ? $this->plan($tool, $args) : $this->tool($tool, $args);
			self::fail('Prohibited destination accepted');
		} catch (FileTypeDenied $e) {
			self::assertSame(FileTypeMessages::denied(), $e->getMessage());
		}
		self::assertSame($before, $this->tree->nodes);
		self::assertSame([], $this->tree->ops);
	}

	/**
	 * @param string $tool Tool to exercise.
	 * @param bool $plan Whether to invoke the plan boundary.
	 * @return void
	 */
	#[DataProvider('operations')]
	public function testLegacySourceIsAllowedByTheFinalName(string $tool, bool $plan): void {
		$args = ['from' => '/legacy.php', 'to' => '/folder.php/output.txt'];
		if ($plan) {
			$this->plan($tool, $args);
			self::assertSame([], $this->tree->ops);
			self::assertArrayNotHasKey('/alice/files/folder.php/output.txt', $this->tree->nodes);
		} else {
			$out = $this->json($tool, $args);
			self::assertSame('legacy', $this->tree->nodes['/alice/files/folder.php/output.txt']['content']);
			$tool === 'files_copy' ? self::assertNotSame($out['idBefore'], $out['idAfter']) : self::assertSame($out['idBefore'], $out['idAfter']);
		}
	}

	/** An accepted first copy never authorizes a prohibited second rename. */
	public function testTwoStepsCannotRenameToAProhibitedType(): void {
		$this->json('files_copy', ['from' => '/input.txt', 'to' => '/stage.txt']);
		$before = $this->tree->nodes;
		try {
			$this->tool('files_move', ['from' => '/stage.txt', 'to' => '/stage.php']);
			self::fail('Second step accepted');
		} catch (FileTypeDenied) {
			self::assertSame($before, $this->tree->nodes);
		}
	}

	/** Both tools re-read after a previously allowed plan, independently of confirmation flags. */
	public function testConfirmationObservesAPolicyChangedAfterPlanning(): void {
		foreach (['files_copy', 'files_move'] as $tool) {
			$fixture = CopyMovePolicyFixture::forTest($this);
			$fixture->value = FileWritePolicy::defaults();
			$args = ['from' => '/input.txt', 'to' => '/output.php', 'confirm_shared' => true];
			$this->plan($tool, $args);
			$fixture->value = self::policy();
			try {
				$this->tool($tool, $args);
				self::fail('Stale plan authorized a write');
			} catch (FileTypeDenied) {
				self::assertSame([], $this->tree->ops);
			}
		}
	}

	/** A policy flip after confirmation preflight is caught in the lock-aware actual sink. */
	public function testActualSinkRechecksPolicyForBothTools(): void {
		foreach (['files_copy', 'files_move'] as $tool) {
			$fixture = CopyMovePolicyFixture::forTest($this);
			$fixture->reads = [self::policy(), array_replace(self::policy(), ['extensions' => []])];
			$fixture->count = 0;
			try {
				$this->tool($tool, ['from' => '/input.txt', 'to' => '/output.txt']);
				self::fail('Sink accepted the revoked name');
			} catch (FileTypeDenied) {
				self::assertSame(2, $fixture->count);
				self::assertSame([], $this->tree->ops);
			}
		}
	}

	/** D9 fails closed with no write, including a direct confirmed call. */
	public function testInvalidPolicyRefusesBothToolsAndPlans(): void {
		CopyMovePolicyFixture::forTest($this)->value = ['version' => 99];
		foreach (self::operations() as [$tool, $plan]) {
			try {
				$args = ['from' => '/input.txt', 'to' => '/output.txt'];
				$plan ? $this->plan($tool, $args) : $this->tool($tool, $args);
				self::fail('Invalid policy accepted');
			} catch (FileWritePolicyUnavailable) {
				self::assertSame([], $this->tree->ops);
			}
		}
	}

	/** Off and absent configuration preserve existing native copy/move behavior. */
	public function testOffAndAbsentPolicyPreserveBehavior(): void {
		foreach ([null, FileWritePolicy::defaults()] as $i => $value) {
			CopyMovePolicyFixture::forTest($this)->value = $value;
			$this->json('files_copy', ['from' => '/input.txt', 'to' => '/off-' . $i . '.php']);
			$this->json('files_move', ['from' => '/off-' . $i . '.php', 'to' => '/moved-' . $i . '.php']);
			self::assertSame('original', $this->tree->nodes['/alice/files/moved-' . $i . '.php']['content']);
		}
	}

	/** A dotted folder is not evaluated as a file; T15-6 evaluates its file descendants. */
	public function testFoldersKeepTheirExistingBehavior(): void {
		CopyMovePolicyFixture::forTest($this)->value = self::policy();
		$this->plan('files_move', ['from' => '/folder.php', 'to' => '/folder.exe']);
		$this->json('files_move', ['from' => '/folder.php', 'to' => '/folder.exe']);
		self::assertArrayHasKey('/alice/files/folder.exe', $this->tree->nodes);
	}

	/** The branch without an optional lock service still checks policy immediately before the copy. */
	public function testUnlockedCopyBranchRechecksTheActualSink(): void {
		$access = new \OCA\Mcp\Platform\FileAccess\NodeAccessInfo($this->users, $this->tree->shareManager());
		$fixture = CopyMovePolicyFixture::forTest($this);
		$fixture->reads = [self::policy(), array_replace(self::policy(), ['extensions' => []])];
		$reorganization = \OCA\Mcp\Tests\Unit\Tools\Files\FilesCatalogWiring::reorganization($access, new \OCA\Mcp\Platform\FileAccess\SharedWriteGuard($access),
			$this->report(), $this->users, fileTypes: $fixture->guard);
		try {
			$reorganization->copy($this->tree->rootFolder(), 'alice', '/input.txt', '/output.txt', null, false);
			self::fail('Unlocked branch skipped sink authorization');
		} catch (FileTypeDenied) {
			self::assertSame(2, $fixture->count);
			self::assertSame([], $this->tree->ops);
		}
	}

	/** Type refusal comes before the existing shared-resource confirmation; allowed names still need it. */
	public function testSharedResourceConfirmationDoesNotAuthorizeTypes(): void {
		$this->tree->addFile('/alice/files/shared.txt', 'shared', 'text/plain', ['scope' => 'shared']);
		foreach (['files_copy', 'files_move'] as $tool) {
			$args = ['from' => '/shared.txt', 'to' => '/output.php'];
			try {
				$this->tool($tool, $args);
				self::fail('Type refusal deferred to shared confirmation');
			} catch (FileTypeDenied) {
				self::assertSame([], $this->tree->ops);
			}
			$args['to'] = '/output.txt';
			$reply = $this->json($tool, $args);
			self::assertTrue($reply['requiresConfirmation']);
			self::assertSame([], $this->tree->ops);
		}
	}

}
