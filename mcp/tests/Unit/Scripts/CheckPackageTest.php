<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use OCA\Mcp\Scripts\PackageCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/check_package.php';

/**
 * Covers scripts/check_package.php, the gate scripts/package.sh runs on every app archive.
 *
 * The archives are assembled byte by byte (ustar headers, GNU long names, pax headers) in a temporary folder, so
 * AppleDouble entries and pax headers can be produced exactly as macOS tar writes them, without depending on the
 * tar of the machine running the tests.
 */
final class CheckPackageTest extends TestCase {
    /** The production script. */
    private const SCRIPT = __DIR__ . '/../../../scripts/check_package.php';

    /** Temporary folder holding the archives and the sources of one test. */
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/mcp-check-package-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/templates', 0777, true);
        file_put_contents($this->dir . '/templates/admin.php', "<?php\n\\OCP\\Util::addScript('mcp', 'admin');\n\\OCP\\Util::addStyle(\"mcp\", \"admin\");\n");
    }

    protected function tearDown(): void {
        // Only this test's own temporary folder, created in setUp.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** A production-shaped package passes: one mcp/ root, allowed vendor only, every referenced asset present. */
    public function testACleanPackagePasses(): void {
        $this->assertSame([], $this->check($this->clean()));
    }

    /** The command line exits 0 and prints nothing for a clean package, and exits 1 with the problems on stderr otherwise. */
    public function testTheCommandLineReportsOnStderrWithTheExitCode(): void {
        [$code, $stdout, $stderr] = $this->runScript([$this->archive($this->clean()), 'templates']);
        $this->assertSame([0, '', ''], [$code, $stdout, $stderr]);

        [$code, $stdout, $stderr] = $this->runScript([$this->archive([...$this->clean(), self::file('mcp/._info.xml')]), 'templates']);
        $this->assertSame(1, $code);
        $this->assertSame('', $stdout);
        $this->assertSame("forbidden macOS entry: mcp/._info.xml\n", $stderr);
    }

    /** Without sources the command line searches templates and lib, relative to the working directory. */
    public function testTheCommandLineDefaultsToTemplatesAndLib(): void {
        mkdir($this->dir . '/lib');
        file_put_contents($this->dir . '/lib/Page.php', "<?php Util::addScript('mcp', 'page');");
        [$code, , $stderr] = $this->runScript([$this->archive($this->clean())]);
        $this->assertSame(1, $code);
        $this->assertSame("missing mcp/js/page.js (addScript in lib/Page.php)\n", $stderr);
    }

    /** AppleDouble and .DS_Store entries are refused at any depth, a directory named ._x included. */
    public function testMacOsEntriesAreRefusedAtAnyDepth(): void {
        $entries = [...$this->clean(), self::file('mcp/._x'), self::file('mcp/lib/deep/.DS_Store'), self::dir('mcp/._folder/')];
        $this->assertSame([
            'forbidden macOS entry: mcp/._x',
            'forbidden macOS entry: mcp/lib/deep/.DS_Store',
            'forbidden macOS entry: mcp/._folder',
        ], $this->check($entries));
    }

    /** A pax extended header (where macOS puts com.apple.* xattrs) is reported on the entry it belongs to. */
    public function testAPaxExtendedHeaderIsRefused(): void {
        $entries = [...$this->clean(), self::pax('x', ['SCHILY.xattr.com.apple.provenance' => "\x01\x02"]), self::file('mcp/lib/A.php')];
        $this->assertSame(['pax header on: mcp/lib/A.php'], $this->check($entries));
    }

    /** A global pax header marks every entry that follows it. */
    public function testAGlobalPaxHeaderMarksEveryFollowingEntry(): void {
        $entries = [self::pax('g', ['comment' => 'x']), ...$this->clean()];
        $problems = $this->check($entries);
        $this->assertCount(count($this->clean()), $problems);
        $this->assertSame('pax header on: mcp', $problems[0]);
    }

    /** The path record of a pax header renames the entry, as tar does, and a PaxHeader entry name is refused. */
    public function testThePaxPathIsAppliedAndPaxHeaderNamesAreRefused(): void {
        $entries = [...$this->clean(), self::pax('x', ['path' => 'mcp/._renamed']), self::file('mcp/short'), self::file('mcp/PaxHeader/info.xml')];
        $this->assertSame([
            'forbidden macOS entry: mcp/._renamed',
            'pax header on: mcp/._renamed',
            'pax header on: mcp/PaxHeader/info.xml',
        ], $this->check($entries));
    }

    /** GNU long names and the ustar prefix are resolved before the checks. */
    public function testLongNamesAreResolved(): void {
        $long = 'mcp/lib/' . str_repeat('a', 120) . '/._long';
        $entries = [...$this->clean(), self::longName($long), self::file('mcp/truncated'), self::file('._prefixed', prefix: 'mcp/lib')];
        $this->assertSame(["forbidden macOS entry: $long", 'forbidden macOS entry: mcp/lib/._prefixed'], $this->check($entries));
    }

    /** Anything at the top level besides mcp is refused, listed like the Python original listed it. */
    public function testOnlyMcpIsAllowedAtTheTopLevel(): void {
        $entries = [...$this->clean(), self::file('README.md'), self::dir('build/')];
        $this->assertSame(["top-level entries must be only mcp/, found: ['README.md', 'build', 'mcp']"], $this->check($entries));
        $this->assertContains('top-level entries must be only mcp/, found: []', $this->check([]));
    }

    /** Dev dependencies such as sabre/* or symfony/console are refused; their directory entries are not reported. */
    public function testForbiddenVendorPackagesAreRefused(): void {
        $entries = [
            ...$this->clean(),
            self::dir('mcp/vendor/sabre'),
            self::file('mcp/vendor/sabre/dav/lib/Server.php'),
            self::file('mcp/vendor/symfony/console/Application.php'),
            self::file('mcp/vendor/symfony/polyfill-mbstringx/bootstrap.php'),
            self::file('mcp/vendor/bin/phpunit'),
        ];
        $this->assertSame([
            'forbidden vendor entry: mcp/vendor/bin/phpunit',
            'forbidden vendor entry: mcp/vendor/sabre/dav/lib/Server.php',
            'forbidden vendor entry: mcp/vendor/symfony/console/Application.php',
            'forbidden vendor entry: mcp/vendor/symfony/polyfill-mbstringx/bootstrap.php',
        ], $this->check($entries));
    }

    /** The allowed vendor paths pass, including directory entries macOS tar writes without a trailing slash. */
    public function testAllowedVendorPathsPass(): void {
        $this->assertSame([], PackageCheck::vendorProblems([
            ['mcp/vendor', true],
            ['mcp/vendor/', true],
            ['mcp/vendor/symfony', true],
            ['mcp/vendor/autoload.php', false],
            ['mcp/vendor/composer', false],
            ['mcp/vendor/composer/installed.json', false],
            ['mcp/vendor/smalot/pdfparser/src/Parser.php', false],
            ['mcp/vendor/symfony/polyfill-mbstring/Mbstring.php', false],
        ]));
    }

    /** A script or style referenced with Util::addScript/addStyle must be packaged under js/ or css/. */
    public function testAReferencedAssetMissingFromThePackageIsRefused(): void {
        $entries = array_values(array_filter($this->clean(), static fn (string $entry): bool => !str_contains($entry, 'mcp/css/admin.css')));
        $this->assertSame(['missing mcp/css/admin.css (addStyle in templates/admin.php)'], $this->check($entries));
    }

    /** A file that is not a tar archive is an error, not a pass. */
    public function testAFileThatIsNotATarArchiveIsAnError(): void {
        file_put_contents($this->dir . '/broken.tar.gz', gzencode(str_repeat('x', 1024)));
        $this->expectException(RuntimeException::class);
        PackageCheck::members($this->dir . '/broken.tar.gz');
    }

    /**
     * @return array<string, array{0: string, 1: bool}> typeflag of the extension header and whether the end-of-archive
     *                                                 zero blocks follow it (otherwise the data just ends)
     */
    public static function terminalExtensions(): array {
        $cases = [];
        foreach (['x', 'X', 'g', 'L', 'K'] as $type) {
            $cases["$type, zero blocks"] = [$type, true];
            $cases["$type, end of data"] = [$type, false];
        }
        return $cases;
    }

    /**
     * A pax header, GNU long name or long link with no entry after it is refused, never silently dropped: the
     * xattrs of a terminal pax header would otherwise ship unreported.
     */
    #[DataProvider('terminalExtensions')]
    public function testAnExtensionHeaderWithNoEntryAfterItIsAnError(string $type, bool $zeroBlocks): void {
        $extension = match ($type) {
            'L' => self::longName('mcp/._x'),
            'K' => self::header('././@LongLink', 'K', 8) . self::pad("target\0\0"),
            default => self::pax($type, ['SCHILY.xattr.com.apple.provenance' => "\x01\x02"]),
        };
        $archive = $this->archive([...$this->clean(), $extension], end: $zeroBlocks);
        [$code, $stdout, $stderr] = $this->runScript([$archive, 'templates']);
        $this->assertSame([1, ''], [$code, $stdout]);
        $this->assertStringContainsString("(typeflag $type) with no entry for it", $stderr);
    }

    /** A gzip file cut before its CRC-32/size trailer is truncated, not a normal end of the archive. */
    public function testAGzipStreamWithoutItsTrailerIsAnError(): void {
        $archive = $this->archive($this->clean());
        file_put_contents($archive, substr((string)file_get_contents($archive), 0, -8));
        [$code, , $stderr] = $this->runScript([$archive, 'templates']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is truncated (the gzip stream ends early)', $stderr);
    }

    /** A gzip trailer whose CRC-32 does not match the data is refused. */
    public function testAGzipStreamWithABadChecksumIsAnError(): void {
        $archive = $this->archive($this->clean());
        $bytes = (string)file_get_contents($archive);
        $bytes[strlen($bytes) - 8] = chr(ord($bytes[strlen($bytes) - 8]) ^ 1);
        file_put_contents($archive, $bytes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a valid gzip file');
        PackageCheck::members($archive);
    }

    /** A plain tar, a gzip file of several members and NUL padding after the gzip stream are all read whole. */
    public function testPlainTarMultiMemberGzipAndNulPaddingAreRead(): void {
        $tar = implode('', $this->clean()) . str_repeat("\0", 1024);
        $plain = $this->dir . '/plain.tar';
        file_put_contents($plain, $tar);
        $split = $this->dir . '/split.tar.gz';
        file_put_contents($split, gzencode(substr($tar, 0, 1500)) . gzencode(substr($tar, 1500)) . str_repeat("\0", 700));
        $expected = count($this->clean());
        $this->assertCount($expected, PackageCheck::members($plain));
        $this->assertCount($expected, PackageCheck::members($split));
        $this->assertSame([], $this->checkArchive($split));
    }

    /** Base-256 sizes are decoded up to PHP_INT_MAX; a negative or larger one is a controlled error. */
    public function testBase256SizesAreBoundedAndNeverNegative(): void {
        $this->assertSame(0x1234, PackageCheck::size("\x80" . str_repeat("\0", 9) . "\x12\x34"));
        $this->assertSame(PHP_INT_MAX, PackageCheck::size("\x80\0\0\0\x7f" . str_repeat("\xff", 7)));
        foreach (["\x80\0\0\0\x80" . str_repeat("\0", 7), "\x80" . str_repeat("\xff", 11), str_repeat("\xff", 12), "\xc0" . str_repeat("\0", 11)] as $field) {
            try {
                PackageCheck::size($field);
                $this->fail('accepted ' . bin2hex($field));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('base-256', $e->getMessage());
            }
        }
    }

    /** An entry whose base-256 size overflows ends the command line with exit 1 and a message, not a fatal error. */
    public function testAnOverflowingSizeIsReportedByTheCommandLine(): void {
        $entry = self::header('mcp/huge', '0', 0, sizeField: "\x80" . str_repeat("\xff", 11));
        [$code, $stdout, $stderr] = $this->runScript([$this->archive([...$this->clean(), $entry]), 'templates']);
        $this->assertSame([1, ''], [$code, $stdout]);
        $this->assertStringContainsString('base-256 field in a header is too large', $stderr);
    }

    /**
     * Metadata bodies never have to fit in memory: with memory_limit=16M a 24 MiB GNU long link is skipped, a
     * 24 MiB pax header is still reported and a 24 MiB long name is refused, each with a message and exit code.
     */
    public function testLargeMetadataBodiesStayWithinASmallMemoryLimit(): void {
        $size = 24 << 20;
        $body = str_repeat("\0", $size);
        $cases = [
            [self::header('././@LongLink', 'K', $size) . $body, 0, ''],
            [self::header('PaxHeader/x', 'x', $size) . $body, 1, "pax header on: mcp/after\n"],
            [self::header('././@LongLink', 'L', $size) . $body, 1, "has a GNU long name of $size bytes, over the limit of 65536\n"],
        ];
        foreach ($cases as [$extension, $expectedCode, $expectedEnd]) {
            $archive = $this->archive([...$this->clean(), $extension, self::file('mcp/after')]);
            [$code, $stdout, $stderr] = $this->runScript([$archive, 'templates'], ['-d', 'memory_limit=16M']);
            $this->assertSame([$expectedCode, ''], [$code, $stdout], $stderr);
            $this->assertSame($expectedEnd, $expectedEnd === '' ? $stderr : substr($stderr, -strlen($expectedEnd)));
            unlink($archive);
        }
    }

    /** @return list<string> the raw tar blocks of a production-shaped package */
    private function clean(): array {
        return [
            self::dir('mcp'),
            self::dir('mcp/appinfo'),
            self::file('mcp/appinfo/info.xml', '<info/>'),
            self::file('mcp/js/admin.js', 'x'),
            self::file('mcp/css/admin.css', 'x'),
            self::dir('mcp/vendor'),
            self::file('mcp/vendor/autoload.php'),
            self::file('mcp/vendor/composer/autoload_real.php'),
            self::file('mcp/vendor/smalot/pdfparser/src/Parser.php'),
            self::file('mcp/vendor/symfony/polyfill-mbstring/Mbstring.php'),
        ];
    }

    /**
     * @param list<string> $entries raw tar blocks
     * @return list<string> the problems PackageCheck finds, with templates/ as the source
     */
    private function check(array $entries): array {
        return $this->checkArchive($this->archive($entries));
    }

    /**
     * @param string $archive path of an archive in the temporary folder
     * @return list<string> the problems PackageCheck finds, with templates/ as the source
     */
    private function checkArchive(string $archive): array {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            return PackageCheck::problems($archive, ['templates']);
        } finally {
            chdir($cwd);
        }
    }

    /**
     * @param list<string> $entries raw tar blocks
     * @param bool $end whether the two end-of-archive zero blocks follow the entries
     * @return string path of the gzip-compressed archive written to the temporary folder
     */
    private function archive(array $entries, bool $end = true): string {
        $path = $this->dir . '/package-' . bin2hex(random_bytes(4)) . '.tar.gz';
        file_put_contents($path, gzencode(implode('', $entries) . ($end ? str_repeat("\0", 1024) : '')));
        return $path;
    }

    /**
     * Runs the script as package.sh does, from the temporary folder.
     *
     * @param list<string> $args arguments after the script path
     * @param list<string> $options options of the PHP binary, before the script path
     * @return array{0: int, 1: string, 2: string} exit code, stdout and stderr
     */
    private function runScript(array $args, array $options = []): array {
        $process = proc_open([PHP_BINARY, ...$options, realpath(self::SCRIPT), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** A regular file entry with its content padded to 512 bytes. */
    private static function file(string $name, string $content = '', string $prefix = ''): string {
        return self::header($name, '0', strlen($content), $prefix) . self::pad($content);
    }

    /** A directory entry. */
    private static function dir(string $name): string {
        return self::header($name, '5', 0);
    }

    /** A pax extended header (typeflag x or g) holding the given records. */
    private static function pax(string $type, array $records): string {
        $body = '';
        foreach ($records as $key => $value) {
            $record = " $key=$value\n";
            // The length counts its own digits.
            $length = strlen($record);
            while (strlen($length . $record) !== $length) {
                $length = strlen($length . $record);
            }
            $body .= $length . $record;
        }
        return self::header('PaxHeader/x', $type, strlen($body)) . self::pad($body);
    }

    /** A GNU long name entry (typeflag L), applied to the entry that follows. */
    private static function longName(string $name): string {
        return self::header('././@LongLink', 'L', strlen($name) + 1) . self::pad($name . "\0");
    }

    /** A ustar header with a valid checksum; $sizeField replaces the octal size field (12 raw bytes). */
    private static function header(string $name, string $type, int $size, string $prefix = '', ?string $sizeField = null): string {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . sprintf('%07o', 0644) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . ($sizeField ?? sprintf('%011o', $size) . "\0")
            . sprintf('%011o', 0) . "\0"
            . str_repeat(' ', 8)
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_repeat("\0", 32 + 32 + 8 + 8)
            . str_pad($prefix, 155, "\0");
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(unpack('C*', $header));
        return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    }

    /** The content padded to a multiple of 512 bytes. */
    private static function pad(string $content): string {
        return $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
    }
}
