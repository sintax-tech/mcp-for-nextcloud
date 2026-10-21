<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The admin routes behind the global policy revision (contracts §2.3; validation T1, T3, T4): every write names the
 * revision it was built from, stale or missing revisions write nothing, a command is all or nothing, and an undo
 * restores the exact raw values of its own receipt. Atomicity under a failure inside the transaction needs the real
 * config and is proven by the integration suite; here the database is a mock without transactions.
 */
final class GrantsRevisionTest extends TestCase {
    private MatrixFixture $fx;
    /** @var array<string, mixed> JSON body parameters seen through IRequest::getParam */
    private array $body = [];

    protected function setUp(): void {
        $this->fx = new MatrixFixture($this);
        foreach (['ana', 'bob', 'carl'] as $uid) {
            $this->fx->addUser($uid, ucfirst($uid));
        }
    }

    /** A request with this body; expectedRevision is the current one unless the body names it (null omits it). */
    private function controller(array $body = [], string $admin = 'root'): GrantsController {
        if (!array_key_exists('expectedRevision', $body)) {
            $body['expectedRevision'] = $this->revision();
        }
        $this->body = array_filter($body, static fn ($value): bool => $value !== null);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $this->body) ? $this->body[$key] : $default);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($admin);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return new GrantsController('mcp', $request, $this->fx->matrix(), $this->fx->policy, $this->fx->userManager(),
            $this->fx->config->mock($this), $this->fx->logsAccess(), $this->fx->services()->grants, $this->fx->services()->service, $this->fx->services()->undo, $this->fx->state(), $session,
            $this->createMock(\OCA\Mcp\Access\Grants\Groups\GroupGrantsService::class), $this->fx->oauth, $this->fx->oauthClients());
    }

    /** Review F1 of B2: a re-read for the response that fails after the commit answers uncertain, with the receipt. */
    public function testAReReadThatFailsAfterTheCommitAnswersUncertainWithTheRevisionAndReceipt(): void {
        $failing = $this->createMock(\OCA\Mcp\Access\Grants\GrantMatrix::class);
        $failing->method('rows')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable('Resource scopes unavailable'));
        $failing->method('groups')->willThrowException(new \RuntimeException('Groups unavailable'));
        $policy = $this->createMock(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        $policy->method('globalEnabled')->willThrowException(new \RuntimeException('Config unavailable'));
        $build = function (array $body) use ($failing, $policy): GrantsController {
            $body['expectedRevision'] = $this->revision();
            $request = $this->createMock(IRequest::class);
            $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $body[$key] ?? $default);
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('root');
            $session = $this->createMock(IUserSession::class);
            $session->method('getUser')->willReturn($user);
            return new GrantsController('mcp', $request, $failing, $policy, $this->fx->userManager(),
                $this->fx->config->mock($this), $this->fx->logsAccess(), $this->fx->services()->grants, $this->fx->services()->service, $this->fx->services()->undo, $this->fx->state(), $session,
                $this->createMock(\OCA\Mcp\Access\Grants\Groups\GroupGrantsService::class), $this->fx->oauth, $this->fx->oauthClients());
        };

        $revision = $this->revision();
        $update = $build(['eligible' => true])->update('ana');
        self::assertStatus(503, $update);
        $this->assertSame(['error' => 'Unavailable', 'uncertain' => true, 'revision' => (string)((int)$revision + 1)], array_diff_key($update->getData(), ['receiptId' => 1]));
        $this->assertIsString($update->getData()['receiptId']);
        $this->assertSame('1', $this->raw('ana')['eligible'], 'the cell is saved');

        $service = $build(['enabled' => false])->service();
        self::assertStatus(503, $service);
        $this->assertSame(['error' => 'Unavailable', 'uncertain' => true, 'revision' => $this->revision()], $service->getData());

        $logs = $build(['groups' => ['sales'], 'previous' => [], 'version' => 0])->updateLogsAccess();
        self::assertStatus(503, $logs);
        $this->assertTrue($logs->getData()['uncertain']);
    }

    private function revision(): string {
        return (string)$this->fx->config->revision;
    }

    /** @return array<string, string> the raw mcp preferences of a user */
    private function raw(string $uid): array {
        return $this->fx->config->user[$uid]['mcp'] ?? [];
    }

    private static function assertStatus(int $status, JSONResponse $response): void {
        self::assertSame($status, $response->getStatus(), json_encode($response->getData()));
    }

    /** @return array<string, mixed> one command of POST /api/grants/commands */
    private static function command(string $uid, ?string $module, string $operation, string $state): array {
        return ['uid' => $uid, 'module' => $module, 'operation' => $operation, 'state' => $state];
    }

    public function testNewRoutesAreAdminOnlyAndCsrfProtectedLikeTheOthers(): void {
        foreach (['commands', 'undo'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(GrantsController::class, $method))->getAttributes(), $method);
        }
        $routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
        $this->assertContains(['name' => 'grants#commands', 'url' => '/api/grants/commands', 'verb' => 'POST'], $routes);
        $this->assertContains(['name' => 'grants#undo', 'url' => '/api/grants/undo', 'verb' => 'POST'], $routes);
    }

    public function testReadsCarryTheRevisionAsADecimalString(): void {
        $this->assertSame('0', $this->controller()->index('', '', 1)->getData()['revision']);
        $this->assertSame('0', $this->controller()->logsAccess()->getData()['revision']);
        $this->fx->config->revision = 9007199254740993; // above Number.MAX_SAFE_INTEGER: the page never parses it
        $this->assertSame('9007199254740993', $this->controller()->index('', '', 1)->getData()['revision']);
    }

    /** @return array<string, array{string, array<string, mixed>}> every policy write route with a valid body */
    public static function writes(): array {
        return [
            'update' => ['update', ['eligible' => true]],
            'bulk' => ['bulk', ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'edit', 'granted' => true]],
            'commands' => ['commands', ['commands' => [['uid' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'state' => 'allow']]]],
            'service' => ['service', ['enabled' => true]],
            'logs' => ['updateLogsAccess', ['groups' => ['sales'], 'previous' => [], 'version' => 0]],
        ];
    }

    private function call(GrantsController $controller, string $route): JSONResponse {
        return $route === 'update' ? $controller->update('ana') : $controller->{$route}();
    }

    private function snapshot(): array {
        return [$this->fx->config->user, $this->fx->config->app, $this->fx->config->revision, $this->fx->config->events, $this->fx->logsAccess()->state()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function testMissingRevisionIs428AndAMalformedOneIs400WithoutWriting(string $route, array $body): void {
        $before = $this->snapshot();
        $response = $this->call($this->controller(['expectedRevision' => null] + $body), $route);
        $this->assertSame([428, ['error' => 'PreconditionRequired']], [$response->getStatus(), $response->getData()]);
        foreach ([0, '01', '-1', ' 0', '0x0', '9223372036854775807', 'zero'] as $bad) {
            self::assertStatus(400, $this->call($this->controller(['expectedRevision' => $bad] + $body), $route));
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function testAStaleRevisionIs409WithTheFreshRevisionAndWritesNothing(string $route, array $body): void {
        // Worker A and B read revision 0; A saves, then B saves with what it read.
        $this->fx->policy->setGrant('bob', 'deck', 'edit', true);
        $before = $this->snapshot();
        $response = $this->call($this->controller(['expectedRevision' => '0'] + $body), $route);
        self::assertStatus(409, $response);
        $this->assertSame(['error' => 'Conflict', 'revision' => '1'], array_intersect_key($response->getData(), ['error' => 1, 'revision' => 1]));
        $this->assertArrayHasKey('state', $response->getData());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('1', $this->raw('bob')['grant_deck_edit'], 'the bytes of A stay');
    }

    public function testAnOldPageThatReloadedSavesAgainWithTheRevisionItGot(): void {
        $response = $this->controller(['expectedRevision' => '0', 'eligible' => true])->update('ana');
        $this->assertSame(['1', true], [$response->getData()['revision'], $response->getData()['eligible']]);
        // The same revision once more is stale, even for another target (ABA on a different cell).
        self::assertStatus(409, $this->controller(['expectedRevision' => '0', 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->update('bob'));
        self::assertStatus(200, $this->controller(['expectedRevision' => '1', 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->update('bob'));
    }

    public function testConflictStateHoldsTheFreshRowsOfTheUsersInTheRequest(): void {
        $this->fx->policy->setEligible('ana', true);
        $data = $this->controller(['expectedRevision' => '0', 'uids' => ['ana', 'bob'], 'module' => null, 'operation' => 'eligible', 'granted' => false])->bulk()->getData();
        $this->assertSame(['ana', 'bob'], array_column($data['state']['users'], 'uid'));
        $this->assertTrue($data['state']['users'][0]['eligible']);
    }

    public function testBooleansKeepAllowAndDenyAndNeverInherit(): void {
        $this->controller(['module' => 'notes', 'operation' => 'edit', 'granted' => true])->update('ana');
        $this->assertSame('1', $this->raw('ana')['grant_notes_edit']);
        $this->controller(['uids' => ['ana', 'bob'], 'module' => 'notes', 'operation' => 'edit', 'granted' => false])->bulk();
        $this->assertSame(['0', '0'], [$this->raw('ana')['grant_notes_edit'], $this->raw('bob')['grant_notes_edit']]);
        // Absent with a false default is still a real change to an explicit deny.
        $this->controller(['module' => 'calendar', 'operation' => 'edit', 'granted' => false])->update('carl');
        $this->assertSame('0', $this->raw('carl')['grant_calendar_edit']);
    }

    public function testCommandsWriteManyUsersAndOperationsAsOneRevisionAndOneReceipt(): void {
        $this->fx->config->user['bob']['mcp']['grant_deck_move'] = '1';
        $response = $this->controller(['commands' => [
            self::command('ana', null, 'eligible', 'allow'),
            self::command('ana', 'notes', 'edit', 'deny'),
            self::command('bob', 'deck', 'move', 'inherit'),
            self::command('bob', 'deck', 'move', 'inherit'),
            self::command('carl', 'files', 'read', 'deny'),
        ]])->commands();
        self::assertStatus(200, $response);
        $this->assertSame('1', $response->getData()['revision']);
        $this->assertSame(4, $response->getData()['changed']);
        $this->assertSame(['eligible' => '1', 'grant_notes_edit' => '0'], $this->raw('ana'));
        $this->assertArrayNotHasKey('grant_deck_move', $this->raw('bob'));
        $receipt = $this->fx->config->events[$response->getData()['receiptId']];
        $this->assertSame(PolicyEventsMapper::KIND_RECEIPT, $receipt['kind']);
        $this->assertSame([
            ['uid' => 'ana', 'key' => 'eligible', 'before' => null, 'after' => '1'],
            ['uid' => 'ana', 'key' => 'grant_notes_edit', 'before' => null, 'after' => '0'],
            ['uid' => 'bob', 'key' => 'grant_deck_move', 'before' => '1', 'after' => null],
            ['uid' => 'carl', 'key' => 'grant_files_read', 'before' => null, 'after' => '0'],
        ], $receipt['payload']['changes']);
        $this->assertSame(['root', 'grants.commands', '0', '1'], [$receipt['payload']['actor'], $receipt['payload']['action'],
            $receipt['payload']['fromRevision'], $receipt['payload']['revision']]);
        $audits = array_values(array_filter($this->fx->config->events, static fn (array $e): bool => $e['kind'] === PolicyEventsMapper::KIND_AUDIT));
        $this->assertCount(1, $audits);
        $this->assertSame([$response->getData()['receiptId'], []], [$audits[0]['payload']['receiptId'], $audits[0]['payload']['changes']]);
    }

    public function testACommandOfOnlyNoOpsAdvancesNothingAndHasNoReceipt(): void {
        $this->fx->config->user['ana']['mcp']['grant_notes_edit'] = '1';
        $data = $this->controller(['commands' => [self::command('ana', 'notes', 'edit', 'allow'), self::command('bob', 'notes', 'edit', 'inherit')]])->commands()->getData();
        $this->assertSame(['revision' => '0', 'changed' => 0, 'receiptId' => null], $data);
        $this->assertSame([], $this->fx->config->events);
    }

    /** @return array<string, array{list<mixed>}> */
    public static function badCommands(): array {
        $cells = static fn (int $n, int $users): array => array_map(static fn (int $i): array => self::command('u' . ($i % $users), 'notes', 'edit', 'allow'), range(1, $n));
        return [
            'no command' => [[]],
            'not a list' => [['a' => self::command('ana', 'notes', 'edit', 'allow')]],
            'a boolean granted' => [[self::command('ana', 'notes', 'edit', 'allow') + ['granted' => true]]],
            'granted instead of state' => [[['uid' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'granted' => true]]],
            'mixed forms across cells' => [[self::command('ana', 'notes', 'edit', 'allow'), ['uid' => 'bob', 'module' => 'notes', 'operation' => 'edit', 'granted' => false]]],
            'unknown state' => [[self::command('ana', 'notes', 'edit', 'yes')]],
            'boolean state' => [[['uid' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'state' => true]]],
            'unknown operation' => [[self::command('ana', 'files', 'delete', 'allow')]],
            'eligible with a module' => [[self::command('ana', 'notes', 'eligible', 'allow')]],
            'grant without module' => [[self::command('ana', null, 'edit', 'allow')]],
            'module key left out' => [[['uid' => 'ana', 'operation' => 'eligible', 'state' => 'allow']]],
            'same cell, two states' => [[self::command('ana', 'notes', 'edit', 'allow'), self::command('ana', 'notes', 'edit', 'deny')]],
            'last user unknown' => [[self::command('ana', 'notes', 'edit', 'allow'), self::command('ghost', 'notes', 'edit', 'allow')]],
            'numeric uid' => [[['uid' => 7, 'module' => 'notes', 'operation' => 'edit', 'state' => 'allow']]],
            '51 users' => [$cells(51, 51)],
            '2501 cells' => [$cells(2501, 50)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCommands')]
    public function testAnInvalidCommandIsRefusedWithoutAnyWrite(array $commands): void {
        foreach (range(0, 50) as $i) {
            $this->fx->addUser('u' . $i, 'U' . $i);
        }
        $before = $this->snapshot();
        self::assertStatus(400, $this->controller(['commands' => $commands])->commands());
        $this->assertSame($before, $this->snapshot());
    }

    public function testFiftyUsersInOneCommandAreOneCommit(): void {
        $commands = [];
        foreach (range(1, 50) as $i) {
            $this->fx->addUser('u' . $i, 'U' . $i);
            foreach (['read', 'edit', 'create'] as $operation) {
                $commands[] = self::command('u' . $i, 'notes', $operation, 'deny');
            }
        }
        $data = $this->controller(['commands' => $commands])->commands()->getData();
        $this->assertSame(['1', 150], [$data['revision'], $data['changed']]);
        $this->assertCount(150, $this->fx->config->events[$data['receiptId']]['payload']['changes']);
    }

    public function testFiftyOneUsersInTheOldBulkAreRefused(): void {
        foreach (range(1, 51) as $i) {
            $this->fx->addUser('u' . $i, 'U' . $i);
        }
        self::assertStatus(400, $this->controller(['uids' => array_map(static fn (int $i): string => 'u' . $i, range(1, 51)), 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->bulk());
        $this->assertSame('0', $this->revision());
    }

    private function undo(string $receiptId, string $revision, string $admin = 'root'): JSONResponse {
        return $this->controller(['receiptId' => $receiptId, 'expectedRevision' => $revision], $admin)->undo();
    }

    public function testUndoRestoresAbsenceWhereThereWasNoValueAndTheOldValueElsewhere(): void {
        $this->fx->config->user['bob']['mcp']['grant_notes_edit'] = '0';
        $saved = $this->controller(['uids' => ['ana', 'bob'], 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->bulk()->getData();
        $this->assertSame(['1', '1'], [$this->raw('ana')['grant_notes_edit'], $this->raw('bob')['grant_notes_edit']]);

        $undone = $this->undo($saved['receiptId'], $saved['revision']);
        self::assertStatus(200, $undone);
        $this->assertSame(['revision' => '2', 'changed' => 2], $undone->getData());
        $this->assertArrayNotHasKey('grant_notes_edit', $this->raw('ana'), 'absent again, not a stored deny');
        $this->assertSame('0', $this->raw('bob')['grant_notes_edit']);
        $audit = array_values(array_filter($this->fx->config->events, static fn (array $e): bool => $e['payload']['action'] === PolicyEventPayload::ACTION_UNDO));
        $this->assertSame($saved['receiptId'], $audit[0]['payload']['undoOf']);
        // The undo moved the revision, so the receipt cannot be applied twice.
        self::assertStatus(409, $this->undo($saved['receiptId'], $saved['revision']));
    }

    public function testAnyLaterWriteBlocksTheUndoAndTouchesNothing(): void {
        $saved = $this->controller(['commands' => [self::command('ana', 'notes', 'edit', 'allow')]])->commands()->getData();
        $this->controller(['module' => 'deck', 'operation' => 'read', 'granted' => false])->update('carl');
        $before = $this->snapshot();
        $response = $this->undo($saved['receiptId'], $saved['revision']);
        self::assertStatus(409, $response);
        $this->assertSame('2', $response->getData()['revision']);
        // The state holds the current rows of the receipt's users, like any other conflict.
        $this->assertSame(['ana'], array_column($response->getData()['state']['users'], 'uid'));
        $this->assertTrue($response->getData()['state']['users'][0]['grants']['notes']['edit']);
        // A client that sends the newest revision instead of the receipt's is refused the same way.
        self::assertStatus(409, $this->undo($saved['receiptId'], '2'));
        $this->assertSame($before, $this->snapshot());
    }

    public function testForeignInventedAndExpiredReceiptsAreRefused(): void {
        $saved = $this->controller(['commands' => [self::command('ana', 'notes', 'edit', 'allow')]])->commands()->getData();
        $before = $this->snapshot();
        $this->assertSame([404, ['error' => 'NotFound']], [$this->undo($saved['receiptId'], $saved['revision'], 'other-admin')->getStatus(),
            $this->undo($saved['receiptId'], $saved['revision'], 'other-admin')->getData()]);
        foreach ([str_repeat('a', 32) . '-1', 'not-an-id', ''] as $invented) {
            self::assertStatus(404, $this->undo($invented, $saved['revision']));
        }
        self::assertStatus(400, $this->controller(['receiptId' => 7])->undo());
        self::assertStatus(428, $this->controller(['receiptId' => $saved['receiptId'], 'expectedRevision' => null])->undo());
        $this->fx->config->now += \OCA\Mcp\Access\Policy\PolicyLimits::RECEIPT_TTL;
        $this->assertSame([410, ['error' => 'Gone']], [$this->undo($saved['receiptId'], $saved['revision'])->getStatus(),
            $this->undo($saved['receiptId'], $saved['revision'])->getData()]);
        // Ownership is checked first: another administrator cannot learn that the receipt exists, expired or not.
        self::assertStatus(404, $this->undo($saved['receiptId'], $saved['revision'], 'other-admin'));
        $this->assertSame($before, $this->snapshot());
    }

    public function testAReceiptIdNeverTakesBeforeValuesFromTheClient(): void {
        $saved = $this->controller(['commands' => [self::command('ana', 'notes', 'edit', 'allow')]])->commands()->getData();
        $this->body = [];
        $response = $this->controller(['receiptId' => $saved['receiptId'], 'expectedRevision' => $saved['revision'],
            'changes' => [['uid' => 'ana', 'key' => 'grant_notes_edit', 'before' => '1']]])->undo();
        self::assertStatus(200, $response);
        $this->assertArrayNotHasKey('grant_notes_edit', $this->raw('ana'));
    }

    public function testUndoWithTheServiceOffRestoresRawValuesNotDeny(): void {
        // The service is off (never enabled): the receipt holds raw values, not effective decisions.
        $saved = $this->controller(['commands' => [self::command('ana', 'notes', 'create', 'allow')]])->commands()->getData();
        $this->assertSame([['uid' => 'ana', 'key' => 'grant_notes_create', 'before' => null, 'after' => '1']],
            $this->fx->config->events[$saved['receiptId']]['payload']['changes']);
        self::assertStatus(200, $this->undo($saved['receiptId'], $saved['revision']));
        $this->assertArrayNotHasKey('grant_notes_create', $this->raw('ana'), 'absent again, not a deny');
    }

    public function testUndoDoesNotRestoreRevokedConnections(): void {
        $this->fx->policy->setEligible('ana', true);
        $this->fx->oauth->insertToken(['user_id' => 'ana'], 0);
        $saved = $this->controller(['uids' => ['ana'], 'module' => null, 'operation' => 'eligible', 'granted' => false])->bulk()->getData();
        $this->assertSame([], $this->fx->oauth->tokens);
        self::assertStatus(200, $this->undo($saved['receiptId'], $saved['revision']));
        $this->assertSame('1', $this->raw('ana')['eligible']);
        $this->assertSame([], $this->fx->oauth->tokens, 'the token stays revoked');
    }

    public function testUndoOfAnAllowFromAbsentEligibilityRevokesLikeAnyLoss(): void {
        $saved = $this->controller(['commands' => [self::command('ana', null, 'eligible', 'allow')]])->commands()->getData();
        $this->fx->oauth->insertToken(['user_id' => 'ana'], 0);
        // A token issued during the window, before the undo: losing eligibility again revokes it.
        self::assertStatus(200, $this->undo($saved['receiptId'], $saved['revision']));
        $this->assertArrayNotHasKey('eligible', $this->raw('ana'));
        $this->assertSame([], $this->fx->oauth->tokens);
    }

    public function testUndoOfAReceiptWhoseUserWasDeletedIsAConflict(): void {
        $saved = $this->controller(['commands' => [self::command('bob', 'notes', 'edit', 'allow')]])->commands()->getData();
        unset($this->fx->users['bob']);
        self::assertStatus(409, $this->undo($saved['receiptId'], $saved['revision']));
        $this->assertSame('1', $this->raw('bob')['grant_notes_edit']);
    }

    public function testTheServiceSwitchIsAuditedAndNoOpWhenRepeated(): void {
        $this->assertSame('1', $this->controller(['enabled' => true])->service()->getData()['revision']);
        $this->assertSame('1', $this->controller(['enabled' => true])->service()->getData()['revision']);
        $audits = array_values($this->fx->config->events);
        $this->assertCount(1, $audits);
        $this->assertSame([['uid' => null, 'key' => 'service_enabled', 'before' => null, 'after' => '1']], $audits[0]['payload']['changes']);
    }

    public function testLogsAccessChecksTheListVersionAndThePolicyRevisionInOneGate(): void {
        $saved = $this->controller(['groups' => ['sales'], 'previous' => [], 'version' => 0])->updateLogsAccess();
        self::assertStatus(200, $saved);
        $this->assertSame(['1', 1], [$saved->getData()['revision'], $saved->getData()['version']], 'a role change advances the global revision');
        // The right list version with a stale policy revision is still a conflict, with the current state.
        $this->fx->policy->setGrant('bob', 'logs', 'read', true);
        $stale = $this->controller(['groups' => [], 'previous' => ['sales'], 'version' => 1, 'expectedRevision' => '1'])->updateLogsAccess();
        self::assertStatus(409, $stale);
        $this->assertSame(['2', ['sales']], [$stale->getData()['revision'], $stale->getData()['state']['groups']]);
        // And a stale list version with the current revision too.
        self::assertStatus(409, $this->controller(['groups' => [], 'previous' => ['sales'], 'version' => 0])->updateLogsAccess());
        $this->assertSame(['sales'], $this->fx->logsAccess()->groups());
        $audit = array_values(array_filter($this->fx->config->events, static fn (array $e): bool => $e['payload']['action'] === PolicyEventPayload::ACTION_LOGS));
        $this->assertSame([['uid' => null, 'key' => 'logs_groups', 'before' => '[]', 'after' => '["sales"]']], $audit[0]['payload']['changes']);
    }

    public function testStorageFailuresAre503AndSayWhetherTheOutcomeIsUncertain(): void {
        $state = $this->fx->state();
        $transaction = $this->createMock(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class);
        $transaction->method('runAdmin')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable('lost', true));
        $writer = new \OCA\Mcp\Access\Grants\Commands\GrantCommands($transaction, $this->fx->services()->mutations,
            new \OCA\Mcp\Access\Grants\Commands\GrantInheritance(), new \OCA\Mcp\Access\Policy\PolicyUsers($this->fx->userManager()));
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ['eligible' => true, 'expectedRevision' => '0'][$key] ?? $default);
        $session = $this->createMock(IUserSession::class);
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('root');
        $session->method('getUser')->willReturn($admin);
        $controller = new GrantsController('mcp', $request, $this->fx->matrix(), $this->fx->policy, $this->fx->userManager(),
            $this->fx->config->mock($this), $this->fx->logsAccess(), $writer, $this->fx->services()->service, $this->fx->services()->undo, $state, $session,
            $this->createMock(\OCA\Mcp\Access\Grants\Groups\GroupGrantsService::class), $this->fx->oauth, $this->fx->oauthClients());
        $response = $controller->update('ana');
        $this->assertSame([503, ['error' => 'Unavailable', 'uncertain' => true]], [$response->getStatus(), $response->getData()]);
    }
}
