<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;

use OCA\Mcp\Controller\MyScopeController;
use OCA\Mcp\Controller\ScopeController;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** Admin scope routes and the personal read (contracts B.6, validation S8 and S13 on the routes). */
final class ScopeRoutesTest extends TestCase {
    private ScopeFixture $fx;
    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
    }

    /** @param array<string, mixed> $body request parameters */
    private function controller(array $body = [], bool $open = false, ?\OCA\Mcp\Db\ResourceScopeMapper $reads = null, ?\OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator $resources = null): ScopeController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $body[$key] ?? $default);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($this->fx->user('root'));
        $logger = new class ($this->logged) extends AbstractLogger {
            public function __construct(private array &$logged) {}
            public function log($level, \Stringable|string $message, array $context = []): void {
                $this->logged[] = [(string)$message, $context];
            }
        };
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000123);
        return new ScopeController('mcp', $request, $reads ?? $this->fx->scopes, $resources ?? $this->fx->enumerator(), $this->fx->commands($open, $resources), $this->fx->state,
            new ScopeActivation(['files' => $open, 'calendar' => $open, 'tasks' => $open]),
            $this->fx->userManager(), $session, $logger, $time);
    }

    private static function body(JSONResponse $response): array {
        return $response->getData();
    }

    /** @return array<string, array{string}> */
    public static function modules(): array {
        return ['files' => ['files'], 'calendar' => ['calendar'], 'tasks' => ['tasks']];
    }

    /** F1 over HTTP: behind a closed barrier, the PUT of every module is refused with no write, receipt or revision. */
    #[DataProvider('modules')]
    public function testAClosedPutIsRefusedForEveryModuleWithoutAnyWrite(string $module): void {
        $before = $this->fx->snapshot();
        foreach ([['mode' => 'restricted', 'items' => []], ['mode' => 'unrestricted']] as $body) {
            $response = $this->controller($body + ['expectedRevision' => $before['revision']])->update('alice', $module);
            $this->assertSame([422, ['error' => 'ScopeInactive']], [$response->getStatus(), self::body($response)]);
        }
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame('refused', end($this->logged)[1]['result']);
    }

    public function testReadsStayAvailableWhileTheBarrierIsClosed(): void {
        $show = $this->controller()->show('alice', 'files');
        $this->assertSame(200, $show->getStatus());
        $this->assertSame(['uid' => 'alice', 'module' => 'files', 'active' => false, 'mode' => 'unrestricted', 'items' => [], 'limit' => 200,
            'revision' => $this->fx->revision()], self::body($show));
        $this->assertSame(200, $this->controller()->candidates('alice', 'files')->getStatus());
    }

    public function testAPutWithoutRevisionIs428AndAStaleOneIs409WithTheSavedState(): void {
        $before = $this->fx->snapshot();
        $missing = $this->controller(['mode' => 'restricted', 'items' => [$this->fx->projects]], true)->update('alice', 'files');
        $this->assertSame([428, ['error' => 'PreconditionRequired']], [$missing->getStatus(), self::body($missing)]);
        $this->assertSame($before, $this->fx->snapshot());

        $this->controller(['expectedRevision' => $before['revision'], 'mode' => 'restricted', 'items' => [$this->fx->projects]], true)->update('alice', 'files');
        $stale = $this->controller(['expectedRevision' => $before['revision'], 'mode' => 'unrestricted'], true)->update('alice', 'files');
        $this->assertSame(409, $stale->getStatus());
        $this->assertSame(['error' => 'Conflict', 'revision' => $this->fx->revision(), 'state' => ['scope' => ['mode' => 'restricted', 'count' => 1]]], self::body($stale));
    }

    public function testASavedScopeAnswersItsRevisionReceiptAndSummary(): void {
        $revision = $this->fx->revision();
        $response = $this->controller(['expectedRevision' => $revision, 'mode' => 'restricted', 'items' => ['12', '11', '12']], true)->update('alice', 'calendar');

        $body = self::body($response);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame((string)((int)$revision + 1), $body['revision']);
        $this->assertTrue($body['changed']);
        $this->assertIsString($body['receiptId']);
        $this->assertSame(['mode' => 'restricted', 'count' => 2], $body['scope']);

        $show = self::body($this->controller([], true)->show('alice', 'calendar'));
        $this->assertSame(['Personal', 'Team'], array_column($show['items'], 'name'));
        $this->assertTrue($show['active']);
    }

    public function testAnInaccessibleResourceIs422AndTooManyResourcesIs422WithoutWrite(): void {
        $before = $this->fx->snapshot();
        $inaccessible = $this->controller(['expectedRevision' => $before['revision'], 'mode' => 'restricted', 'items' => [$this->fx->locked]], true)->update('alice', 'files');
        $this->assertSame([422, ['error' => 'Inaccessible', 'items' => [$this->fx->locked]]], [$inaccessible->getStatus(), self::body($inaccessible)]);

        $tooMany = $this->controller(['expectedRevision' => $before['revision'], 'mode' => 'restricted', 'items' => array_map('strval', range(1, 201))], true)->update('alice', 'files');
        $this->assertSame([422, ['error' => 'TooManyResources', 'limit' => 200]], [$tooMany->getStatus(), self::body($tooMany)]);
        $this->assertSame($before, $this->fx->snapshot());
    }

    /** Review F1: once the save committed, a failing re-read never answers "nothing was written". */
    public function testASummaryThatFailsAfterTheCommitAnswersUncertainWithTheReceipt(): void {
        $reads = $this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class);
        $reads->method('forModule')->willThrowException(new \UnexpectedValueException('Invalid stored resource scope'));
        $revision = $this->fx->revision();

        $response = $this->controller(['expectedRevision' => $revision, 'mode' => 'restricted', 'items' => ['11']], true, $reads)->update('alice', 'calendar');

        $body = self::body($response);
        $this->assertSame(503, $response->getStatus());
        $this->assertSame(['error' => 'Unavailable', 'uncertain' => true, 'revision' => (string)((int)$revision + 1), 'changed' => true], array_diff_key($body, ['receiptId' => 1]));
        $this->assertIsString($body['receiptId']);
        $this->assertSame(['11'], $this->fx->scopes->forModule('alice', 'calendar')['items'], 'the scope is saved');
        $this->assertSame(['uncertain', 503], [end($this->logged)[1]['result'], end($this->logged)[1]['status']]);
        $this->fx->services()->undo->restoreReceipt($body['receiptId'], $body['revision'], 'root');
        $this->assertNull($this->fx->scopes->forModule('alice', 'calendar'), 'the receipt of the uncertain answer undoes the save');
    }

    /** Review F2: native resources that cannot be read refuse the save as unavailable, not as inaccessible. */
    public function testAnUnreadableTreeRefusesTheSaveWith503NotInaccessible(): void {
        $resources = $this->createMock(\OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator::class);
        $resources->method('inaccessible')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable('Native resources unavailable'));
        $before = $this->fx->snapshot();

        $response = $this->controller(['expectedRevision' => $before['revision'], 'mode' => 'restricted', 'items' => [$this->fx->projects]], true, null, $resources)->update('alice', 'files');

        $this->assertSame([503, ['error' => 'Unavailable', 'uncertain' => false]], [$response->getStatus(), self::body($response)]);
        $this->assertSame($before, $this->fx->snapshot());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidBodies(): array {
        return [
            'no mode' => [['items' => []]],
            'unknown mode' => [['mode' => 'all', 'items' => []]],
            'unrestricted with items' => [['mode' => 'unrestricted', 'items' => []]],
            'restricted without items' => [['mode' => 'restricted']],
            'items as an object' => [['mode' => 'restricted', 'items' => ['a' => '1']]],
            'items as text' => [['mode' => 'restricted', 'items' => '1']],
            'revision as a number' => [['mode' => 'unrestricted', 'expectedRevision' => 0]],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIs400WithoutWrite(array $body): void {
        $before = $this->fx->snapshot();
        $response = $this->controller($body + ['expectedRevision' => $before['revision']], true)->update('alice', 'files');
        $this->assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), self::body($response)]);
        $this->assertSame($before, $this->fx->snapshot());
    }

    public function testAnUnknownUserOrModuleIs400AndIsNotNamedInTheAudit(): void {
        foreach ([['nobody', 'files'], ['alice', 'notes']] as [$uid, $module]) {
            foreach (['show', 'candidates'] as $route) {
                $this->assertSame(400, $this->controller()->{$route}($uid, $module)->getStatus());
            }
        }
        foreach ($this->logged as [, $context]) {
            $this->assertSame([null, 'invalid'], [$context['target'], $context['result']]);
        }
    }

    /** S13: every admin query is audited with structured context only, never a resource name or path. */
    public function testEveryQueryIsAuditedWithoutNamesOrPaths(): void {
        $this->controller([], true)->candidates('alice', 'files', '', 1);
        $this->controller(['expectedRevision' => $this->fx->revision(), 'mode' => 'restricted', 'items' => [$this->fx->projects, $this->fx->secret]], true)->update('alice', 'files');
        $this->controller([], true)->show('alice', 'files');

        $this->assertCount(3, $this->logged);
        $this->assertSame(['scope.candidates', 'scope.update', 'scope.show'], array_column(array_column($this->logged, 1), 'action'));
        [, $candidates] = $this->logged[0];
        $this->assertSame(['app' => 'mcp', 'admin' => 'root', 'target' => 'alice', 'action' => 'scope.candidates', 'result' => 'ok',
            'module' => 'files', 'timestamp' => 1760000123, 'page' => 1, 'count' => 3], $candidates);
        $this->assertSame(2, $this->logged[1][1]['count']);
        $text = json_encode($this->logged, JSON_UNESCAPED_SLASHES);
        foreach (['Projects', 'Archive', 'Secret', '/Projects'] as $name) {
            $this->assertStringNotContainsString($name, (string)$text);
        }
    }

    /** S13: no attribute opens the admin routes, so the framework refuses a non-administrator before the target. */
    public function testTheAdminRoutesCarryNoAttributeThatOpensThem(): void {
        foreach (['show', 'candidates', 'update'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(ScopeController::class, $method))->getAttributes(), $method);
        }
    }

    /** The matrix undo route refuses, with a mapped answer, a scope receipt whose barrier is closed again. */
    public function testTheUndoRouteRefusesAScopeReceiptBehindAClosedBarrier(): void {
        $saved = $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $before = $this->fx->snapshot();
        $request = $this->createMock(IRequest::class);
        $body = ['receiptId' => $saved['receiptId'], 'expectedRevision' => $saved['revision']];
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $body[$key] ?? $default);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($this->fx->user('root'));
        $grants = new \OCA\Mcp\Controller\GrantsController('mcp', $request, $this->createMock(\OCA\Mcp\Access\Grants\GrantMatrix::class),
            $this->createMock(\OCA\Mcp\Access\Grants\GrantPolicy::class), $this->fx->userManager(), $this->createMock(\OCP\IConfig::class),
            $this->createMock(\OCA\Mcp\Access\Logs\LogsAccess::class), $this->fx->services(false)->grants, $this->fx->services(false)->service, $this->fx->services(false)->undo, $this->fx->state, $session,
                $this->createMock(\OCA\Mcp\Access\Grants\Groups\GroupGrantsService::class), $this->fx->oauth, $this->createMock(\OCA\Mcp\OAuth\OAuthClientsService::class));

        $response = $grants->undo();

        $this->assertSame([422, ['error' => 'ScopeInactive']], [$response->getStatus(), self::body($response)]);
        $this->assertSame($before, $this->fx->snapshot());
    }

    private function personal(?string $session = 'alice'): MyScopeController {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($session === null ? null : $this->fx->user($session));
        return new MyScopeController('mcp', $this->createMock(IRequest::class), $this->fx->scopes, $this->fx->enumerator(), $userSession);
    }

    public function testThePersonalReadShowsOnlyTheOwnScopeWithoutTheExclusion(): void {
        $this->fx->commands()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->secret, $this->fx->projects]);

        $other = $this->personal()->index('bob');
        $this->assertSame([403, ['error' => 'Forbidden']], [$other->getStatus(), self::body($other)]);
        $this->assertSame(401, $this->personal(null)->index()->getStatus());

        foreach (['', 'alice'] as $uid) {
            $own = self::body($this->personal()->index($uid));
            $this->assertSame(['mode' => 'unrestricted', 'items' => []], $own['modules']['calendar']);
            $this->assertSame('restricted', $own['modules']['files']['mode']);
            $this->assertSame([
                ['id' => $this->fx->projects, 'found' => true, 'name' => 'Projects', 'path' => '/Projects'],
                ['id' => $this->fx->secret, 'found' => true, 'name' => 'Secret', 'path' => '/Secret'],
            ], $own['modules']['files']['items']);
        }
    }

    public function testThePersonalReadIsUnavailableNeverUnrestrictedWhenTheScopeCannotBeRead(): void {
        $this->fx->database->dbal->insert('mcp_scope_modes', ['user_key' => \OCA\Mcp\Db\IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'mallory', 'policy_rev' => '1']);
        $this->assertSame(503, $this->personal()->index()->getStatus());
        $this->assertSame(503, $this->controller()->show('alice', 'files')->getStatus());
    }
}
