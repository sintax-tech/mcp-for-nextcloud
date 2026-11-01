<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

use RuntimeException;

require_once __DIR__ . '/AssetTargets.php';
require_once __DIR__ . '/DevelopmentEntries.php';

/**
 * Reads a tar archive and the bytes of the two frontend bundles.
 *
 * `check_package.php` only calls {@see self::read()}. The stored name is the spelling in the archive, so
 * {@see DevelopmentEntries} can quote it. The canonical path ({@see self::canonical()}) is what `tar` installs:
 * `.` and empty segments (`//`) are dropped, and `..`, a leading `/` or a backslash is unsafe. A bundle body is
 * kept only for that canonical path. A symlink or hard link is marked and its body is not file bytes.
 */
final class ArchiveMembers {
	/** Size of a tar block. */
	private const BLOCK = 512;

	/** Typeflags of pax extended headers: per-entry (x, and the Solaris X) and global (g). */
	private const PAX_TYPES = ['x', 'X', 'g'];

	/**
	 * Largest metadata body kept in memory: a GNU long name (`L`) or the records of a pax header.
	 */
	private const METADATA_LIMIT = 65536;

	/** What each extension typeflag is, for the message when the archive ends right after one. */
	private const EXTENSIONS = [
		'L' => 'GNU long name',
		'K' => 'GNU long link',
		'x' => 'pax extended header',
		'X' => 'pax extended header',
		'g' => 'pax global header',
	];

	/**
	 * The entries of a gzip or plain tar, with a bundle body when the canonical path is one of the two bundles.
	 *
	 * @param string $archive path of the archive
	 * @return list<array{name: string, dir: bool, pax: bool, link: bool, unsafe: bool, body?: string}> entries, in archive order
	 * @throws RuntimeException when the archive cannot be read or is not a tar archive
	 */
	public static function read(string $archive): array {
		$capture = [];
		foreach (AssetTargets::MANIFESTS as $bundle) {
			$capture[] = 'mcp/' . $bundle;
		}
		$stream = new ArchiveStream($archive);
		try {
			$members = [];
			$longName = null;
			$paxPath = null;
			$pax = false;
			$globalPax = false;
			$pending = null;
			while (true) {
				$header = self::readBlock($stream, self::BLOCK, $archive, true);
				if ($header === '' || trim($header, "\0") === '') {
					if ($pending !== null) {
						throw new RuntimeException("$archive ends after a " . self::EXTENSIONS[$pending] . " (typeflag $pending) with no entry for it");
					}
					break;
				}
				self::verifyChecksum($header, $archive);
				$type = $header[156];
				$size = PackageCheck::size(substr($header, 124, 12));
				$padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
				if ($type === 'L') {
					if ($size > self::METADATA_LIMIT) {
						throw new RuntimeException("$archive has a GNU long name of $size bytes, over the limit of " . self::METADATA_LIMIT);
					}
					$longName = rtrim(self::readBlock($stream, $size, $archive), "\0");
					self::skip($stream, $padding, $archive);
					$pending = $type;
					continue;
				}
				if (in_array($type, self::PAX_TYPES, true)) {
					$records = [];
					if ($size <= self::METADATA_LIMIT) {
						$records = self::paxRecords(self::readBlock($stream, $size, $archive));
					} else {
						self::skip($stream, $size, $archive);
					}
					self::skip($stream, $padding, $archive);
					if ($type === 'g') {
						$globalPax = true;
					} else {
						$pax = true;
						$paxPath = $records['path'] ?? $paxPath;
					}
					$pending = $type;
					continue;
				}
				$name = self::cut(substr($header, 0, 100));
				$prefix = self::cut(substr($header, 345, 155));
				if (substr($header, 257, 6) === "ustar\0" && $prefix !== '') {
					$name = "$prefix/$name";
				}
				$name = $paxPath ?? $longName ?? $name;
				$dir = $type === '5' || ($type === "\0" && str_ends_with($name, '/'));
				if ($dir) {
					$name = rtrim($name, '/');
				}
				if ($type === 'K') {
					self::skip($stream, $size + $padding, $archive);
					$pending = $type;
					continue;
				}
				$link = $type === '1' || $type === '2';
				$canonical = self::canonical($name);
				$unsafe = $canonical === null;
				if ($link) {
					$target = self::cut(substr($header, 157, 100));
					if ($target === '' || self::canonical($target) === null) {
						$unsafe = true;
					}
				}
				$body = null;
				if (!$dir && !$link && !$unsafe && in_array($canonical, $capture, true)) {
					$body = self::readBlock($stream, $size, $archive);
					self::skip($stream, $padding, $archive);
				} else {
					self::skip($stream, $size + $padding, $archive);
				}
				$member = [
					'name' => $name,
					'dir' => $dir,
					'pax' => $pax || $globalPax,
					'link' => $link,
					'unsafe' => $unsafe,
				];
				if ($body !== null) {
					$member['body'] = $body;
				}
				$members[] = $member;
				$longName = null;
				$paxPath = null;
				$pax = false;
				$pending = null;
			}
			$stream->finish();
			return $members;
		} finally {
			$stream->close();
		}
	}

