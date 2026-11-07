<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion\Folder;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** Per-inspection API count and refusals discriminate ancestor reuse from lost share coverage. */
final class DeleteFolderAdmissionTest extends TestCase {
	/** @return void Both branched and deep trees check each exact path/type only once, plus external ancestors once. */
	public function testApiCallsAreLinearForBranchedAndDeepTrees(): void {
		foreach ([false, true] as $deep) {
			$paths = ['/alice/files' => true, '/alice/files/outer' => true, '/alice/files/outer/source' => true];
			$prefix = '/alice/files/outer/source';
			for ($i = 0; $i < 32; $i++) {
				$folder = $deep ? ($prefix .= '/d' . $i) : '/alice/files/outer/source/d' . $i;
				$paths[$folder] = true;
				$paths[$folder . '/f.txt'] = false;
			}
			$fixture = new FolderAdmissionFixture($this, $paths);
			$result = $fixture->inspect();
			self::assertSame(65, $result['nodes']);
			$types = array_unique(array_filter((new \ReflectionClass(IShare::class))->getConstants(), static fn ($value, $key): bool =>
				str_starts_with($key, 'TYPE_') && is_int($value), ARRAY_FILTER_USE_BOTH));
			self::assertCount((65 + 2) * count($types), $fixture->shareCalls);
			self::assertCount(count($fixture->shareCalls), array_unique(array_map('json_encode', $fixture->shareCalls)));
			self::assertCount(65, $fixture->audiences);
			self::assertSame([true], array_values(array_filter(array_column($fixture->audiences, 1))));
			// A new inspection must query again; the context never becomes authorization for the next frontier.
			self::assertSame($result, $fixture->inspect());
			self::assertCount(2 * (65 + 2) * count($types), $fixture->shareCalls);
		}
	}

	/** @return void Descendant guards and outside-ancestor shares still refuse, including all-provider-only audience. */
	public function testFreshInspectionsKeepEveryAdmissionGate(): void {
		foreach (['deny', 'mount', 'received', 'hidden'] as $flag) {
			$fixture = new FolderAdmissionFixture($this, ['/alice/files' => true, '/alice/files/outer' => true,
				'/alice/files/outer/source' => true, '/alice/files/outer/source/private.txt' => false]);
			$fixture->inspect();
			$fixture->flags[$flag === 'ancestor-share' ? '/alice/files/outer' : '/alice/files/outer/source/private.txt'] = $flag === 'ancestor-share' ? 'native-share' : $flag;
			try {
				$fixture->inspect();
				self::fail('Expected fresh refusal: ' . $flag);
			} catch (ToolFailure $error) {
				self::assertStringNotContainsString('private.txt', $error->getMessage());
			}
		}
	}
	/** @return void Shared descendants/ancestors are reported without skipping later forbidden children. */
	public function testSharedImpactIsAggregatedButNeverSkipsAdmission(): void {
		foreach (['shared', 'native-share', 'custom-provider', 'ancestor-share'] as $flag) {
			$fixture = new FolderAdmissionFixture($this, ['/alice/files' => true, '/alice/files/outer' => true,
				'/alice/files/outer/source' => true, '/alice/files/outer/source/a.txt' => false, '/alice/files/outer/source/z.txt' => false]);
			$fixture->flags[$flag === 'ancestor-share' ? '/alice/files/outer' : '/alice/files/outer/source/a.txt'] = $flag === 'ancestor-share' ? 'native-share' : $flag;
			self::assertTrue($fixture->inspect()['shared']);
			$fixture->flags['/alice/files/outer/source/z.txt'] = 'deny';
			try { $fixture->inspect(); self::fail('Shared impact cannot short circuit later admission'); }
			catch (ToolFailure) { self::assertTrue(true); }
		}
	}

}
