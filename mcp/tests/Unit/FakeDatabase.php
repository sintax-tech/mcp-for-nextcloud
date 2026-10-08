<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A database for the production stores to run against: OCP's query builder answered from arrays, so the
 * conditional UPDATE/DELETE statements that decide single use and ownership are executed and not skipped.
 *
 * It understands the operators the stores use (eq, lt, lte, gt, gte, isNull, iLike, orX), ordering, paging,
 * count, transactions with rollback, and an auto-increment `id` for the tables listed in the constructor.
 * Whatever the stores ask for beyond that fails loudly, so a new statement cannot go untested by accident.
 */
final class FakeDatabase {
    /** @var array<string, list<array<string, mixed>>> rows by table, keys preserved so deletes leave the others in place */
    public array $tables;
    /** @var list<array{string, string}> every executed statement as [operation, table] */
    public array $statements = [];
    /** @var array<string, string> message of the exception an insert into the table throws */
    public array $failInsert = [];
    /** @var (callable(string, string): void)|null called before each update or delete with [operation, table], to play a race */
    public $beforeWrite = null;
    private ?array $snapshot = null;
    /** @var array<string, int> */
    private array $nextId = [];

    /**
     * @param array<string, list<array<string, mixed>>> $tables table name => initial rows
     * @param list<string> $autoIncrement tables whose inserts get an increasing `id`
     * @param array<string, array<string, mixed>> $defaults columns a table fills when an insert leaves them out (a nullable column reads back as null)
     */
    public function __construct(private TestCase $test, array $tables, private array $autoIncrement = [], private array $defaults = []) {
        $this->tables = $tables;
    }

    /** @return bool whether a transaction is open */
    public function inTransaction(): bool {
        return $this->snapshot !== null;
    }

