<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Service\Compat\AppEnablement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every Nextcloud API the app uses exists, in the shape it is used, in every Nextcloud major the fixtures cover: the
 * declared range and any older release kept ready for the next widening.
 *
 * The suite runs against `nextcloud/ocp` of one branch and doubles of Deck, Talk and DAV, so nothing else would notice
 * a class, method, constant or parameter that an older covered release lacks: the call would only fail on that
 * server. The truth per release is a fixture of tests/fixtures/nextcloud-api, generated from the release sources by
 * generate.php there; {@see LibScanner} reads what lib/ uses and {@see NextcloudApiUsage} adds what a scanner cannot
 * see. An API a release lacks is accepted only in {@see NextcloudApiUsage::VERSION_GATED}, with its fallback.
 */
final class NextcloudApiContractTest extends TestCase {
    private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';
    private const INFO_XML = __DIR__ . '/../../../../appinfo/info.xml';
    private const LIB = __DIR__ . '/../../../../lib';

    /** @var array<string, array{sources: array<string, string>, classes: array<string, array<string, mixed>|null>}> */
    private static array $fixtures = [];
    private static ?LibScanner $scanner = null;

    /** @return list<string> the Nextcloud majors info.xml declares, oldest first */
    private static function declaredMajors(): array {
        $nextcloud = simplexml_load_file(self::INFO_XML)->dependencies->nextcloud;
        return array_map('strval', range((int)$nextcloud['min-version'], (int)$nextcloud['max-version']));
    }

    /**
     * @return list<string> every major there is a fixture of, oldest first: the declared range, plus the older
     * releases kept checked so widening info.xml is a matter of declaring them again
     */
    private static function checkedMajors(): array {
        $majors = array_map(static fn (string $file): string => basename($file, '.php'), glob(self::FIXTURES . '/[0-9]*.php') ?: []);
        sort($majors);
        return $majors;
    }

    /** @return array{sources: array<string, string>, classes: array<string, array<string, mixed>|null>} */
    private static function fixture(string $major): array {
        return self::$fixtures[$major] ??= require self::FIXTURES . '/' . $major . '.php';
    }

    private static function scanner(): LibScanner {
        return self::$scanner ??= new LibScanner((string)realpath(self::LIB));
    }

    /** @return array<string, mixed>|null the class surface in that release, null when it lacks the class */
    private static function surface(string $major, string $class): ?array {
        return self::fixture($major)['classes'][$class] ?? null;
    }

    /**
     * A method counts as provided when the class declares it, when an Entity field serves it through the magic getX(),
     * setX() and isX() of OCP\AppFramework\Db\Entity, or when an ancestor outside Nextcloud declares it: Nextcloud
     * ships Sabre and the other libraries of its 3rdparty with the versions of the app's own vendor/.
     */
    private static function serves(?array $surface, string $method): bool {
        if ($surface === null) {
            return false;
        }
        if (isset($surface['methods'][$method])) {
            return true;
        }
        foreach ($surface['external'] as $ancestor) {
            if ((class_exists($ancestor) || interface_exists($ancestor)) && method_exists($ancestor, $method)) {
                return true;
            }
        }
        return preg_match('/^(?:get|set|is)([A-Z]\w*)$/', $method, $match) === 1 && in_array(lcfirst($match[1]), $surface['fields'], true);
    }

    /** @return bool whether the class or member is gated for that major in NextcloudApiUsage::VERSION_GATED */
    private static function gated(string $api, string $major): bool {
        return in_array($major, NextcloudApiUsage::VERSION_GATED[$api]['missing'] ?? [], true);
    }

    /**
     * Every declared major is checked; a fixture of a release older than the declared range is allowed and kept, so the
     * code can be declared for 31 and 32 again without regenerating anything.
     */
    public function testThereIsAFixtureForEveryDeclaredMajor(): void {
        $sources = array_map('strval', array_keys(NextcloudApiUsage::SOURCES));
        foreach (self::declaredMajors() as $major) {
            $this->assertContains($major, self::checkedMajors(), "no fixture for the declared Nextcloud $major: regenerate tests/fixtures/nextcloud-api");
            $this->assertContains($major, $sources, "NextcloudApiUsage::SOURCES has no entry for the declared major $major");
        }
        foreach (self::checkedMajors() as $major) {
            $this->assertContains($major, $sources, "NextcloudApiUsage::SOURCES has no entry for the fixture $major");
        }
    }

