<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;

/** The resource scope tables (issue #4, contracts B.3): additive, portable, seeded with nothing. */
final class ScopeSchemaTest extends TestCase {
    private const TABLES = [
        ResourceScopeMapper::MODES_TABLE => [
            'pk' => ['user_key', 'module'],
            'columns' => ['user_key' => [StringType::class, 64], 'module' => [StringType::class, 32], 'user_id' => [TextType::class, null], 'policy_rev' => [BigIntType::class, null]],
            'text' => ['user_id'],
        ],
        ResourceScopeMapper::ITEMS_TABLE => [
            'pk' => ['user_key', 'module', 'resource_key'],
            'columns' => ['user_key' => [StringType::class, 64], 'module' => [StringType::class, 32], 'resource_key' => [StringType::class, 64], 'resource_id' => [TextType::class, null]],
            'text' => ['resource_id'],
        ],
    ];

    /** @return list<SimpleMigrationStep> the steps of 1.0.8 */
    private function legacy(): array {
        return [new Version000500Date20260930000000(), new Version000700Date20261001000000(), new Version000800Date20261002000000(),
            new Version000800Date20261002010000(), new Version001002Date20261008000000()];
    }

    /** @return list<SimpleMigrationStep> the 1.1.0 steps before this one */
    private function earlier(): array {
        return [new Version001100Date20261014000000(), new Version001100Date20261015000000($this->createMock(IDBConnection::class)),
            new Version001100Date20261015010000()];
    }

    /** @return array{Schema, \Closure} */
    private function schema(): array {
        $schema = new Schema();
        $wrapper = $this->createMock(ISchemaWrapper::class);
        $wrapper->method('hasTable')->willReturnCallback(fn (string $name): bool => $schema->hasTable($name));
        $wrapper->method('createTable')->willReturnCallback(fn (string $name) => $schema->createTable($name));
        $wrapper->method('getTable')->willReturnCallback(fn (string $name) => $schema->getTable($name));
        return [$schema, fn () => $wrapper];
    }

    /** @param list<SimpleMigrationStep> $steps */
    private function apply(\Closure $closure, array $steps): void {
        foreach ($steps as $step) {
            $step->changeSchema($this->createMock(IOutput::class), $closure, []);
        }
    }

