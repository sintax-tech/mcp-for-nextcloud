<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

require_once __DIR__ . '/DevelopmentEntries.php';
require_once __DIR__ . '/AssetBuilder.php';
require_once __DIR__ . '/PackagedAssets.php';
require_once __DIR__ . '/ArchiveMembers.php';

/**
 * Inspects an app archive before it ships: `php scripts/check_package.php <archive.tar.gz> [sources...]`.
 *
 * The archive is refused (every problem on stderr, exit code 1) when it has:
 * - a `._*` (AppleDouble) or `.DS_Store` entry, at any depth;
 * - a PaxHeader entry or a pax extended header (typeflag `x`, `X` or `g`), where macOS xattrs such as
 *   com.apple.provenance travel;
 * - any top-level entry other than `mcp`;
 * - a `mcp/vendor/` entry outside {@see self::ALLOWED_VENDOR}: a dev dependency such as sabre/* or
 *   symfony/console must never ship in the production package;
 * - a missing license or attribution file from REQUIRED_LEGAL_FILES;
 * - a development-only entry under `mcp/scripts/` or `mcp/tests/` ({@see DevelopmentEntries});
 * - a script or style referenced with `Util::addScript/addStyle('mcp', X)` in the .php files of the sources
 *   (`templates lib` by default) that is not packaged as `mcp/js/X.js` or `mcp/css/X.css`;
 * - an orphan `src/` script, a manifest entry that is not a file inside `src/`, or any other refusal of
 *   {@see AssetBuilder}. The required manifests are {@see AssetTargets::REQUIRED}, not the files found on disk;
 * - a file member whose name is repeated, a `mcp/src/` entry, or a bundle whose bytes are not the manifest
 *   build ({@see PackagedAssets}). The tree being packaged cannot hide a different file inside the archive.
 *
 * The archive is read with a minimal tar reader of its own instead of PharData. PharData normalizes the
 * archive: it consumes the pax extended headers without exposing them or their typeflag, so the check that
 * matters most here (xattrs in pax headers) could not be made with it, and `tar -tzf` on macOS hides the
 * AppleDouble entries. The reader walks the 512-byte headers (ustar name plus prefix, typeflag, octal or
 * base-256 size), skips each body rounded up to 512 bytes and resolves GNU long names (`L`) and the `path`
 * record of pax headers. A pax header is reported on the entry it applies to (a global one on every entry
 * after it); an archive that ends right after a pax header, a GNU long name or a GNU long link, with no entry
 * for it to apply to, is not readable and fails. {@see ArchiveStream} reads gzip or plain tar and refuses a
 * truncated or corrupt gzip stream.
 *
 * Any problem reading the archive is a RuntimeException, so the command line always reports it on stderr
 * with exit code 1.
 */
final class PackageCheck {
	/**
	 * Every top-level entry the production vendor/ may hold: the two runtime dependencies (composer --no-dev
	 * resolves exactly these), Composer's own autoloader directory and the autoload file it writes. Anything
	 * else means a dev dependency leaked into the package. A trailing slash marks a directory.
	 */
	public const ALLOWED_VENDOR = ['smalot/', 'symfony/polyfill-mbstring/', 'composer/', 'autoload.php'];

	/** License and attribution documents required in every production archive. */
	public const REQUIRED_LEGAL_FILES = [
		'mcp/LICENSE',
		'mcp/NOTICE',
		'mcp/THIRD-PARTY-NOTICES.md',
		'mcp/LICENSES/AGPL-3.0-or-later.txt',
		'mcp/LICENSES/LGPL-3.0-or-later.txt',
		'mcp/LICENSES/GPL-3.0-or-later.txt',
		'mcp/LICENSES/MIT.txt',
		'mcp/LICENSES/Unicode-3.0.txt',
		'mcp/LICENSES/BSD-3-Clause.txt',
		'mcp/vendor/smalot/pdfparser/LICENSE.txt',
		'mcp/vendor/symfony/polyfill-mbstring/LICENSE',
		'mcp/vendor/composer/LICENSE',
	];

