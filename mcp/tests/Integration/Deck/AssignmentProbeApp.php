<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCP\Server;
use RuntimeException;

/** Installs a disposable deferrable notification app only on this cell; no production source is changed. */
final class AssignmentProbeApp {
	public const ID = 'mcp_deck_probe';
	public readonly string $nonce;
	private string $directory;

	/** Copy the test app and enable it through the official app CLI before issuing fresh HTTP requests. */
	public function __construct() {
		$this->nonce = bin2hex(random_bytes(16));
		$this->directory = getenv('NEXTCLOUD_ROOT') . '/custom_apps/' . self::ID;
		if (file_exists($this->directory)) {
			throw new RuntimeException('Probe app already exists');
		}
		mkdir($this->directory . '/appinfo', 0777, true);
		mkdir($this->directory . '/lib/AppInfo', 0777, true);
		copy(__DIR__ . '/AssignmentProbe/info.xml', $this->directory . '/appinfo/info.xml');
		copy(__DIR__ . '/AssignmentProbe/Application.php', $this->directory . '/lib/AppInfo/Application.php');
		copy(__DIR__ . '/AssignmentProbe/Delivery.php', $this->directory . '/lib/Delivery.php');
		copy(__DIR__ . '/AssignmentProbe/PrepareFault.php', $this->directory . '/lib/PrepareFault.php');
		Server::get(\OCP\IConfig::class)->setAppValue(self::ID, 'nonce', $this->nonce);
		$this->occ('enable');
	}

	/** @param string $action Enable/disable this app only. */
	private function occ(string $action): void {
		$p = proc_open([PHP_BINARY, getenv('NEXTCLOUD_ROOT') . '/occ', 'app:' . $action, self::ID],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$out = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($p) !== 0) {
			throw new RuntimeException('Probe app lifecycle failed: ' . $out . $error);
		}
	}

	/** @param bool $on Whether the probe's notifier fails while Deck notifications are prepared. @return void */
	public function prepareFault(bool $on): void {
		Server::get(\OCP\IConfig::class)->setAppValue(self::ID, 'prepare_fault', $on ? '1' : '0');
	}

	/** @return list<array<string,mixed>> Test-only phase metadata; never credentials or notification content. */
	public function events(): array {
		$file = '/tmp/mcp-deck-probe-' . $this->nonce . '.jsonl';
		return is_file($file) ? array_map(static fn ($line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($file, FILE_IGNORE_NEW_LINES)) : [];
	}

	/** Remove only the enabled app and trace created by this fixture. */
	public function close(): void {
		$this->occ('disable');
		Server::get(\OCP\IConfig::class)->deleteAppValues(self::ID);
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->directory);
		@unlink('/tmp/mcp-deck-probe-' . $this->nonce . '.jsonl');
	}
}
