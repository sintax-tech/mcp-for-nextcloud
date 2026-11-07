<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Runs the same synthetic source in PHP and in the graph against a counting write double. */
abstract class RuntimeTreeTestCase extends CallGraphTreeTestCase {
	/**
	 * Settings of the reproduction process, independent of the php.ini of the machine: no ini file is read, and each
	 * diagnostic is written once, as plain text, to stderr. With the ini of setup-php (log_errors and display_errors
	 * both on) a warning reached stderr twice, once with the "PHP " prefix of the log.
	 */
	private const INI = ['-n', '-d', 'error_reporting=E_ALL', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0'];

	/**
	 * @param array<string, string> $classes ordered declarations, with parent classes before children
	 * @param int $expected expected writes in the PHP reproduction
	 * @param bool $strict whether the exact sources executed and analyzed declare strict_types
	 * @param string|null $warning Exact runtime warning, empty for none; null leaves the diagnostics unchecked.
	 * @return array{methods:array<string,string>, leaves:list<string>, unknown:list<string>} the graph of the same source
	 */
	protected function runtimeAndUnknown(array $classes, int $expected = 1, bool $strict = false, ?string $warning = null): array {
		@unlink($this->lib . '/runtime.php');
		$r = $this->reach($classes, 'Entry::read', $strict);
		$runner = '<?php namespace OCP\\Files { class Folder { public int $writes = 0;
			public function newFile(string $name): void { $this->writes++; } } }
			namespace { ';
		foreach ($classes as $declaration => $body) {
			$runner .= 'require ' . var_export($this->lib . '/Service/' . explode(' ', $declaration)[0] . '.php', true) . ';';
		}
		$runner .= '$f = new \\OCP\\Files\\Folder(); (new \\OCA\\Collectives\\Service\\Entry())->read($f); echo $f->writes; }';
		file_put_contents($this->lib . '/runtime.php', $runner);
		$process = proc_open([PHP_BINARY, ...self::INI, $this->lib . '/runtime.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		unlink($this->lib . '/runtime.php');
		$this->assertSame(0, $exitCode, $errors);
		if ($warning === '') {
			$this->assertSame('', $errors, 'The runtime reproduction must not emit diagnostics');
		} elseif ($warning !== null) {
			$this->assertMatchesRegularExpression('/\\AWarning: ' . preg_quote($warning, '/') . ' in [^\\n]+ on line [0-9]+\\s*\\z/', $errors);
		}
		$this->assertSame((string)$expected, $output, 'the exact runtime reproduction has the expected write count: ' . ($classes['Entry'] ?? ''));
		$this->assertTrue($expected === 0 || $r['unknown'] !== [] || in_array('OCP\\Files\\Folder::newFile', $r['leaves'], true),
			'a runtime write is followed or explicitly unknown, never accepted as a resolved read');
		return $r;
	}
}