	/** A script or style the app registers: the kind (Script or Style) and the name. */
	private const REFERENCE = '/add(Script|Style)\(\s*[\'"]mcp[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/';

	/**
	 * Runs every check on an archive.
	 *
	 * @param string $archive path of the .tar.gz (or plain .tar)
	 * @param list<string> $sources folders whose .php files are searched for addScript/addStyle references,
	 *                              relative to the working directory; one that is not a folder is skipped
	 * @return list<string> the problems found, in the order they are reported; empty when the archive passes
	 * @throws RuntimeException when the archive cannot be read or is not a tar archive
	 */
	public static function problems(string $archive, array $sources): array {
		$found = [];
		$members = self::members($archive);
		$names = [];
		$entries = [];
		foreach ($members as $member) {
			$names[rtrim($member['name'], '/')] = !$member['dir'];
			$entries[] = [$member['name'], $member['dir']];
			$parts = explode('/', rtrim($member['name'], '/'));
			$base = end($parts);
			if (str_starts_with($base, '._') || $base === '.DS_Store') {
				$found[] = "forbidden macOS entry: {$member['name']}";
			}
			if (str_contains($member['name'], 'PaxHeader') || $member['pax']) {
				$found[] = "pax header on: {$member['name']}";
			}
		}
		$tops = array_values(array_unique(array_map(static fn (array $m): string => explode('/', $m['name'])[0], $members)));
		sort($tops, SORT_STRING);
		if ($tops !== ['mcp']) {
			$found[] = 'top-level entries must be only mcp/, found: [' . implode(', ', array_map(static fn (string $top): string => "'$top'", $tops)) . ']';
		}
		foreach (self::REQUIRED_LEGAL_FILES as $required) {
			if (!($names[$required] ?? false)) {
				$found[] = "missing legal file: $required";
			}
		}
		array_push($found, ...self::vendorProblems($entries));
		array_push($found, ...DevelopmentEntries::problems($entries));
		$root = getcwd() ?: '';
		array_push($found, ...AssetBuilder::packageProblems($root));
		array_push($found, ...PackagedAssets::problems($root, $members));
		foreach ($sources as $source) {
			foreach (self::phpFiles($source) as $path) {
				preg_match_all(self::REFERENCE, (string)file_get_contents($path), $matches, PREG_SET_ORDER);
				foreach ($matches as [, $kind, $name]) {
					$expected = $kind === 'Script' ? "mcp/js/$name.js" : "mcp/css/$name.css";
					if (!isset($names[$expected])) {
						$found[] = "missing $expected (add$kind in $path)";
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Rejects any mcp/vendor/ entry outside {@see self::ALLOWED_VENDOR}.
	 *
	 * A directory entry carries no code and is skipped (macOS tar writes directory names without a trailing
	 * slash, so whether an entry is a directory comes from its typeflag, not its name). A file passes when it
	 * equals an allowed leaf or sits under an allowed directory, matched on a path-segment boundary, so
	 * symfony/polyfill-mbstring/ passes while symfony/console/ does not.
	 *
	 * @param list<array{0: string, 1: bool}> $entries name and is-directory of each archive entry
	 * @return list<string> one problem per forbidden entry, sorted by name
	 */
	public static function vendorProblems(array $entries): array {
		$directories = array_values(array_filter(self::ALLOWED_VENDOR, static fn (string $d): bool => str_ends_with($d, '/')));
		sort($entries);
		$found = [];
		foreach ($entries as [$name, $isDir]) {
			if (!str_starts_with($name, 'mcp/vendor/') || $isDir) {
				continue;
			}
			$rest = substr($name, strlen('mcp/vendor/'));
			if (trim($rest, '/') === '') {
				continue;
			}
			$allowed = in_array($rest, self::ALLOWED_VENDOR, true);
			foreach ($directories as $directory) {
				$allowed = $allowed || $rest === rtrim($directory, '/') || str_starts_with($rest, $directory);
			}
			if (!$allowed) {
				$found[] = "forbidden vendor entry: $name";
			}
		}
		return $found;
	}

	/**
	 * The entries of a tar archive. The walk and the bundle bodies are {@see ArchiveMembers::read()}.
	 *
	 * @param string $archive path of the archive
	 * @return list<array{name: string, dir: bool, pax: bool, link: bool, unsafe: bool, body?: string}> entries, in archive order
	 * @throws RuntimeException when the archive cannot be read or is not a tar archive
	 */
	public static function members(string $archive): array {
		return ArchiveMembers::read($archive);
	}

	/**
	 * The .php files under a source folder, sorted so the report is stable.
	 *
	 * @param string $source folder to search; anything that is not a folder yields nothing
	 * @return list<string> paths, each starting with $source
	 */
	private static function phpFiles(string $source): array {
		if (!is_dir($source)) {
			return [];
		}
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
				$files[] = $file->getPathname();
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Decodes a numeric header field: NUL/space-terminated octal, or GNU base-256.
	 *
	 * A base-256 field starts with the byte 0x80 and holds the value big-endian in the bytes after it, as Python's
	 * tarfile reads it; 0xff (a negative value) or any other byte with the high bit set is refused, as is a value
	 * that does not fit in a PHP int.
	 *
	 * @param string $field the raw field
	 * @return int the value, never negative
	 * @throws RuntimeException when the field holds anything but octal digits, or is a negative or too large
	 *                          base-256 value
	 */
	public static function size(string $field): int {
		if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
			if (ord($field[0]) !== 0x80) {
				throw new RuntimeException('not a valid tar archive (negative or malformed base-256 field in a header)');
			}
			$value = 0;
			for ($i = 1; $i < strlen($field); $i++) {
				$byte = ord($field[$i]);
				if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
					throw new RuntimeException('not a valid tar archive (base-256 field in a header is too large)');
				}
				$value = $value * 256 + $byte;
			}
			return $value;
		}
		$digits = trim(self::cut($field), " \0");
		if ($digits !== '' && preg_match('/^[0-7]+$/', $digits) !== 1) {
			throw new RuntimeException('not a valid tar archive (bad numeric field in a header)');
		}
		return $digits === '' ? 0 : (int)octdec($digits);
	}

	/**
	 * @param string $field a NUL-terminated header field
	 * @return string the field up to its first NUL
	 */
	private static function cut(string $field): string {
		$end = strpos($field, "\0");
		return $end === false ? $field : substr($field, 0, $end);
	}
}

/**
 * The uncompressed bytes of an archive file: gzip (detected by its magic bytes, one or more members) or plain.
 *
 * gzread cannot be used here: it returns the data of a truncated gzip file as if the file had ended normally.
 * The stream is inflated with inflate_add instead, whose status tells a finished member (its CRC-32 and size
 * trailer checked by zlib) from one cut short. The file is fed in small chunks so the inflated buffer stays small
 * whatever the compression ratio. NUL padding after a finished member is ignored, as Python's gzip does.
 */
final class ArchiveStream {
	/** Compressed bytes fed to zlib at a time; deflate expands at most about 1032:1, so about 1 MiB out. */
	private const CHUNK = 1024;

	/** @var resource the open file */
	private $file;

	/** The inflate context of the current gzip member; null for a plain archive. */
	private ?\InflateContext $inflate = null;

	/** Whether the current gzip member received any input yet. */
	private bool $started = false;

	/** Whether at least one gzip member has ended (NUL padding may follow). */
	private bool $ended = false;

	/** Uncompressed bytes not yet returned, from $offset on. */
	private string $buffer = '';

	private int $offset = 0;

	/** File bytes read ahead (the magic bytes) and not yet fed. */
	private string $input = '';

	/**
	 * @param string $path the archive file
	 * @throws RuntimeException when it cannot be opened
	 */
	public function __construct(private readonly string $path) {
		$file = @fopen($path, 'rb');
		if ($file === false) {
			throw new RuntimeException("cannot open $path");
		}
		$this->file = $file;
		$this->input = (string)fread($file, 2);
		if ($this->input === "\x1f\x8b") {
			$this->inflate = self::context($path);
		}
	}

	/**
	 * Reads up to $length bytes; fewer only at the end of the data.
	 *
	 * @param int $length bytes wanted
	 * @return string the bytes
	 * @throws RuntimeException when the gzip stream is corrupt or truncated
	 */
	public function read(int $length): string {
		while (strlen($this->buffer) - $this->offset < $length && $this->fill()) {
		}
		$data = substr($this->buffer, $this->offset, $length);
		$this->offset += strlen($data);
		if ($this->offset > 65536) {
			$this->buffer = substr($this->buffer, $this->offset);
			$this->offset = 0;
		}
		return $data;
	}

	/**
	 * Reads the rest of the stream through, discarding it, so a truncated or corrupt gzip file fails.
	 *
	 * @throws RuntimeException when the gzip stream is corrupt or truncated
	 */
	public function finish(): void {
		do {
			$this->buffer = '';
			$this->offset = 0;
		} while ($this->fill());
	}

	public function close(): void {
		fclose($this->file);
	}

	/**
	 * Appends the next uncompressed bytes to the buffer.
	 *
	 * @return bool false at the end of the file
	 * @throws RuntimeException when the gzip stream is corrupt, or the file ends inside a gzip member
	 */
	private function fill(): bool {
		$input = $this->input . (string)fread($this->file, self::CHUNK);
		$this->input = '';
		if ($input === '') {
			if ($this->inflate !== null && $this->started) {
				throw new RuntimeException("{$this->path} is truncated (the gzip stream ends early)");
			}
			return false;
		}
		if ($this->inflate === null) {
			$this->buffer .= $input;
			return true;
		}
		while ($input !== '') {
			if ($this->ended && !$this->started) {
				$input = ltrim($input, "\0");
				if ($input === '') {
					break;
				}
			}
			$before = inflate_get_read_len($this->inflate);
			$out = @inflate_add($this->inflate, $input);
			if ($out === false) {
				throw new RuntimeException("{$this->path} is not a valid gzip file (corrupt data or trailer)");
			}
			$this->started = true;
			$this->buffer .= $out;
			$used = inflate_get_read_len($this->inflate) - $before;
			$input = substr($input, $used);
			if (inflate_get_status($this->inflate) === ZLIB_STREAM_END) {
				$this->inflate = self::context($this->path);
				$this->started = false;
				$this->ended = true;
			} elseif ($used === 0 && $out === '') {
				throw new RuntimeException("{$this->path} is not a valid gzip file");
			}
		}
		return true;
	}

	/** A fresh gzip inflate context. */
	private static function context(string $path): \InflateContext {
		$context = inflate_init(ZLIB_ENCODING_GZIP);
		if ($context === false) {
			throw new RuntimeException("cannot inflate $path");
		}
		return $context;
	}
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	if ($argc < 2) {
		fwrite(STDERR, "usage: php scripts/check_package.php <archive.tar.gz> [sources...]\n");
		exit(2);
	}
	try {
		$issues = PackageCheck::problems($argv[1], array_slice($argv, 2) ?: ['templates', 'lib']);
	} catch (\Throwable $e) {
		// A RuntimeException is the expected failure; anything else is still reported, never a fatal error.
		$issues = [$e->getMessage()];
	}
	foreach ($issues as $issue) {
		fwrite(STDERR, $issue . "\n");
	}
	exit($issues === [] ? 0 : 1);
}
