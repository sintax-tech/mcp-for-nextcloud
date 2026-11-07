<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Decisions C4 and C5 of #22 (contract §4): the Collectives tools write `.md` pages under their own
 * CollectivesMarkdownPolicy, never under the Files allowlist of #15; the `files_*` tools keep that allowlist for the
 * very same folder, and nothing in Files, the file-type policy or visibility was taught about Collectives.
 */
final class CollectivesFileTypeBoundaryTest extends TestCase {
	/** @return void with an allowlist that excludes .md, files_* is refused and collectives_edit still writes the page */
	public function testFilesKeepsTheAllowlistWhileCollectivesWritesMarkdown(): void {
		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturn(json_encode(['version' => 1, 'mode' => 'allowlist', 'extensions' => ['png'], 'allowExtensionless' => false]));
		$policy = new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class));
		try {
			(new FileTypeGuard($policy))->assertWritableName('Guide.md');
			$this->fail('the #15 allowlist let files_* write a page of the collective');
		} catch (FileTypeDenied) {
			$this->addToAssertionCount(1);
		}
		$rig = new CollectivesRig($this);
		$args = ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Guide.md'], 'content' => "Written\n"];
		$plan = $rig->module()->preview('collectives_edit', $args, 'alice');
		$rig->module()->call('collectives_edit', $args + ['confirm' => true, 'etag' => $plan['etag'], 'plan_state' => $plan['plan_state']], 'alice');
		$this->assertSame("Written\n", CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Guide.md']['content']);
	}

	/** @return void no Collectives class depends on the #15 policy; no Files, file-type or visibility class names Collectives */
	public function testTheExceptionIsBoundOnlyInTheModule(): void {
		$root = dirname(__DIR__, 4) . '/lib/';
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'Modules/Collectives')) as $file) {
			if ($file->isFile()) {
				$source = (string)file_get_contents($file->getPathname());
				$this->assertStringNotContainsString('FileTypeGuard', $source, $file->getPathname());
				$this->assertStringNotContainsString('FileWritePolicy', $source, $file->getPathname());
			}
		}
		foreach (['Modules/Files', 'Access/FileTypes', 'Access/Visibility'] as $dir) {
			foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $dir)) as $file) {
				if ($file->isFile()) {
					$this->assertStringNotContainsStringIgnoringCase('collectives', (string)file_get_contents($file->getPathname()), $file->getPathname());
				}
			}
		}
		$registration = (string)file_get_contents($root . 'Modules/Collectives/CollectivesRegistration.php');
		$this->assertStringContainsString('registerService(CollectivesMarkdownPolicy::class', $registration);
		$this->assertTrue((new ReflectionClass(\OCA\Mcp\Modules\Collectives\Editing\EditPagePlan::class))->getConstructor()->getParameters()[2]->getType()->getName()
			=== \OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy::class);
	}
}