    /** The fixtures come from the oldest release of each major covered; for Deck and Talk, the oldest one checks the minimums the app enforces. */
    public function testFixturesWereGeneratedFromTheOldestCoveredReleases(): void {
        foreach (NextcloudApiUsage::SOURCES as $major => $sources) {
            $this->assertSame($sources, self::fixture((string)$major)['sources'], "fixture $major");
            $this->assertStringStartsWith($major . '.', $sources['server']);
        }
        $oldest = NextcloudApiUsage::SOURCES[self::checkedMajors()[0]];
        foreach (AppEnablement::MINIMUM_VERSIONS as $app => $minimum) {
            $this->assertSame($minimum, $oldest[$app], "the $app minimum must be the release the oldest fixture checks");
        }
    }

    /** @return array<string, array{string, string}> one case per Nextcloud class lib/ names and per covered major */
    public static function namedClasses(): array {
        $cases = [];
        foreach (self::scanner()->classes() as $class => $files) {
            foreach (self::checkedMajors() as $major) {
                $cases["$class @ $major"] = [$class, $major];
            }
        }
        return $cases;
    }

    #[DataProvider('namedClasses')]
    public function testEveryNextcloudClassTheAppNamesExists(string $class, string $major): void {
        $fixture = self::fixture($major)['classes'];
        $this->assertArrayHasKey($class, $fixture, "$class is new in lib/: regenerate the fixtures with tests/fixtures/nextcloud-api/generate.php");
        if (self::gated($class, $major)) {
            $this->assertNull($fixture[$class], "$class exists in $major now: drop it from NextcloudApiUsage::VERSION_GATED");
            return;
        }
        $files = implode(', ', self::scanner()->classes()[$class]);
        $this->assertNotNull($fixture[$class], "$class, used in $files, does not exist in Nextcloud $major");
    }

    /**
     * Every method name lib/ calls, on a class that declares it in any covered release, exists in all of them: a
     * method a newer major removed is caught as surely as one an older major lacks. The pairing is by name, so a name
     * the class only shares with another object is listed in NAME_CLASHES.
     *
     * @return array<string, array{string, list<string>, string}> one case per class and covered major, with its methods
     */
    public static function calledMethods(): array {
        $majors = self::checkedMajors();
        $cases = [];
        foreach (array_keys(self::scanner()->classes()) as $class) {
            $methods = array_values(array_filter(
                array_keys(self::scanner()->methods()),
                static function (string $method) use ($class, $majors): bool {
                    if (isset(NextcloudApiUsage::NAME_CLASHES["$class::$method"])) {
                        return false;
                    }
                    foreach ($majors as $major) {
                        if (self::serves(self::surface($major, $class), $method)) {
                            return true;
                        }
                    }
                    return false;
                },
            ));
            if ($methods === []) {
                continue;
            }
            foreach ($majors as $major) {
                $cases["$class @ $major"] = [$class, $methods, $major];
            }
        }
        return $cases;
    }

    /**
     * @param list<string> $methods method names lib/ calls that the class declares in the newest release
     */
    #[DataProvider('calledMethods')]
    public function testEveryCalledMethodExistsInEveryCoveredMajor(string $class, array $methods, string $major): void {
        if (self::gated($class, $major)) {
            $this->assertNull(self::surface($major, $class));
            return;
        }
        $missing = [];
        foreach ($methods as $method) {
            $present = self::serves(self::surface($major, $class), $method);
            if (self::gated("$class::$method", $major)) {
                $this->assertFalse($present, "$class::$method exists in $major now: drop it from VERSION_GATED");
            } elseif (!$present) {
                $missing[] = "$method(), called in " . implode(', ', array_unique(self::scanner()->methods()[$method]));
            }
        }
        $this->assertSame([], $missing, "methods of $class that Nextcloud $major does not have");
    }

