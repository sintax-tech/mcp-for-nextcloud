<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\SourceTrees;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Covers how the development scripts read a tree: from a folder on disk and from a Git revision.
 *
 * The Git test builds a throwaway repository in the system temporary folder; it is skipped where no `git` binary
 * exists (the php:8.3-cli image has none).
 */
final class SourceTreesTest extends TestCase {
	/** Temporary folder of one test. */
	private string $dir;

	/** Creates a repository-shaped folder: an app folder `mcp/` with lib/ and tests/, and a file outside it. */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/mcp-source-trees-' . bin2hex(random_bytes(6));
		mkdir($this->dir . '/mcp/lib/Sub', 0777, true);
		mkdir($this->dir . '/mcp/tests', 0777, true);
		file_put_contents($this->dir . '/mcp/lib/Sub/A.php', "<?php\nclass A {}\n");
		file_put_contents($this->dir . '/mcp/tests/ATest.php', "<?php\n");
		file_put_contents($this->dir . '/README.md', "outside the app\n");
	}

	/** Removes the folder created by setUp. */
	protected function tearDown(): void {
		// Only this test's own temporary folder, created in setUp.
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->dir);
	}

	/** A folder is read with paths relative to it, optionally limited to some top-level folders. */
	public function testAFolderIsReadRelativeToItself(): void {
		$this->assertSame(['lib/Sub/A.php' => "<?php\nclass A {}\n", 'tests/ATest.php' => "<?php\n"], SourceTrees::fromDirectory($this->dir . '/mcp'));
		$this->assertSame(['lib/Sub/A.php'], array_keys(SourceTrees::fromDirectory($this->dir . '/mcp', ['lib', 'missing'])));
	}

	/** A revision is read from Git, relative to the app folder, without the working tree's later edits. */
	public function testARevisionIsReadFromGit(): void {
		IsolatedGitEnvironment::run(function (): void {
			exec('command -v git', $output, $status);
			if ($status !== 0) {
				$this->markTestSkipped('git is not installed');
			}
			$git = 'git -C ' . escapeshellarg($this->dir) . ' -c commit.gpgsign=false -c user.name=t -c user.email=t@example.invalid ';
			exec($git . 'init -q --template= && ' . $git . 'add -A && ' . $git . 'commit -qm base', $output, $status);
			$this->assertSame(0, $status);
			file_put_contents($this->dir . '/mcp/lib/Sub/A.php', "<?php\nclass Changed {}\n");
			$files = SourceTrees::fromGit($this->dir, 'HEAD', 'mcp');
			$this->assertSame(['lib/Sub/A.php' => "<?php\nclass A {}\n", 'tests/ATest.php' => "<?php\n"], $files);
		});
	}

	/** Host Git routing/config is ignored for the test and restored for the caller afterwards. */
	public function testInheritedGitSettingsAreIsolatedAndRestored(): void {
		$previous = getenv('GIT_DIR');
		$previousConfig = getenv('GIT_CONFIG_GLOBAL');
		$config = $this->dir . '/host.gitconfig';
		file_put_contents($config, "[commit]\n gpgsign = true\n[core]\n hooksPath = /missing/hooks\n");
		putenv('GIT_DIR=' . $this->dir . '/not-the-test-repository');
		putenv('GIT_CONFIG_GLOBAL=' . $config);
		try {
			try {
				IsolatedGitEnvironment::run(function (): void {
					$this->assertFalse(getenv('GIT_DIR'));
					$this->assertSame('/dev/null', getenv('GIT_CONFIG_GLOBAL'));
					$this->assertSame('1', getenv('GIT_CONFIG_NOSYSTEM'));
					throw new \RuntimeException('test failure');
				});
				$this->fail('The test exception must propagate');
			} catch (\RuntimeException $error) {
				$this->assertSame('test failure', $error->getMessage());
			}
			$this->assertSame($this->dir . '/not-the-test-repository', getenv('GIT_DIR'));
			$this->assertSame($config, getenv('GIT_CONFIG_GLOBAL'));
		} finally {
			putenv($previous === false ? 'GIT_DIR' : 'GIT_DIR=' . $previous);
			putenv($previousConfig === false ? 'GIT_CONFIG_GLOBAL' : 'GIT_CONFIG_GLOBAL=' . $previousConfig);
		}
	}

}
