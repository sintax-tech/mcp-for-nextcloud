<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/**
 * {@see LibScanner::constructions()} lists every `new` of a Nextcloud class with how it is called, so the contract can
 * check each constructor against every covered major without a hand-kept list.
 */
final class LibScannerTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mcp-lib-scanner-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void {
        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testEveryNewOfANextcloudClassIsListedWithItsArguments(): void {
        file_put_contents($this->root . '/Sample.php', <<<'PHP'
            <?php
            namespace OCA\Mcp\Sample;

            use OCP\Security\Events\ValidatePasswordPolicyEvent;
            use OCA\DAV\RootCollection;
            use OCA\Mcp\Own\Thing;

            final class Sample {
                public function run(array $values): void {
                    new ValidatePasswordPolicyEvent($password, PasswordContext::SHARING);
                    new RootCollection();
                    new RootCollection;
                    new \OCA\DAV\CardDAV\Plugin(f($a, [$b, $c]), fn ($x) => [$x, $y], $d ? $e : $g,);
                    new \OCP\Files\Search\SearchQuery(...$values);
                    new \OCP\AppFramework\Http\JSONResponse(data: [], statusCode: 200);
                    new Thing(1, 2);
                    new \DateTime('now');
                    new class (1) {
                        public function __construct(int $x) {
                        }
                    };
                }
            }
            PHP);

        $constructions = (new LibScanner($this->root))->constructions();

        $this->assertSame([
            'OCA\\DAV\\CardDAV\\Plugin' => [['file' => 'Sample.php', 'arguments' => 3, 'spread' => false, 'named' => []]],
            'OCA\\DAV\\RootCollection' => [
                ['file' => 'Sample.php', 'arguments' => 0, 'spread' => false, 'named' => []],
                ['file' => 'Sample.php', 'arguments' => 0, 'spread' => false, 'named' => []],
            ],
            'OCP\\AppFramework\\Http\\JSONResponse' => [['file' => 'Sample.php', 'arguments' => 2, 'spread' => false, 'named' => ['data', 'statusCode']]],
            'OCP\\Files\\Search\\SearchQuery' => [['file' => 'Sample.php', 'arguments' => 1, 'spread' => true, 'named' => []]],
            'OCP\\Security\\Events\\ValidatePasswordPolicyEvent' => [['file' => 'Sample.php', 'arguments' => 2, 'spread' => false, 'named' => []]],
        ], $constructions);
    }
}