    /** @return array<string, array{string, list<string>, string}> */
    public static function positionalCalls(): array {
        $cases = [];
        foreach (NextcloudApiUsage::POSITIONAL as $call => $parameters) {
            foreach (self::checkedMajors() as $major) {
                $cases["$call @ $major"] = [$call, $parameters, $major];
            }
        }
        return $cases;
    }

    /**
     * @param list<string> $parameters what the app passes, in order
     */
    #[DataProvider('positionalCalls')]
    public function testPositionalCallsKeepTheirParametersInEveryCoveredMajor(string $call, array $parameters, string $major): void {
        [$class, $method] = explode('::', $call);
        if (self::gated($class, $major)) {
            $this->assertNull(self::surface($major, $class));
            return;
        }
        $surface = self::surface($major, $class);
        $this->assertNotNull($surface, "$class does not exist in Nextcloud $major");
        $this->assertTrue(self::serves($surface, $method), "$call does not exist in Nextcloud $major");
        if (!isset($surface['methods'][$method])) {
            // An Entity getter takes no argument; there is no signature to compare.
            $this->assertSame([], $parameters);
            return;
        }
        $this->assertNull(self::signatureMismatch($surface['methods'][$method], $parameters), "$call in Nextcloud $major");
    }

    /**
     * The rule above must fail on a real change of signature, or every positional case passes vacuously: here it is
     * fed the Nextcloud 35 signatures with the changes the contract exists to catch.
     */
    public function testThePositionalRuleCatchesAChangedSignature(): void {
        $sendMessage = self::fixture('35')['classes']['OCA\\Talk\\Chat\\ChatManager']['methods']['sendMessage'];
        $passes = NextcloudApiUsage::POSITIONAL['OCA\\Talk\\Chat\\ChatManager::sendMessage'];
        $this->assertNull(self::signatureMismatch($sendMessage, $passes), 'the real signature is accepted');

        $required = $sendMessage;
        array_splice($required, 6, 0, ['threadId']);
        $this->assertNotNull(self::signatureMismatch($required, $passes), 'a required parameter inserted before the ones passed');
        $renamed = $sendMessage;
        $renamed[4] = 'comment';
        $this->assertNotNull(self::signatureMismatch($renamed, $passes), 'a parameter renamed at a passed position');
        $this->assertNotNull(self::signatureMismatch([...$sendMessage, 'verb'], $passes), 'a new required trailing parameter');
        // What the generator wrote before it read parameters behind #[SensitiveParameter].
        $this->assertNotNull(self::signatureMismatch([], NextcloudApiUsage::POSITIONAL['OCP\\Security\\Events\\ValidatePasswordPolicyEvent::__construct']), 'a signature read without its parameters');
    }

    /**
     * @param list<string> $declared the parameters of the release, as the fixture writes them
     * @param list<string> $parameters what the app passes, in order
     * @return string|null why the call no longer fits the signature, null when it does
     */
    private static function signatureMismatch(array $declared, array $parameters): ?string {
        $names = array_map(static fn (string $name): string => rtrim(ltrim($name, '.'), '?'), $declared);
        if (array_slice($names, 0, count($parameters)) !== $parameters) {
            return 'takes (' . implode(', ', $declared) . ')';
        }
        foreach (array_slice($declared, count($parameters)) as $rest) {
            if (!str_ends_with($rest, '?') && !str_starts_with($rest, '...')) {
                return "requires \$$rest, which the app does not pass";
            }
        }
        return null;
    }

