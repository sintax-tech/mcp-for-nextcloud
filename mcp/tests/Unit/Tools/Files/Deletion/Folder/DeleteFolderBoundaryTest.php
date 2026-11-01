<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Folder;

use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Tools\Files\Deletion\Folder\DeleteFolderBoundary;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Complete admission is required before returning any folder observation. */
final class DeleteFolderBoundaryTest extends TestCase {
	/** @var FakeTree Synthetic directory metadata and listing effects. */
	private FakeTree $tree;
	/** @var FilesAccessGuard Exclusion metadata gate. */
	private FilesAccessGuard $guard;
	/** @var FilesScope Selected roots, including nested selections. */
	private FilesScope $scope;

	/** @return void Prepare a nonempty tree without mutating any real storage. */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->mountPath = '/alice/';
		$this->tree->addFolder('/alice/files/folder');
		$this->tree->addFolder('/alice/files/folder/nested');
		$this->tree->addFile('/alice/files/folder/nested/secret.txt', 'known', 'text/plain');
		$this->guard = $this->createMock(FilesAccessGuard::class);
		$this->guard->method('isConfigurationValid')->willReturn(true);
		$this->scope = $this->createMock(FilesScope::class);
	}

	/** @return DeleteFolderBoundary Boundary using only the controlled metadata collaborators. */
	private function boundary(): DeleteFolderBoundary {
		return new DeleteFolderBoundary($this->guard, $this->scope);
	}

	/** @return array<string,mixed> Inspect the synthetic source without invoking a native sink. */
	private function inspect(DeleteFolderBoundary $boundary, ?callable $admit = null): array {
		return $boundary->inspect($this->tree->node('/alice/files'), 'alice', $this->tree->node('/alice/files/folder'), $admit ?? static function (): void {});
	}

	/** @return void A complete nonempty tree has deterministic metadata and counts but no effects. */
	public function testCompleteObservationCountsBytesAndIncludesRoot(): void {
		$before = $this->inspect($this->boundary());
		self::assertSame(['nodes' => 3, 'files' => 1, 'folders' => 2, 'bytes' => 5], array_diff_key($before, ['fingerprint' => true]));
		self::assertSame($before, $this->inspect($this->boundary()));
		$this->tree->nodes['/alice/files/folder/nested/secret.txt']['etag'] = 'external-change';
		self::assertNotSame($before['fingerprint'], $this->inspect($this->boundary())['fingerprint']);
		self::assertSame([], $this->tree->ops);
	}

	/** @return void Configured exclusions refuse the entire source before any enumeration. */
	public function testExposureGateRunsBeforeEnumeration(): void {
		$this->guard->method('getHiddenTagIds')->willReturn([7]);
		try {
			$this->inspect($this->boundary());
			self::fail('Expected refusal');
		} catch (ToolFailure) {
			self::assertSame([], $this->tree->listings);
		}
	}

	/** @return void A selected source root is refused without listing its children. */
	public function testSelectedRootIsNotDeletable(): void {
		$this->scope->method('items')->willReturn([FilesScope::canonical($this->tree->node('/alice/files/folder')->getId())]);
		try {
			$this->inspect($this->boundary());
			self::fail('Expected refusal');
		} catch (ToolFailure) {
			self::assertSame([], $this->tree->listings);
		}
	}

	/** @return void A selected descendant root cannot be removed through an admitted ancestor. */
	public function testNestedSelectedRootRefusesWholeTree(): void {
		$this->scope->method('items')->willReturn([FilesScope::canonical($this->tree->node('/alice/files/folder/nested')->getId())]);
		$this->expectException(ToolFailure::class);
		$this->inspect($this->boundary());
	}

	/** @return void Node, byte and depth limits each reject incomplete observations. */
	public function testEveryWalkBudgetFailsClosedWithoutPartialNames(): void {
		foreach (['nodeLimit' => 2, 'byteLimit' => 4, 'depthLimit' => 1] as $property => $limit) {
			$boundary = $this->boundary();
			$boundary->$property = $limit;
			try {
				$this->inspect($boundary);
				self::fail('Expected bounded refusal');
			} catch (ToolFailure $error) {
				self::assertStringNotContainsString('secret', $error->getMessage());
				self::assertSame([], $this->tree->ops);
			}
		}
	}

	/** @return void Any descendant denial aborts without disclosing its path or creating effects. */
	public function testDeniedDescendantDoesNotLeakDetails(): void {
		try {
			$this->inspect($this->boundary(), static function ($node): void {
				if (str_ends_with($node->getPath(), 'secret.txt')) { throw new \RuntimeException('private secret.txt ACL'); }
			});
			self::fail('Expected denial');
		} catch (ToolFailure $error) {
			self::assertStringNotContainsString('secret', $error->getMessage());
			self::assertSame([], $this->tree->ops);
		}
	}
	/** @return void The personal root and a mount root are refused before any listing. */
	public function testPersonalAndMountRootsCannotEnterWalk(): void {
		foreach ([true, false] as $personal) {
			if (!$personal) { $this->tree->mountPath = '/alice/files/folder'; }
			try {
				$this->boundary()->assertSource($this->tree->node('/alice/files'), 'alice', $this->tree->node($personal ? '/alice/files' : '/alice/files/folder'));
				self::fail('Expected root refusal');
			} catch (ToolFailure) {
				self::assertSame([], $this->tree->listings);
			}
		}
	}

	/** @return void A backend listing error produces no partial plan or private failure text. */
	public function testListingFailureIsIntegralRefusal(): void {
		$this->tree->beforeListing = static function (): void { throw new \RuntimeException('private child mount'); };
		try {
			$this->inspect($this->boundary());
			self::fail('Expected listing refusal');
		} catch (ToolFailure $error) {
			self::assertStringNotContainsString('private child', $error->getMessage());
			self::assertSame([], $this->tree->ops);
		}
	}

	/** @return void Repeated identities never turn a malformed or cyclic listing into a complete observation. */
	public function testRepeatedIdentityIsRefused(): void {
		$this->tree->nodes['/alice/files/folder/nested/secret.txt']['id'] = $this->tree->node('/alice/files/folder')->getId();
		$this->expectException(ToolFailure::class);
		$this->inspect($this->boundary());
	}

}
