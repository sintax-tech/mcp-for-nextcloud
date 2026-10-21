<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/** T19: actual engine deadlocks, with the production writer as victim after its revision CAS and outbox INSERT. */
final class RealDeadlockTest extends IntegrationTestCase {
    public static function survivorCommands(): array {
        return ['retry succeeds' => [false], 'survivor makes revision stale' => [true]];
    }

    #[DataProvider('survivorCommands')]
    public function testDeadlockVictimRollsBackAndSurvivorCommits(bool $advanceRevision): void {
        if ($this->cellDatabase() === 'sqlite') {
            $this->markTestSkipped('SQLite serializes writes; its busy case is covered by UserCausesTest.');
        }
        $db = $this->official();
        $observer = $this->openIndependentConnection();
        $state = new PolicyStateMapper($db);
        $before = $state->state();
        $uid = 'mcp-it-deadlock-' . bin2hex(random_bytes(6));
        $gid = $uid;
        $key = 'grant_notes_edit';
        $config = Server::get(IConfig::class);
        $users = Server::get(IUserConfig::class);
        $auth = new AuthUsersMapper($db);
        $generations = $auth->generations($uid);
        // PostgreSQL DELETE ignores a concurrently inserted row it cannot see. Seed a committed row first,
        // so both sessions contend on the same existing tuple rather than an uncommitted insert.
        $db->beginTransaction();
        (new GroupGrantsMapper($db))->replaceGroup($gid, ['notes' => ['create']]);
        $db->commit();
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/deadlock-worker.php', $gid, $advanceRevision ? '1' : '0', $this->sessionId($db)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 20);
        stream_set_blocking($pipes[2], false);
        $events = [];
        $errors = [];
        $attempts = 0;
        $commands = [];
        $workerDone = false;
        try {
            $this->assertSame("locked\n", fgets($pipes[1]), 'worker must hold group rows before the writer starts: ' . stream_get_contents($pipes[2]));
            if ($this->cellDatabase() === 'pgsql') {
                $db->executeStatement("SET statement_timeout = '15s'");
            } else {
                $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 15');
            }
            $collide = function () use ($db, $gid, $pipes, &$errors, &$workerDone): void {
                fwrite($pipes[0], "cycle\n");
                fflush($pipes[0]);
                try {
                    (new GroupGrantsMapper($db))->deleteGroup($gid);
                    $this->fail('The policy writer must be the deadlock victim');
                } catch (\OCP\DB\Exception $e) {
                    // Capture the real core/DBAL/driver chain, then rethrow the SAME exception to PolicyTransaction.
                    $chain = [];
                    for ($error = $e; $error !== null; $error = $error->getPrevious()) {
                        $chain[] = ['class' => $error::class, 'code' => $error->getCode(),
                            'sqlstate' => method_exists($error, 'getSQLState') ? $error->getSQLState() : null,
                            'message' => $error->getMessage()];
                    }
                    $errors[] = $chain;
                    $line = 'T19 real deadlock ' . $this->cellDatabase() . ': ' . json_encode($chain, JSON_UNESCAPED_SLASHES) . "\n";
                    fwrite(STDERR, $line);
                    file_put_contents('/tmp/mcp-it-observations.txt', $line, FILE_APPEND);
                    $this->assertSame("committed\n", fgets($pipes[1]), 'the other transaction must survive: ' . stream_get_contents($pipes[2]));
                    $workerDone = true;
                    throw $e;
                }
            };
            $mapper = new class($db, Server::get(ITimeFactory::class), $collide) extends PolicyEventsMapper {
                private bool $collided = false;
                public function __construct($db, $time, private \Closure $collide) { parent::__construct($db, $time); }
                public function append(string $id, string $kind, array $payload): void {
                    parent::append($id, $kind, $payload);
                    if (!$this->collided) {
                        $this->collided = true;
                        ($this->collide)();
                    }
                }
            };
            // Keep all official shared services/connections; only add an observation hook after outbox insertion.
            $writer = clone Server::get(PolicyTransaction::class);
            (new \ReflectionProperty($writer, 'events'))->setValue($writer, $mapper);
            try {
                $result = $writer->runAdmin($before['revision'], [$uid], function ($observed, $command) use (&$commands, &$attempts, $observer, $before, $generations, $uid, $key, &$events): void {
                    $commands[] = $command;
                    if ($attempts > 0) {
                        $this->assertSame($before['auth_epoch'], (new PolicyStateMapper($observer))->state()['auth_epoch']);
                        $this->assertSame($before['revision'], (new PolicyStateMapper($observer))->state()['revision']);
                        $this->assertNull((new PolicyEventsMapper($observer, Server::get(ITimeFactory::class)))->find($events[0]), 'victim outbox INSERT rolled back');
                        $this->assertSame($generations, (new AuthUsersMapper($observer))->generations($uid));
                        $this->assertSame([], Server::get(IUserConfig::class)->getValuesByUsers('mcp', $key, null, [$uid]));
                    }
                }, function ($snapshot, PolicyAttempt $attempt) use (&$attempts, &$events, $config, $uid, $key, $auth, $state): bool {
                $current = $attempt->state();
                $command = $attempt->commandId();
                $outbox = $attempt->outbox();
                    ++$attempts;
                    $config->setUserValue($uid, 'mcp', $key, '1');
                    $issued = $auth->issue($uid);
                    $auth->cut($uid, $issued);
                    $state->advanceAuthEpoch($current['auth_epoch']);
                    $events[] = $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit(null,
                        PolicyEventPayload::ACTION_UPDATE, $current['revision'], DecimalCounter::increment($current['revision']),
                        null, null, [['uid' => $uid, 'key' => $key, 'before' => null, 'after' => '1']]));
                    return true;
                });
                $this->assertFalse($advanceRevision, 'stale admin must receive a conflict');
                $this->assertTrue($result['changed']);
                $this->assertFalse($result['cleanupPending'], 'the successful audit was delivered');
                $this->assertSame(2, $attempts);
                $this->assertSame($commands[0], $commands[1], 'one logical command survives the retry');
                $this->assertSame($events[0], $events[1], 'outbox event id survives the retry');
            } catch (PolicyConflict) {
                $this->assertTrue($advanceRevision);
                $this->assertSame(1, $attempts, 'retry rejects the stale revision before applying');
            }
            $this->assertCount(1, $errors);
            $codes = array_column($errors[0], 'code');
            $states = array_column($errors[0], 'sqlstate');
            if ($this->cellDatabase() === 'mariadb') {
                $this->assertContains(1213, $codes);
            } else {
                $this->assertContains('40P01', $states);
            }
            $this->assertFalse($db->inTransaction());
            $after = (new PolicyStateMapper($observer))->state();
            $this->assertSame(DecimalCounter::increment($before['revision']), $after['revision'], 'only the survivor OR successful retry advances revision');
            $this->assertSame((string)((int)$before['lock_seq'] + ($advanceRevision ? 1 : 2)), $after['lock_seq'], 'victim mutex increment rolled back');
            $this->assertSame($advanceRevision ? $before['auth_epoch'] : DecimalCounter::increment($before['auth_epoch']), $after['auth_epoch']);
            $this->assertSame($advanceRevision ? $generations : ['issue_seq' => '2', 'revoked_below' => '2'], (new AuthUsersMapper($observer))->generations($uid));
            $users->clearCache($uid, false);
            $this->assertSame($advanceRevision ? [] : [$uid => '1'], $users->getValuesByUsers('mcp', $key, null, [$uid]));
            $this->assertSame([['groupId' => $gid, 'grants' => ['notes' => ['edit']]]], (new GroupGrantsMapper($observer))->forGroups([$gid]), 'survivor changed the committed grant from create to edit');
            $qb = $observer->getQueryBuilder();
            $query = $qb->select('id')->from('mcp_policy_events')->where($qb->expr()->eq('id', $qb->createNamedParameter($events[0])))->executeQuery();
            $rows = $query->fetchAll();
            $query->closeCursor();
            $this->assertCount($advanceRevision ? 0 : 1, $rows, 'no partial or duplicate outbox event');
        } finally {
            if (!$workerDone) {
                proc_terminate($process);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->executeStatement($this->cellDatabase() === 'pgsql' ? 'RESET statement_timeout' : 'SET SESSION innodb_lock_wait_timeout = DEFAULT');
            // Restore the global epoch so unrelated baseline OAuth fixtures remain valid for the rest of the suite.
            $qb = $db->getQueryBuilder();
            $qb->update('mcp_policy_state')->set('auth_epoch', $qb->createNamedParameter($before['auth_epoch']))->executeStatement();
            $config->deleteUserValue($uid, 'mcp', $key);
            $users->clearCache($uid, false);
            $qb = $db->getQueryBuilder();
            $qb->delete('mcp_auth_users')->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($uid))))->executeStatement();
            foreach (array_unique($events) as $event) {
                $qb = $db->getQueryBuilder();
                $qb->delete('mcp_policy_events')->where($qb->expr()->eq('id', $qb->createNamedParameter($event)))->executeStatement();
            }
            $db->beginTransaction();
            $groups = new GroupGrantsMapper($db);
            $groups->deleteGroup($gid);
            for ($i = 0; $i < 64; ++$i) {
                $groups->deleteGroup($gid . '-weight-' . $i);
            }
            $db->commit();
        }
    }
}
