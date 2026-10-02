<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A store double may only answer what the production store answers.
 *
 * A double that gains a method the real class does not have makes the code that calls it green in the tests and a
 * fatal "Call to undefined method" on the server: that is how files_undo_batch reached production in 0.9.0 with a
 * BatchStore::keepRemaining() that only the in-memory store had. What a test needs to look inside a double goes in
 * public properties, never in a method.
 */
final class StoreDoubleContractTest extends TestCase {
    /** @return array<string, array{class-string, class-string}> double => the production class it stands for */
    public static function doubles(): array {
        return [
            'BatchStore' => [\OCA\Mcp\Tests\Unit\Tools\Files\InMemoryBatchStore::class, \OCA\Mcp\Tools\Files\BatchStore::class],
            'OAuthStore' => [\OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore::class, \OCA\Mcp\OAuth\OAuthStore::class],
        ];
    }

    /**
     * @param class-string $double the test double
     * @param class-string $real the production class
     */
    #[DataProvider('doubles')]
    public function testTheDoubleHasNoPublicMethodTheProductionStoreLacks(string $double, string $real): void {
        $this->assertTrue(is_subclass_of($double, $real), "$double precisa estender $real");
        $names = static fn (string $class): array => array_map(static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        $this->assertSame([], array_values(array_diff($names($double), $names($real))),
            "métodos públicos de $double que $real não tem");
    }

    /** A real method the double does not override would reach for the database the double was given in its place. */
    #[DataProvider('doubles')]
    public function testTheDoubleOverridesEveryPublicMethodOfTheProductionStore(string $double, string $real): void {
        $missing = [];
        foreach ((new \ReflectionClass($real))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }
            if ((new \ReflectionMethod($double, $method->getName()))->getDeclaringClass()->getName() !== $double) {
                $missing[] = $method->getName();
            }
        }
        $this->assertSame([], $missing, "métodos de $real que $double não sobrescreve");
    }
}
