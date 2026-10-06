<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use OCA\Mcp\Scripts\PackageCheck;
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
        $archive = $this->archive($entries);
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
     * @return string path of the gzip-compressed archive written to the temporary folder
     */
    private function archive(array $entries): string {
        $path = $this->dir . '/package-' . bin2hex(random_bytes(4)) . '.tar.gz';
        file_put_contents($path, gzencode(implode('', $entries) . str_repeat("\0", 1024)));
        return $path;
    }

    /**
     * Runs the script as package.sh does, from the temporary folder.
     *
     * @param list<string> $args arguments after the script path
     * @return array{0: int, 1: string, 2: string} exit code, stdout and stderr
     */
    private function runScript(array $args): array {
        $process = proc_open([PHP_BINARY, realpath(self::SCRIPT), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
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

    /** A ustar header with a valid checksum. */
    private static function header(string $name, string $type, int $size, string $prefix = ''): string {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . sprintf('%07o', 0644) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
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