	/**
	 * The path `tar` installs, or null when the name must be refused.
	 *
	 * `.` and empty segments are dropped by {@see DevelopmentEntries::canonical()}, so `mcp/js/./mcp-admin.js`
	 * and `mcp//js/mcp-admin.js` are both `mcp/js/mcp-admin.js`. `..`, a leading slash and a backslash are not
	 * resolved: they are unsafe.
	 *
	 * @param string $name member name as stored, or a link target
	 * @return string|null the canonical relative path, or null when it is unsafe
	 */
	public static function canonical(string $name): ?string {
		if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
			return null;
		}
		foreach (explode('/', $name) as $part) {
			if ($part === '..') {
				return null;
			}
		}
		$path = DevelopmentEntries::canonical($name);
		return $path === '' ? null : $path;
	}

	/**
	 * @param string $body the header body
	 * @return array<string, string> value per key; a malformed record ends the parsing
	 */
	private static function paxRecords(string $body): array {
		$records = [];
		$offset = 0;
		while ($offset < strlen($body) && preg_match('/\G(\d+) /', $body, $match, 0, $offset)) {
			$length = (int)$match[1];
			if ($length <= strlen($match[0])) {
				break;
			}
			$record = substr($body, $offset + strlen($match[0]), $length - strlen($match[0]) - 1);
			$equals = strpos($record, '=');
			if ($equals !== false) {
				$records[substr($record, 0, $equals)] = substr($record, $equals + 1);
			}
			$offset += $length;
		}
		return $records;
	}

	/**
	 * @param string $header the 512-byte header
	 * @param string $archive archive path, for the message
	 * @throws RuntimeException when it does not match
	 */
	private static function verifyChecksum(string $header, string $archive): void {
		$blank = substr_replace($header, str_repeat(' ', 8), 148, 8);
		$unsigned = array_sum(unpack('C*', $blank));
		$signed = array_sum(unpack('c*', $blank));
		$stored = PackageCheck::size(substr($header, 148, 8));
		if ($stored !== $unsigned && $stored !== $signed) {
			throw new RuntimeException("$archive is not a valid tar archive (bad header checksum)");
		}
	}

	/**
	 * @param string $field a NUL-terminated header field
	 * @return string the field up to its first NUL
	 */
	private static function cut(string $field): string {
		$end = strpos($field, "\0");
		return $end === false ? $field : substr($field, 0, $end);
	}

	/**
	 * @param ArchiveStream $stream the open archive
	 * @param int $length bytes to read
	 * @param string $archive archive path, for the message
	 * @param bool $atBoundary true when the archive may legitimately end here
	 * @return string the bytes
	 * @throws RuntimeException when the archive ends early
	 */
	private static function readBlock(ArchiveStream $stream, int $length, string $archive, bool $atBoundary = false): string {
		$data = $stream->read($length);
		if (strlen($data) !== $length && !($atBoundary && $data === '')) {
			throw new RuntimeException("$archive is truncated");
		}
		return $data;
	}

	/**
	 * @param ArchiveStream $stream the open archive
	 * @param int $length bytes to skip
	 * @param string $archive archive path, for the message
	 * @throws RuntimeException when the archive ends early
	 */
	private static function skip(ArchiveStream $stream, int $length, string $archive): void {
		while ($length > 0) {
			$step = min(1 << 20, $length);
			self::readBlock($stream, $step, $archive);
			$length -= $step;
		}
	}
}
