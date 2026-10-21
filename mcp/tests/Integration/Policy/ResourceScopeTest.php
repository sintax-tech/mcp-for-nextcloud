<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use RuntimeException;

/**
 * Resource scope schema (issue #4, B1) on the real databases: both tables exist after fresh install and upgrade and
 * hold no row, the BIGINT revision and TEXT ids round-trip, and scopes written by the mapper commit or roll back with
 * the policy writer, seen from a second session. Ids are compared as stored bytes, never through the collation.
 */
final class ResourceScopeTest extends IntegrationTestCase {
    private string $uid = '';

    protected function setUp(): void {
        parent::setUp();
        $this->uid = 'mcp-it-scope-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void {
        $db = $this->official();
        foreach ([$this->uid, strtoupper($this->uid)] as $uid) {
            foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
                $qb = $db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($uid))))->executeStatement();
            }
        }
        parent::tearDown();
    }

    public function testTheTablesExistAndAreEmptyAfterInstallOrUpgrade(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $this->assertTrue($db->tableExists($table), $table);
        }
        // Nothing is materialized: an upgraded instance has every user unrestricted.
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'n'))->from($table)->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            $this->assertSame(0, $count, $table);
        }
        $this->assertSame([], Server::get(ResourceScopeMapper::class)->forUser($this->uid));
    }

    public function testScopesCommitOrRollBackWithTheWriterAndKeepExactIds(): void {
        $writer = Server::get(PolicyTransaction::class);
        $scopes = Server::get(ResourceScopeMapper::class);
        $other = new ResourceScopeMapper($this->openIndependentConnection());
        $saved = '';

        $command = function (bool $fail) use ($scopes, &$saved): \Closure {
            return function ($snapshot, PolicyAttempt $attempt) use ($fail, $scopes, &$saved): bool {
            $current = $attempt->state();
                $saved = $current['revision'];
                $scopes->replace($this->uid, 'files', ['42', DecimalCounter::MAX], $saved);
                $scopes->replace($this->uid, 'calendar', [], $saved);
                $scopes->replace($this->uid, 'tasks', ['42'], $saved);
                if ($fail) {
                    throw new RuntimeException('Injected after the scope writes');
                }
                return true;
            };
        };
        try {
            $writer->runInternal([], fn () => null, $command(true));
            $this->fail('Injected failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected after the scope writes', $e->getMessage());
        }
        $this->assertSame([], $other->forUser($this->uid));
        $this->assertSame([], $scopes->forUser($this->uid));

        $writer->runInternal([], fn () => null, $command(false));
        $expected = [
            'calendar' => ['policyRev' => $saved, 'items' => []],
            'files' => ['policyRev' => $saved, 'items' => ['42', DecimalCounter::MAX]],
            'tasks' => ['policyRev' => $saved, 'items' => ['42']],
        ];
        $this->assertSame($expected, $other->forUser($this->uid));
        $this->assertSame([], $other->forUser(strtoupper($this->uid)), 'ids are never case-folded');

        // A revision past the one read before a deletion keeps the scope; the cleanup removes only older ones.
        $removed = [];
        $writer->runInternal([], fn () => null, function () use ($scopes, $saved, &$removed): bool {
            $removed = $scopes->removeUpTo($this->uid, $saved);
            return $removed !== [];
        });
        $this->assertSame(['calendar', 'files', 'tasks'], $removed);
        $this->assertSame([], $other->forUser($this->uid));
    }

    public function testTheRevisionColumnHoldsTheLargestCounter(): void {
        $writer = Server::get(PolicyTransaction::class);
        $scopes = Server::get(ResourceScopeMapper::class);
        $writer->runInternal([], fn () => null, function () use ($scopes): bool {
            $scopes->replace($this->uid, 'files', ['1'], DecimalCounter::MAX);
            return false;
        });
        $this->assertSame(['policyRev' => DecimalCounter::MAX, 'items' => ['1']],
            (new ResourceScopeMapper($this->openIndependentConnection()))->forModule($this->uid, 'files'));
    }
}
