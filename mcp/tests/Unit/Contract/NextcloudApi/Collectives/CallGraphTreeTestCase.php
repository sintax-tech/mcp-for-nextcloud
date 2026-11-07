<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use PHPUnit\Framework\TestCase;

/** A small source tree written for each test, read by CollectivesCallGraph. */
abstract class CallGraphTreeTestCase extends TestCase {
	protected string $lib;

	/** @return void loads the reader and makes an empty tree */
	protected function setUp(): void {
		require_once __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/CollectivesCallGraph.php';
		$this->lib = sys_get_temp_dir() . '/mcp-callgraph-' . bin2hex(random_bytes(4));
		mkdir($this->lib . '/Service', recursive: true);
	}

	/** @return void removes the tree */
	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->lib, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->lib);
	}

	/**
	 * @param array<string, string> $classes class body by declaration (`Child extends Base`), all in OCA\Collectives\Service
	 * @param string $entry `Class::method` to start from
	 * @return array{methods:array<string,string>, leaves:list<string>, unknown:list<string>} what the reader reached
	 */
	protected function reach(array $classes, string $entry): array {
		foreach ($classes as $declaration => $body) {
			file_put_contents($this->lib . '/Service/' . explode(' ', $declaration)[0] . '.php', "<?php\nnamespace OCA\\Collectives\\Service;\n"
				. "use OCP\\Files\\Folder;\nuse OCP\\EventDispatcher\\IEventDispatcher;\nclass $declaration {\n$body\n}\n");
		}
		return (new \CollectivesCallGraph($this->lib))->reach(['OCA\\Collectives\\Service\\' . $entry]);
	}

	/**
	 * @param list<string> $unknown what the reader reported
	 * @param string $file the short name of the file it is reported in
	 * @param string $form the form expected among them
	 * @return void
	 */
	protected function assertUnknown(array $unknown, string $file, string $form): void {
		$hits = array_filter($unknown, static fn (string $u): bool => str_contains($u, $form));
		$this->assertNotSame([], $hits, "$form is reported: " . implode(' | ', $unknown));
		$this->assertMatchesRegularExpression('/^Service\/' . $file . '\.php:\d+ /', reset($hits), 'with its file and line');
	}
}
