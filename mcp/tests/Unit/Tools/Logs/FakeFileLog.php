<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCP\Log\IFileBased;
use OCP\Log\IWriter;

/** Disposable current-file fixture with snapshot growth, observed opens and an explicit eligibility level. */
final class FakeFileLog implements IWriter, IFileBased {
	/** @var list<mixed> File opens through the official path accessor. */
	public array $calls = [];
	/** Lines appended to the fixture after each file snapshot, as a busy server keeps writing. */
	public int $growth = 0;

	/** @param list<string> $lines the file, oldest line first */
	public function __construct(public array $lines, public int $minLevel = 0) {}

	public function write(string $app, $message, int $level) {
	}

	private ?string $path = null;

	/** @return string Disposable file containing this call's fixed snapshot, before simulated growth. */
	public function getLogFilePath(): string {
		$this->calls[] = 'open';
		if ($this->path === null) {
			$path = tempnam(sys_get_temp_dir(), 'mcp-it-log-');
			if ($path === false) { throw new \RuntimeException('Cannot allocate the synthetic log'); }
			$this->path = $path;
		}
		if (file_put_contents($this->path, implode("\n", $this->lines) . "\n") === false) {
			throw new \RuntimeException('Cannot write the synthetic log');
		}
		if ($this->growth > 0) {
			$this->lines = [...$this->lines, ...self::lines($this->growth, static fn (int $i): array => ['reqId' => 'new' . bin2hex(random_bytes(4))])];
		}
		return $this->path;
	}

	/** Remove only the file this fixture created. */
	public function __destruct() {
		if ($this->path !== null) {
			unlink($this->path);
		}
	}


	/**
	 * @param int $limit Unused interface parameter.
	 * @param int $offset Unused interface parameter.
	 * @return array Never returned: parity tests use OfficialFileLog instead.
	 * @throws \LogicException If a test accidentally uses the obsolete native-read path.
	 */
	public function getEntries(int $limit = 50, int $offset = 0): array {
		throw new \LogicException('Use OfficialFileLog for native getEntries comparisons');
	}

	/**
	 * @param int $count entries to build
	 * @param callable(int):array<string, mixed> $fields fields of entry $i, oldest first
	 * @return list<string> JSON lines
	 */
	public static function lines(int $count, callable $fields): array {
		$lines = [];
		for ($i = 0; $i < $count; $i++) {
			$lines[] = json_encode($fields($i) + [
				'reqId' => 'req' . $i, 'level' => 2, 'time' => gmdate(DATE_ATOM, 1791400000 + $i * 60), 'remoteAddr' => '203.0.113.' . ($i % 250),
				'user' => 'alice', 'app' => 'core', 'method' => 'GET', 'url' => '/index.php/apps/files/', 'message' => 'entry ' . $i,
				'userAgent' => 'Mozilla/5.0', 'version' => '31.0.9.1',
			], JSON_UNESCAPED_SLASHES);
		}
		return $lines;
	}
}
