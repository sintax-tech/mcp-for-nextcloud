<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory SQLite database built from the app's own migrations, behind the OCP connection the stores take.
 *
 * A store tested through a hand-written double only proves the double: the SQL itself — the owner filter, the purge,
 * the `IS NULL` that makes an update happen once — never runs. Here every query the store builds goes through Doctrine
 * to SQLite, so a missing condition changes the answer. Only the query-builder calls the stores use are wired; a store
 * that starts using another one fails loudly with PHPUnit's "method was not expected" instead of silently passing.
 */
final class SqliteDatabase {
    public readonly Connection $dbal;
    /** One-shot interleaving hook, invoked just before a database write. */
    public $beforeStatement = null;

    /**
     * @param TestCase $test the test that owns the mocks
     * @param list<IMigrationStep> $migrations the migrations that create the tables, in order
     */
    public function __construct(private TestCase $test, array $migrations) {
        $this->dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        $wrapper = $this->mock(ISchemaWrapper::class);
        $wrapper->method('hasTable')->willReturnCallback(fn (string $name): bool => $schema->hasTable($name));
        $wrapper->method('createTable')->willReturnCallback(fn (string $name) => $schema->createTable($name));
        $wrapper->method('getTable')->willReturnCallback(fn (string $name) => $schema->getTable($name));
        $output = $this->mock(IOutput::class);
        foreach ($migrations as $migration) {
            $migration->changeSchema($output, fn () => $wrapper, []);
        }
        foreach ($schema->toSql(new SQLitePlatform()) as $statement) {
            $this->dbal->executeStatement($statement);
        }
    }

    /** @return IDBConnection the connection a store is built with */
    public function connection(): IDBConnection {
        $db = $this->mock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
        $db->method('lastInsertId')->willReturnCallback(fn (): int => (int)$this->dbal->lastInsertId());
        return $db;
    }

    /**
     * @param string $table table name
     * @return list<array<string, mixed>> every row, ordered by id
     */
    public function rows(string $table): array {
        return $this->dbal->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
    }

    /** An OCP query builder whose calls are forwarded to a Doctrine one on the SQLite connection. */
    private function builder(): IQueryBuilder {
        $inner = $this->dbal->createQueryBuilder();
        $qb = $this->mock(IQueryBuilder::class);
        $chain = static function (string $method) use ($qb, $inner): void {
            $qb->method($method)->willReturnCallback(function (...$args) use ($qb, $inner, $method): IQueryBuilder {
                $args = array_map(static fn ($arg) => $arg instanceof \OCP\DB\QueryBuilder\IQueryFunction ? (string)$arg : $arg, $args);
                $inner->{$method}(...$args);
                return $qb;
            });
        };
        foreach (['select', 'from', 'insert', 'update', 'delete', 'where', 'andWhere', 'set', 'setValue', 'setMaxResults', 'orderBy'] as $method) {
            $chain($method);
        }
        $qb->method('createNamedParameter')->willReturnCallback(
            fn ($value, $type = IQueryBuilder::PARAM_STR): string => $inner->createNamedParameter($value, $type));
        $qb->method('createFunction')->willReturnCallback(function (string $expression) {
            $function = $this->mock(\OCP\DB\QueryBuilder\IQueryFunction::class);
            $function->method('__toString')->willReturn($expression);
            return $function;
        });
        $qb->method('expr')->willReturnCallback(fn (): IExpressionBuilder => $this->expressions($inner));
        $qb->method('executeStatement')->willReturnCallback(function () use ($inner): int {
            if ($this->beforeStatement !== null) {
                $hook = $this->beforeStatement;
                $this->beforeStatement = null;
                $hook();
            }
            try {
                return (int)$inner->executeStatement();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // The official connection translates driver errors into OCP database exceptions.
                throw new class($e->getMessage(), 0, $e) extends \OCP\DB\Exception {
                    public function getReason(): ?int {
                        return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
                    }
                };
            }
        });
        $qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->result($inner));
        return $qb;
    }

    private function expressions(QueryBuilder $inner): IExpressionBuilder {
        $expr = $this->mock(IExpressionBuilder::class);
        foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte'] as $method) {
            $expr->method($method)->willReturnCallback(fn (string $x, $y): string => $inner->expr()->{$method}($x, (string)$y));
        }
        $expr->method('isNull')->willReturnCallback(fn (string $x): string => $inner->expr()->isNull($x));
        $expr->method('isNotNull')->willReturnCallback(fn (string $x): string => $inner->expr()->isNotNull($x));
        return $expr;
    }

    private function result(QueryBuilder $inner): IResult {
        $rows = $inner->executeQuery()->fetchAllAssociative();
        $result = $this->mock(IResult::class);
        $result->method('fetch')->willReturnCallback(function () use (&$rows) {
            return $rows === [] ? false : array_shift($rows);
        });
        $result->method('fetchAll')->willReturnCallback(function () use (&$rows): array {
            [$all, $rows] = [$rows, []];
            return $all;
        });
        $result->method('closeCursor')->willReturn(true);
        return $result;
    }

    private function mock(string $interface): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $interface);
    }
}
