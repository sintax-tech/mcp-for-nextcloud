<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/**
 * {@see SourceParser} reads the parameters of every signature the fixtures record. A parameter it drops makes a fixture
 * claim a shorter signature than the release has, and the contract then accepts a call the server refuses: Nextcloud
 * 35 marks password parameters with `#[SensitiveParameter]`, which the parser used to stop at.
 */
final class SourceParserTest extends TestCase {
    private string $tree;

    protected function setUp(): void {
        parent::setUp();
        $this->tree = sys_get_temp_dir() . '/mcp-source-parser-' . bin2hex(random_bytes(6));
        mkdir($this->tree . '/lib/public/Sample', 0700, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->tree . '/lib/public/Sample/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (['/lib/public/Sample', '/lib/public', '/lib', ''] as $directory) {
            rmdir($this->tree . $directory);
        }
        parent::tearDown();
    }

    /** @return array<string, list<string>> the method signatures the parser reads from the class source */
    private function methodsOf(string $body): array {
        file_put_contents($this->tree . '/lib/public/Sample/Event.php', "<?php\nnamespace OCP\\Sample;\n\nuse SensitiveParameter;\n\n$body\n");
        $surface = (new SourceParser($this->tree))->surface('OCP\\Sample\\Event');
        $this->assertNotNull($surface);
        return $surface['methods'];
    }

    public function testAnAttributeOnAPromotedParameterKeepsEveryParameter(): void {
        $methods = $this->methodsOf(<<<'PHP'
            class Event {
                public function __construct(
                    #[SensitiveParameter]
                    private string $password,
                    private PasswordContext $context = PasswordContext::ACCOUNT,
                ) {
                }
            }
            PHP);

        $this->assertSame(['password', 'context?'], $methods['__construct']);
    }

    public function testAnAttributeOnAnOrdinaryParameterKeepsEveryParameter(): void {
        $methods = $this->methodsOf(<<<'PHP'
            class Event {
                public function setPassword(#[SensitiveParameter] string $password, int $ttl = 0): void {
                }
            }
            PHP);

        $this->assertSame(['password', 'ttl?'], $methods['setPassword']);
    }

    public function testSeveralAttributesWithArgumentsAndOverManyLinesKeepEveryParameter(): void {
        $methods = $this->methodsOf(<<<'PHP'
            class Event {
                #[\Override]
                public function check(
                    #[SensitiveParameter, Deprecated(since: '35', message: 'use [x] (or y)')]
                    #[Tagged(['a' => [1, 2]], new Option(flags: [3]))]
                    string $secret,
                    #[
                        Named('b'),
                    ]
                    array $options = ['c' => ['d']],
                    #[Variadic] string ...$rest,
                ): bool {
                    return true;
                }

                public function after(string $value): void {
                }
            }
            PHP);

        $this->assertSame(['secret', 'options?', '...rest'], $methods['check']);
        $this->assertSame(['value'], $methods['after'], 'the methods after an attributed signature are still read');
    }

    public function testSignaturesWithoutAttributesAreReadAsBefore(): void {
        $methods = $this->methodsOf(<<<'PHP'
            class Event {
                public function send(object $chat, ?string $message = null, array $meta = [], string ...$tags): void {
                }
            }
            PHP);

        $this->assertSame(['chat', 'message?', 'meta?', '...tags'], $methods['send']);
    }
}