    public function connection(): IDBConnection {
        $db = $this->mock(IDBConnection::class);
        $db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());
        $db->method('beginTransaction')->willReturnCallback(function (): void {
            $this->snapshot = $this->tables;
        });
        $db->method('commit')->willReturnCallback(function (): void {
            $this->snapshot = null;
        });
        $db->method('rollBack')->willReturnCallback(function (): void {
            $this->tables = $this->snapshot ?? $this->tables;
            $this->snapshot = null;
        });
        return $db;
    }

    /** @template T of object @param class-string<T> $class @return T&\PHPUnit\Framework\MockObject\MockObject */
    private function mock(string $class): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
    }

    private function builder(): IQueryBuilder {
        $qb = $this->mock(IQueryBuilder::class);
        $state = (object)['table' => '', 'operation' => '', 'values' => [], 'conditions' => [], 'params' => [],
            'columns' => ['*'], 'order' => [], 'limit' => null, 'offset' => 0, 'count' => false];
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use ($state): string {
            $key = ':p' . count($state->params);
            $state->params[$key] = $value;
            return $key;
        });
        $expr = $this->mock(IExpressionBuilder::class);
        foreach (['eq', 'lt', 'lte', 'gt', 'gte', 'iLike'] as $op) {
            $expr->method($op)->willReturnCallback(fn ($column, $param) => json_encode([$op, $column, $state->params[$param]]));
        }
        $expr->method('isNull')->willReturnCallback(fn ($column) => json_encode(['isNull', $column, null]));
        $composites = new \SplObjectStorage();
        $expr->method('orX')->willReturnCallback(function (...$parts) use ($composites) {
            $composite = $this->mock(ICompositeExpression::class);
            $composites[$composite] = ['or', array_map(fn ($p) => json_decode($p, true), $parts)];
            return $composite;
        });
        $func = $this->mock(IFunctionBuilder::class);
        $func->method('count')->willReturnCallback(function () use ($state) {
            $state->count = true;
            return $this->mock(IQueryFunction::class);
        });
        $qb->method('func')->willReturn($func);
        $qb->method('expr')->willReturn($expr);
        foreach (['delete', 'update', 'insert', 'from'] as $op) {
            $qb->method($op)->willReturnCallback(function ($table) use ($state, $op, $qb) {
                $state->table = $table;
                $state->operation = $op;
                return $qb;
            });
        }
        foreach (['set', 'setValue'] as $method) {
            $qb->method($method)->willReturnCallback(function ($column, $param) use ($state, $qb) {
                $state->values[$column] = $state->params[$param];
                return $qb;
            });
        }
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(function ($condition) use ($state, $qb, $composites) {
                $state->conditions[] = is_object($condition) ? $composites[$condition] : json_decode($condition, true);
                return $qb;
            });
        }
        $holds = static function (array $condition, array $row) use (&$holds): bool {
            if ($condition[0] === 'or') {
                return array_filter($condition[1], fn (array $part) => $holds($part, $row)) !== [];
            }
            [$op, $column, $value] = $condition;
            $actual = $row[$column] ?? null;
            return match ($op) {
                'eq' => $actual === $value,
                'lt' => $actual !== null && $actual < $value,
                'lte' => $actual !== null && $actual <= $value,
                'gt' => $actual !== null && $actual > $value,
                'gte' => $actual !== null && $actual >= $value,
                'isNull' => $actual === null,
                'iLike' => preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote(stripcslashes(str_replace(['\\%', '\\_'], ["\x01", "\x02"], $value)), '/')) . '$/i',
                    str_replace(["\x01", "\x02"], ['%', '_'], (string)$actual)) === 1,
            };
        };
        $matches = static function (array $row) use ($state, $holds): bool {
            foreach ($state->conditions as $condition) {
                if (!$holds($condition, $row)) {
                    return false;
                }
            }
            return true;
        };
        $qb->method('select')->willReturnCallback(function (...$columns) use ($state, $qb) {
            $state->columns = $columns;
            return $qb;
        });
        $qb->method('setMaxResults')->willReturnCallback(function ($limit) use ($state, $qb) {
            $state->limit = $limit;
            return $qb;
        });
        $qb->method('setFirstResult')->willReturnCallback(function ($offset) use ($state, $qb) {
            $state->offset = $offset;
            return $qb;
        });
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(function ($column, $direction = 'ASC') use ($state, $qb) {
                $state->order[] = [$column, strtoupper((string)$direction)];
                return $qb;
            });
        }
        $qb->method('executeQuery')->willReturnCallback(function () use ($state, $matches): IResult {
            $rows = array_values(array_filter($this->tables[$state->table], $matches));
            usort($rows, static function (array $a, array $b) use ($state): int {
                foreach ($state->order as [$column, $direction]) {
                    $cmp = ($a[$column] ?? null) <=> ($b[$column] ?? null);
                    if ($cmp !== 0) {
                        return $direction === 'DESC' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
            $count = count($rows);
            $rows = array_slice($rows, $state->offset, $state->limit);
            if ($state->columns !== ['*'] && !$state->count) {
                $rows = array_map(static fn (array $row) => array_intersect_key($row, array_flip($state->columns)), $rows);
            }
            $result = $this->mock(IResult::class);
            $result->method('fetch')->willReturn($rows[0] ?? false);
            $result->method('fetchAll')->willReturn($rows);
            $result->method('fetchOne')->willReturn($count);
            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use ($state, $matches): int {
            $this->statements[] = [$state->operation, $state->table];
            if ($state->operation === 'insert') {
                if (isset($this->failInsert[$state->table])) {
                    throw new \RuntimeException($this->failInsert[$state->table]);
                }
                $row = $state->values + ($this->defaults[$state->table] ?? []);
                if (in_array($state->table, $this->autoIncrement, true) && !isset($row['id'])) {
                    $this->nextId[$state->table] = ($this->nextId[$state->table] ?? count($this->tables[$state->table])) + 1;
                    $row = ['id' => $this->nextId[$state->table]] + $row;
                }
                $this->tables[$state->table][] = $row;
                return 1;
            }
            if ($this->beforeWrite !== null) {
                ($this->beforeWrite)($state->operation, $state->table);
            }
            $count = 0;
            foreach ($this->tables[$state->table] as $key => $row) {
                if (!$matches($row)) {
                    continue;
                }
                $count++;
                if ($state->operation === 'delete') {
                    unset($this->tables[$state->table][$key]);
                } else {
                    $this->tables[$state->table][$key] = array_replace($row, $state->values);
                }
            }
            return $count;
        });
        return $qb;
    }
}
