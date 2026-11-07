<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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
	/** SELECT statements executed through the public app mapper surface (excludes schema setup). */
	public int $queryCount = 0;
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
		$db->method('beginTransaction')->willReturnCallback(fn () => $this->dbal->beginTransaction());
		$db->method('commit')->willReturnCallback(fn () => $this->dbal->commit());
		$db->method('rollBack')->willReturnCallback(fn () => $this->dbal->rollBack());
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->dbal->isTransactionActive());
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
				$args = array_map(self::sqlArgument(...), $args);
				$inner->{$method}(...$args);
				return $qb;
			});
		};
		foreach (['select', 'from', 'insert', 'update', 'delete', 'where', 'andWhere', 'set', 'setValue', 'setMaxResults', 'orderBy', 'addOrderBy', 'leftJoin', 'groupBy'] as $method) {
			$chain($method);
		}
		$qb->method('selectDistinct')->willReturnCallback(function ($columns) use ($qb, $inner): IQueryBuilder {
			$inner->select(...(array)$columns)->distinct();
			return $qb;
		});
		$qb->method('selectAlias')->willReturnCallback(function ($select, string $alias) use ($qb, $inner): IQueryBuilder {
			$inner->addSelect(self::sqlArgument($select) . ' AS ' . $alias);
			return $qb;
		});
		$qb->method('func')->willReturnCallback(function () {
			$functions = $this->mock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
			$functions->method('count')->willReturnCallback(function ($count = '', $alias = '') {
				$function = $this->mock(\OCP\DB\QueryBuilder\IQueryFunction::class);
				$function->method('__toString')->willReturn('COUNT(' . ($count === '' ? '*' : (string)self::sqlArgument($count)) . ')' . ($alias === '' ? '' : ' AS ' . $alias));
				return $function;
			});
			return $functions;
		});
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR, $placeHolder = null) use ($inner): IParameter {
			$parameter = $this->mock(IParameter::class);
			$parameter->method('__toString')->willReturn($inner->createNamedParameter($value, $type, $placeHolder));
			return $parameter;
		});
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
		$qb->method('executeQuery')->willReturnCallback(function () use ($inner): IResult {
			++$this->queryCount;
			return $this->result($inner);
		});
		return $qb;
	}

	private function expressions(QueryBuilder $inner): IExpressionBuilder {
		$expr = $this->mock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte'] as $method) {
			$expr->method($method)->willReturnCallback(fn ($x, $y): string => $inner->expr()->{$method}(
				(string)self::sqlArgument($x), (string)self::sqlArgument($y)));
		}
		$expr->method('andX')->willReturnCallback(fn (...$parts) => new class ((string)$inner->expr()->and(
			...array_map(static fn ($part): string => (string)self::sqlArgument($part), $parts))) implements \OCP\DB\QueryBuilder\ICompositeExpression {
			public function __construct(private string $sql) {}
			public function addMultiple(array $parts = []): \OCP\DB\QueryBuilder\ICompositeExpression { throw new \LogicException('Not modeled'); }
			public function add($part): \OCP\DB\QueryBuilder\ICompositeExpression { throw new \LogicException('Not modeled'); }
			public function count(): int { return 1; }
			public function getType(): string { return 'AND'; }
			public function __toString(): string { return $this->sql; }
		});
		$expr->method('isNull')->willReturnCallback(fn (string $x): string => $inner->expr()->isNull($x));
		$expr->method('isNotNull')->willReturnCallback(fn (string $x): string => $inner->expr()->isNotNull($x));
		$expr->method('in')->willReturnCallback(fn ($x, $values): string => $inner->expr()->in(
			(string)self::sqlArgument($x), self::sqlArgument($values)));
		return $expr;
	}

	/** Only the Doctrine boundary unwraps OCP SQL objects, including parameter lists in IN expressions. */
	private static function sqlArgument(mixed $value): mixed {
		if ($value instanceof IParameter || $value instanceof IQueryFunction || $value instanceof \OCP\DB\QueryBuilder\ICompositeExpression) {
			return (string)$value;
		}
		return is_array($value) ? array_map(self::sqlArgument(...), $value) : $value;
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