    /** @return array<string, array{string, string, string}> */
    public static function readConstants(): array {
        $cases = [];
        foreach (NextcloudApiUsage::CONSTANTS as $class => $constants) {
            foreach ($constants as $constant) {
                foreach (self::checkedMajors() as $major) {
                    $cases["$class::$constant @ $major"] = [$class, $constant, $major];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('readConstants')]
    public function testConstantsReadByNameExistInEveryCoveredMajor(string $class, string $constant, string $major): void {
        $this->assertContains($constant, self::surface($major, $class)['constants'] ?? [], "$class::$constant does not exist in Nextcloud $major");
    }

    /**
     * \OC is private and lost members across majors (Nextcloud 34 dropped OC\Server getters, 35 moved the class out of
     * lib/base.php): the app reaches the server container and the web root through OCP only.
     */
    public function testTheAppNeverTouchesThePrivateOcClass(): void {
        $this->assertArrayNotHasKey('OC', self::scanner()->classes(), 'lib/ uses \\OC; use OCP\\Server::get(ContainerInterface::class) or IURLGenerator::getWebroot()');
        $this->assertSame([], self::scanner()->properties(), 'lib/ reads a static property of a Nextcloud class');
    }

    /** A gap is accepted only where its fallback exists, and the class that handles the gap names that fallback. */
    public function testEveryVersionGatedApiHasItsFallbackWhereItIsMissing(): void {
        foreach (NextcloudApiUsage::VERSION_GATED as $api => $gate) {
            [$class, $member] = explode('::', $api) + [1 => null];
            foreach (self::checkedMajors() as $major) {
                $present = $member === null ? self::surface($major, $class) !== null : self::serves(self::surface($major, $class), $member);
                $this->assertSame(!in_array($major, $gate['missing'], true), $present, "$api in Nextcloud $major");
            }
            foreach ($gate['missing'] as $major) {
                [$fallbackClass, $fallbackMember] = explode('::', $gate['fallback']) + [1 => null];
                $surface = self::surface($major, $fallbackClass);
                $this->assertTrue($fallbackMember === null ? $surface !== null : self::serves($surface, $fallbackMember), "fallback {$gate['fallback']} in Nextcloud $major");
            }
            // The last segment of the fallback (a class short name or a method name) must appear in the handler's code.
            $source = (string)file_get_contents((string)(new \ReflectionClass($gate['by']))->getFileName());
            $this->assertStringContainsString((string)preg_replace('/^.*[\\\\:]/', '', $gate['fallback']), $source, "{$gate['by']} must use the fallback of $api");
        }
    }

    /**
     * Only the class that handles a gap may touch the API it gates: a second caller would skip the fallback and break
     * on the release that lacks the API.
     */
    public function testAVersionGatedApiIsOnlyUsedByTheClassThatHandlesTheGap(): void {
        foreach (NextcloudApiUsage::VERSION_GATED as $api => $gate) {
            [$class, $member] = explode('::', $api) + [1 => null];
            $files = $member === null ? self::scanner()->classes()[$class] ?? [] : self::scanner()->methods()[$member] ?? [];
            $handler = substr((string)(new \ReflectionClass($gate['by']))->getFileName(), strlen((string)realpath(self::LIB)) + 1);
            $this->assertNotSame([], $files, "$api is no longer used: drop it from VERSION_GATED");
            // A file listed in `elsewhere` calls the same name on another object; the entry must stay true.
            foreach (array_keys($gate['elsewhere'] ?? []) as $file) {
                $this->assertContains($file, $files, "$api: $file no longer calls that name, drop it from `elsewhere`");
            }
            $files = array_diff($files, array_keys($gate['elsewhere'] ?? []));
            $this->assertSame([$handler], array_values(array_unique($files)), "$api must only be used through {$gate['by']}");
        }
    }

    /** The scanner itself still sees what it is there to see; an empty answer would make every case above vacuous. */
    public function testTheScannerSeesTheKnownUses(): void {
        $classes = self::scanner()->classes();
        foreach (['OCP\\IUserManager', 'OCA\\Talk\\Chat\\ChatManager', 'OCA\\Deck\\Db\\CardMapper', 'OCA\\DAV\\CalDAV\\EmbeddedCalDavServer', 'OCA\\DAV\\AppInfo\\PluginManager'] as $class) {
            $this->assertArrayHasKey($class, $classes);
        }
        $this->assertArrayNotHasKey('OCA\\Mcp\\Service\\GrantPolicy', $classes, 'the app is not a dependency of itself');
        foreach (['addSystemMessage', 'findAllForStacks', 'isEnabledForAnyone', 'searchUsersByValueString'] as $method) {
            $this->assertArrayHasKey($method, self::scanner()->methods());
        }
    }
}
