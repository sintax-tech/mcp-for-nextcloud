<?php
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

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
 * - a script or style referenced with `Util::addScript/addStyle('mcp', X)` in the .php files of the sources
 *   (`templates lib` by default) that is not packaged as `mcp/js/X.js` or `mcp/css/X.css`.
 *
 * The archive is read with a minimal tar reader of its own instead of PharData. PharData normalizes the
 * archive: it consumes the pax extended headers without exposing them or their typeflag, so the check that
 * matters most here (xattrs in pax headers) could not be made with it, and `tar -tzf` on macOS hides the
 * AppleDouble entries. The reader walks the 512-byte headers (ustar name plus prefix, typeflag, octal or
 * base-256 size), skips each body rounded up to 512 bytes, resolves GNU long names (`L`) and the `path` record
 * of pax headers, and reports every pax header it meets. gzopen reads both gzip and plain tar.
 */
final class PackageCheck {
    /**
     * Every top-level entry the production vendor/ may hold: the two runtime dependencies (composer --no-dev
     * resolves exactly these), Composer's own autoloader directory and the autoload file it writes. Anything
     * else means a dev dependency leaked into the package. A trailing slash marks a directory.
     */
    public const ALLOWED_VENDOR = ['smalot/', 'symfony/polyfill-mbstring/', 'composer/', 'autoload.php'];

    /** A script or style the app registers: the kind (Script or Style) and the name. */
    private const REFERENCE = '/add(Script|Style)\(\s*[\'"]mcp[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/';

    /** Size of a tar block. */
    private const BLOCK = 512;

    /** Typeflags of pax extended headers: per-entry (x, and the Solaris X) and global (g). */
    private const PAX_TYPES = ['x', 'X', 'g'];

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
            $names[rtrim($member['name'], '/')] = true;
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
        array_push($found, ...self::vendorProblems($entries));
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
     * Reads the entries of a tar archive, gzip-compressed or not.
     *
     * Pax extended headers and GNU long names are not entries of their own: they are applied to the entry that
     * follows, which is then marked as carrying a pax header. A global pax header marks every entry after it.
     * Directory names lose their trailing slash.
     *
     * @param string $archive path of the archive
     * @return list<array{name: string, dir: bool, pax: bool}> the entries, in archive order
     * @throws RuntimeException when the file cannot be opened, ends inside an entry or has a bad header checksum
     */
    public static function members(string $archive): array {
        $handle = @gzopen($archive, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot open $archive");
        }
        try {
            $members = [];
            $longName = null;
            $paxPath = null;
            $pax = false;
            $globalPax = false;
            while (true) {
                $header = self::read($handle, self::BLOCK, $archive, true);
                if ($header === '' || trim($header, "\0") === '') {
                    break;
                }
                self::verifyChecksum($header, $archive);
                $type = $header[156];
                $size = self::size(substr($header, 124, 12));
                $body = in_array($type, ['L', 'K', 'x', 'X', 'g'], true)
                    ? self::read($handle, $size, $archive)
                    : null;
                if ($body === null) {
                    self::skip($handle, $size, $archive);
                }
                self::skip($handle, (self::BLOCK - $size % self::BLOCK) % self::BLOCK, $archive);
                if ($type === 'L') {
                    $longName = rtrim($body, "\0");
                    continue;
                }
                if ($type === 'K') {
                    continue;
                }
                if (in_array($type, self::PAX_TYPES, true)) {
                    $records = self::paxRecords($body);
                    if ($type === 'g') {
                        $globalPax = true;
                    } else {
                        $pax = true;
                        $paxPath = $records['path'] ?? $paxPath;
                    }
                    continue;
                }
                $name = self::cut(substr($header, 0, 100));
                $prefix = self::cut(substr($header, 345, 155));
                if (substr($header, 257, 6) === "ustar\0" && $prefix !== '') {
                    $name = "$prefix/$name";
                }
                $name = $paxPath ?? $longName ?? $name;
                // Old V7 tar marks a directory as a regular file (typeflag NUL) whose name ends in a slash.
                $dir = $type === '5' || ($type === "\0" && str_ends_with($name, '/'));
                if ($dir) {
                    $name = rtrim($name, '/');
                }
                $members[] = ['name' => $name, 'dir' => $dir, 'pax' => $pax || $globalPax];
                $longName = null;
                $paxPath = null;
                $pax = false;
            }
            return $members;
        } finally {
            gzclose($handle);
        }
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
     * Parses the records of a pax header body ("<length> <key>=<value>\n" each).
     *
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
     * Decodes a numeric header field: NUL/space-terminated octal, or GNU base-256 when the high bit is set.
     *
     * @param string $field the raw field
     * @return int the value
     * @throws RuntimeException when the field holds anything but octal digits
     */
    private static function size(string $field): int {
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            $value = ord($field[0]) & 0x7f;
            for ($i = 1; $i < strlen($field); $i++) {
                $value = $value * 256 + ord($field[$i]);
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
     * Checks the header checksum: the byte sum with the checksum field read as spaces (unsigned or signed).
     *
     * @param string $header the 512-byte header
     * @param string $archive archive path, for the message
     * @throws RuntimeException when it does not match, which means the file is not a tar archive
     */
    private static function verifyChecksum(string $header, string $archive): void {
        $blank = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $unsigned = array_sum(unpack('C*', $blank));
        $signed = array_sum(unpack('c*', $blank));
        $stored = self::size(substr($header, 148, 8));
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
     * Reads exactly $length bytes.
     *
     * @param resource $handle open gz handle
     * @param int $length bytes to read
     * @param string $archive archive path, for the message
     * @param bool $atBoundary true when the archive may legitimately end here (an empty read is returned)
     * @return string the bytes
     * @throws RuntimeException when the archive ends early
     */
    private static function read($handle, int $length, string $archive, bool $atBoundary = false): string {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = gzread($handle, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        if (strlen($data) !== $length && !($atBoundary && $data === '')) {
            throw new RuntimeException("$archive is truncated");
        }
        return $data;
    }

    /**
     * Skips $length bytes of content.
     *
     * @param resource $handle open gz handle
     * @param int $length bytes to skip
     * @param string $archive archive path, for the message
     * @throws RuntimeException when the archive ends early
     */
    private static function skip($handle, int $length, string $archive): void {
        while ($length > 0) {
            $step = min(1 << 20, $length);
            self::read($handle, $step, $archive);
            $length -= $step;
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if ($argc < 2) {
        fwrite(STDERR, "usage: php scripts/check_package.php <archive.tar.gz> [sources...]\n");
        exit(2);
    }
    try {
        $issues = PackageCheck::problems($argv[1], array_slice($argv, 2) ?: ['templates', 'lib']);
    } catch (RuntimeException $e) {
        $issues = [$e->getMessage()];
    }
    foreach ($issues as $issue) {
        fwrite(STDERR, $issue . "\n");
    }
    exit($issues === [] ? 0 : 1);
}