    /** @return array<string, list<string>> */
    private static function sql(Schema $schema): array {
        $sql = [];
        foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform()] as $platform) {
            $sql[$platform::class] = $schema->toSql($platform);
        }
        return $sql;
    }

    public function testFreshInstallAndUpgradesFrom108AndFromTheEarlier110StepsGiveTheSameSchema(): void {
        [$fresh, $freshClosure] = $this->schema();
        $this->apply($freshClosure, [...$this->legacy(), ...$this->earlier(), new Version001100Date20261015020000()]);

        foreach ([[], $this->earlier()] as $installed) {
            [$upgraded, $closure] = $this->schema();
            $this->apply($closure, [...$this->legacy(), ...$installed]);
            $before = clone $upgraded;
            $this->apply($closure, [...($installed === [] ? $this->earlier() : []), new Version001100Date20261015020000()]);
            $this->assertSame(self::sql($fresh), self::sql($upgraded));

            if ($installed !== []) {
                // Purely additive: every existing table keeps its columns and indexes, and only the two tables appear.
                foreach ($before->getTables() as $old) {
                    $new = $upgraded->getTable($old->getName());
                    $this->assertEquals($old->getColumns(), $new->getColumns(), $old->getName());
                    $this->assertEquals($old->getIndexes(), $new->getIndexes(), $old->getName());
                }
                $added = array_diff(array_map(static fn ($t): string => $t->getName(), $upgraded->getTables()),
                    array_map(static fn ($t): string => $t->getName(), $before->getTables()));
                sort($added);
                $this->assertSame([ResourceScopeMapper::ITEMS_TABLE, ResourceScopeMapper::MODES_TABLE], $added);
            }

            $sql = self::sql($upgraded);
            $this->assertNull((new Version001100Date20261015020000())->changeSchema($this->createMock(IOutput::class), $closure, []));
            $this->assertSame($sql, self::sql($upgraded));
        }
    }

    public function testTablesHaveTheContractShapeWithoutModeFlagIndexOnTextOrForeignKeys(): void {
        [$schema, $closure] = $this->schema();
        $this->apply($closure, [...$this->legacy(), ...$this->earlier(), new Version001100Date20261015020000()]);

        foreach (self::TABLES as $name => $shape) {
            $this->assertLessThanOrEqual(24, strlen($name));
            $table = $schema->getTable($name);
            $this->assertSame($shape['pk'], $table->getPrimaryKey()->getColumns(), $name);
            $this->assertSame(array_keys($shape['columns']), array_map(static fn ($c): string => $c->getName(), $table->getColumns()), $name);
            $this->assertSame([], $table->getForeignKeys(), $name);
            foreach ($shape['columns'] as $column => [$type, $length]) {
                $definition = $table->getColumn($column);
                $this->assertInstanceOf($type, $definition->getType(), "$name.$column");
                $this->assertNotInstanceOf(BooleanType::class, $definition->getType(), "$name.$column");
                $this->assertTrue($definition->getNotnull(), "$name.$column");
                $this->assertNull($definition->getDefault(), "$name.$column: nothing is materialized by default");
                if ($length !== null) {
                    $this->assertSame($length, $definition->getLength(), "$name.$column");
                }
            }
            foreach ($table->getIndexes() as $index) {
                $this->assertLessThanOrEqual(24, strlen($index->getName()), $index->getName());
                $this->assertSame([], array_intersect($shape['text'], $index->getColumns()), $index->getName());
            }
        }
        foreach (self::sql($schema) as $statements) {
            foreach ($statements as $statement) {
                $this->assertStringNotContainsString('FOREIGN KEY', $statement);
            }
        }
    }

    public function testTheMigrationSeedsNothing(): void {
        $step = new \ReflectionClass(Version001100Date20261015020000::class);
        foreach (['preSchemaChange', 'postSchemaChange'] as $method) {
            $this->assertSame(SimpleMigrationStep::class, $step->getMethod($method)->getDeclaringClass()->getName(), $method);
        }
        $this->assertNull($step->getConstructor(), 'no database connection, so no data step');
    }

    /**
     * A rollback to 1.0.8 before any scope is adopted leaves two empty tables that nothing of 1.0.8 reads, and the
     * 1.0.8 steps neither touch nor need them. Only the mapper names the tables; besides it, only the scope writer,
     * the matrix summary, the admin and personal scope routes, the native enumerator, the account cleanup (ScopeCleanup) and
     * the scope guard, read by the
     * resolution core into each operation's snapshot, use the mapper. No tool reads it directly: tools ask the operation
     * context, and a new reader has to be added here on purpose.
     */
    public function testThe108StepsIgnoreTheTablesAndOnlyTheScopeAdministrationReadsThem(): void {
        [$schema, $closure] = $this->schema();
        $this->apply($closure, [...$this->legacy(), ...$this->earlier(), new Version001100Date20261015020000()]);
        $sql = self::sql($schema);
        $this->apply($closure, $this->legacy());
        $this->assertSame($sql, self::sql($schema), 'running the 1.0.8 steps again changes nothing');

        $readers = [];
        $root = dirname(__DIR__, 3) . '/lib';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code = (string)file_get_contents((string)$file);
            if (str_contains($code, 'mcp_scope_') || str_contains($code, 'ResourceScopeMapper')) {
                $readers[] = substr((string)$file, strlen($root) + 1);
            }
        }
        sort($readers);
        $this->assertSame([
            'Access/Grants/GrantMatrix.php',
            'Access/Policy/Events/PolicyEventPayload.php',
            'Access/Policy/Resolution/PolicyResolver.php',
            'Access/ResourceScope/NativeResourceEnumerator.php',
            'Access/ResourceScope/ResourceScopeWriter.php',
            'Access/ResourceScope/ScopeCleanup.php',
            'Access/ResourceScope/ScopeGuard.php',
            'Access/ResourceScope/ScopeMutations.php',
            'Controller/MyScopeController.php',
            'Controller/ScopeController.php',
            'Db/ResourceScopeMapper.php',
            'Migration/Version001100Date20261015020000.php',
        ], $readers);
        $tables = array_values(array_filter($readers, static fn (string $file): bool
            => str_contains((string)file_get_contents($root . '/' . $file), 'mcp_scope_')));
        $this->assertSame(['Db/ResourceScopeMapper.php'], array_values(array_diff($tables, ['Migration/Version001100Date20261015020000.php'])),
            'only the mapper (and the migration that creates them) names the tables');
    }
}
