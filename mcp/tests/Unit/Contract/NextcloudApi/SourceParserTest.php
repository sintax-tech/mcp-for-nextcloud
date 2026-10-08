<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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

    /**
     * The types are what tells a parameter that kept its name but changed meaning, as Deck 1.19 turned
     * `CardService::update(?string $color)` into `?OptionalNullableValue $color`.
     */
    public function testParameterTypesAreReadResolvedAndAlignedWithTheNames(): void {
        file_put_contents($this->tree . '/lib/public/Sample/Event.php', <<<'PHP'
            <?php
            namespace OCP\Sample;

            use OCA\Deck\Model\OptionalNullableValue;
            use Other\Thing as Alias;

            class Event {
                public function update(
                    int $id,
                    ?string $color = null,
                    ?OptionalNullableValue $wrapped = null,
                    Local|Alias|null $union = null,
                    #[\SensitiveParameter] private readonly \Fully\Qualified $promoted = new \Fully\Qualified(['x' => (1)]),
                    &$byReference = [],
                    (Local&Alias)|null $dnf = null,
                    $untyped = null,
                    string &...$rest,
                ): void {
                }
            }
            PHP);
        $surface = (new SourceParser($this->tree))->surface('OCP\\Sample\\Event');

        $this->assertSame(['id', 'color?', 'wrapped?', 'union?', 'promoted?', 'byReference?', 'dnf?', 'untyped?', '...rest'], $surface['methods']['update']);
        $this->assertSame([
            'int', '?string', '?OCA\\Deck\\Model\\OptionalNullableValue', 'OCP\\Sample\\Local|Other\\Thing|null',
            'Fully\\Qualified', '', '(OCP\\Sample\\Local&Other\\Thing)|null', '', 'string',
        ], $surface['types']['update']);
    }

    /** A trait's public methods are the class's own: `use Trait;` in the body was skipped, and they went missing. */
    public function testMethodsOfAUsedTraitBelongToTheClass(): void {
        file_put_contents($this->tree . '/lib/public/Sample/Helper.php', <<<'PHP'
            <?php
            namespace OCP\Sample;

            trait Helper {
                public function fromTrait(string $value): void {
                }

                private function hidden(): void {
                }
            }
            PHP);
        file_put_contents($this->tree . '/lib/public/Sample/Other.php', "<?php\nnamespace OCP\\Sample;\n\ntrait Other {\n    public function fromTrait(): void {\n    }\n}\n");
        $methods = $this->methodsOf(<<<'PHP'
            class Event {
                use Helper, Other {
                    Helper::fromTrait insteadof Other;
                }

                public function own(): void {
                    $callback = function () use ($x) {
                    };
                }
            }
            PHP);

        $this->assertSame(['fromTrait' => ['value'], 'own' => []], $methods);
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
