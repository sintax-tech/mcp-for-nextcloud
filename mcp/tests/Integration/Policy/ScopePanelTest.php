<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Controller\MyScopeController;
use OCA\Mcp\Controller\ScopeController;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Throwable;

/**
 * B7 on the real server: what the resource scope panel asks and saves, through the scope, matrix, undo and personal
 * controllers built from the container's own services, as an administrator session of the server: calendars live in
 * the container's DAV app, the writer and the enumerator are the container's. The matrix cell and the personal page
 * read back what the panel saved; a stale save writes nothing; Files is browsable and saved, its barrier opened by
 * B5. The framework's own admin check is proven over HTTP: a non-administrator session gets 403 on
 * every scope route. The panel's requests themselves (URLs, CSRF header, bodies) are the node tests' part.
 * It also measures the folder picker on a wide folder (pending item F3 of the B2 review): the native listing
 * materializes the whole folder before the page of 50.
 */
final class ScopePanelTest extends IntegrationTestCase {
    private const ADMIN = 'admin';
    private const WIDE = 1000;

    private string $run = '';
    /** @var list<int> */
    private array $calendars = [];
    protected function setUp(): void {
        parent::setUp();
        $this->run = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE))))->executeStatement();
        }
        foreach ($this->calendars as $id) {
            try {
                Server::get(CalDavBackend::class)->deleteCalendar($id, true);
            } catch (Throwable) {
            }
        }
        try {
            $this->home()->get('mcp-it-wide-' . $this->run)->delete();
        } catch (Throwable) {
        }
        parent::tearDown();
    }

    private function home(): Folder {
        return Server::get(IRootFolder::class)->getUserFolder(Fixtures::ALICE);
    }

    private function calendar(string $uri): int {
        $id = (int)Server::get(CalDavBackend::class)->createCalendar('principals/users/' . Fixtures::ALICE, $uri, [
            '{DAV:}displayname' => $uri,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
        ]);
        $this->calendars[] = $id;
        return $id;
    }

    /** A session of a user as the browser holds it: an app-password login, its session cookies and requesttoken. */
    private static function httpSession(string $uid): array {
        $auth = 'Authorization: ' . LoginFactory::basic($uid)->authorization();
        $response = Http::request('GET', Http::BASE . '/index.php/csrftoken', [$auth, 'Accept: application/json']);
        preg_match_all('/^Set-Cookie:\s*([^=;\s]+=[^;\r\n]*)/mi', $response['headers'], $cookies);
        return [$auth, 'Cookie: ' . implode('; ', $cookies[1]), 'requesttoken: ' . (json_decode($response['body'], true)['token'] ?? '')];
    }

    /**
     * A controller of the app built by the container's services, with the request parameters the panel sends.
     *
     * @param class-string $class
     * @param array<string, mixed> $params
     */
    private function controller(string $class, array $params = []): object {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
        $args = [];
        foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $args[] = match (true) {
                $parameter->getName() === 'appName' => 'mcp',
                $name === IRequest::class => $request,
                default => Server::get($name),
            };
        }
        return new $class(...$args);
    }

    /** @return array{status:int, body:array<string, mixed>} the answer of $call, signed in as $uid */
    private function as(string $uid, \Closure $call): array {
        $session = Server::get(IUserSession::class);
        $previous = $session->getUser();
        $session->setUser(Server::get(IUserManager::class)->get($uid));
        try {
            $response = $call();
            return ['status' => $response->getStatus(), 'body' => $response->getData()];
        } finally {
            $session->setUser($previous);
        }
    }

    private function show(string $module): array {
        return $this->as(self::ADMIN, fn () => $this->controller(ScopeController::class)->show(Fixtures::ALICE, $module));
    }

    private function candidates(string $module, string $parent = '', int $page = 1): array {
        return $this->as(self::ADMIN, fn () => $this->controller(ScopeController::class)->candidates(Fixtures::ALICE, $module, '', $page, $parent));
    }

    private function save(string $module, array $body): array {
        return $this->as(self::ADMIN, fn () => $this->controller(ScopeController::class, $body)->update(Fixtures::ALICE, $module));
    }

    private function scopePath(string $module, string $suffix = ''): string {
        return '/api/scope/' . rawurlencode(Fixtures::ALICE) . '/' . $module . $suffix;
    }

    public function testS14ThePanelSavesUndoesAndShowsTheScopeWhereTheAdminAndTheUserReadIt(): void {
        $c1 = (string)$this->calendar("mcp-it-panel-a-{$this->run}");
        $c2 = (string)$this->calendar("mcp-it-panel-b-{$this->run}");

        $shown = $this->show('calendar');
        $this->assertSame(200, $shown['status'], json_encode($shown['body']));
        $this->assertSame(['unrestricted', true, []], [$shown['body']['mode'], $shown['body']['active'], $shown['body']['items']]);
        $found = $this->candidates('calendar')['body'];
        $this->assertContains($c1, array_column($found['items'], 'id'));
        $this->assertContains($c2, array_column($found['items'], 'id'));

        $saved = $this->save('calendar', ['expectedRevision' => $shown['body']['revision'], 'mode' => 'restricted', 'items' => [$c1]]);
        $this->assertSame(200, $saved['status'], json_encode($saved['body']));
        $this->assertSame([true, ['mode' => 'restricted', 'count' => 1]], [$saved['body']['changed'], $saved['body']['scope']]);
        $this->assertSame([[$c1, true, "mcp-it-panel-a-{$this->run}"]],
            array_map(static fn (array $i): array => [$i['id'], $i['found'], $i['name']], $this->show('calendar')['body']['items']));

        // The stale view of a second tab: refused, nothing written, never retried.
        $stale = $this->save('calendar', ['expectedRevision' => $shown['body']['revision'], 'mode' => 'restricted', 'items' => [$c2]]);
        $this->assertSame(409, $stale['status']);
        $this->assertSame([$c1], array_column($this->show('calendar')['body']['items'], 'id'));

        // The matrix cell and the personal page read the same scope.
        $matrix = $this->as(self::ADMIN, fn () => $this->controller(GrantsController::class)->index(Fixtures::ALICE));
        $row = array_values(array_filter($matrix['body']['users'] ?? [], static fn (array $u): bool => $u['uid'] === Fixtures::ALICE))[0] ?? null;
        $this->assertSame(['mode' => 'restricted', 'count' => 1], $row['scope']['calendar'] ?? null, json_encode($matrix['body']));
        $this->assertSame(['mode' => 'unrestricted', 'count' => null], $row['scope']['tasks']);
        $mine = $this->as(Fixtures::ALICE, fn () => $this->controller(MyScopeController::class)->index());
        $this->assertSame(200, $mine['status'], json_encode($mine['body']));
        $personal = $mine['body']['modules']['calendar'];
        $this->assertSame(['restricted', [$c1]], [$personal['mode'], array_column($personal['items'], 'id')]);

        $undone = $this->as(self::ADMIN, fn () => $this->controller(GrantsController::class, ['receiptId' => $saved['body']['receiptId'], 'expectedRevision' => $saved['body']['revision']])->undo());
        $this->assertSame(200, $undone['status'], json_encode($undone['body']));
        $this->assertSame('unrestricted', $this->show('calendar')['body']['mode']);
    }

    public function testS14FilesIsBrowsableAndSavedNowThatItsBarrierIsOpen(): void {
        $folder = $this->home()->newFolder('mcp-it-wide-' . $this->run);
        $shown = $this->show('files')['body'];
        $this->assertTrue($shown['active'], 'B5 wired every Files consumer, so the panel saves the Files scope');
        $root = $this->candidates('files');
        $this->assertSame(200, $root['status']);
        $this->assertContains((string)$folder->getId(), array_column($root['body']['items'], 'id'));

        $saved = $this->save('files', ['expectedRevision' => $shown['revision'], 'mode' => 'restricted', 'items' => [(string)$folder->getId()]]);
        $this->assertSame(200, $saved['status'], json_encode($saved['body']));
        $this->assertSame(['mode' => 'restricted', 'count' => 1], $saved['body']['scope']);
        $this->assertSame([(string)$folder->getId()], array_column($this->show('files')['body']['items'], 'id'));
    }

    /** B7 review A1: on the real database, a scope that cannot be read is "unavailable" in the matrix, never blank. */
    public function testTheRealMatrixMarksAnUnreadableScopeUnavailable(): void {
        // An inconsistent mode row (another uid behind alice's key) makes the scope read fail closed.
        $qb = $this->official()->getQueryBuilder();
        $qb->insert(ResourceScopeMapper::MODES_TABLE)
            ->setValue('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE)))
            ->setValue('module', $qb->createNamedParameter('files'))
            ->setValue('user_id', $qb->createNamedParameter('mcp-it-collision'))
            ->setValue('policy_rev', $qb->createNamedParameter('1'))
            ->executeStatement();
        $matrix = $this->as(self::ADMIN, fn () => $this->controller(GrantsController::class)->index(Fixtures::ALICE));
        $this->assertSame(200, $matrix['status'], 'the grants stay editable');
        $row = array_values(array_filter($matrix['body']['users'], static fn (array $u): bool => $u['uid'] === Fixtures::ALICE))[0];
        $this->assertSame(array_fill_keys(ResourceScopeMapper::MODULES, ['mode' => 'unavailable', 'count' => null]), $row['scope']);
    }

    /** The framework's admin check, over HTTP: a non-administrator session is refused before any scope is read. */
    public function testANonAdministratorIsRefusedBeforeTheTargetIsRead(): void {
        $session = self::httpSession(Fixtures::BOB);
        foreach ([['GET', $this->scopePath('calendar')], ['GET', $this->scopePath('calendar', '/candidates')], ['PUT', $this->scopePath('calendar')]] as [$method, $path]) {
            $response = Http::request($method, Http::BASE . '/index.php/apps/mcp' . $path, [...$session, 'Accept: application/json', 'Content-Type: application/json'],
                $method === 'PUT' ? json_encode(['expectedRevision' => '1', 'mode' => 'unrestricted']) : null);
            $this->assertSame(403, $response['status'], "$method $path");
            $this->assertStringNotContainsString('items', $response['body']);
        }
    }

    /** F3 of the B2 review: one page of the folder picker over a folder of WIDE subfolders, measured and recorded. */
    public function testTheFolderPickerPagesAWideFolder(): void {
        $wide = $this->home()->newFolder('mcp-it-wide-' . $this->run);
        for ($i = 0; $i < self::WIDE; $i++) {
            $wide->newFolder(sprintf('f%04d', $i));
        }
        $started = hrtime(true);
        $page = Server::get(NativeResourceEnumerator::class)->candidates(Fixtures::ALICE, 'files', '', 1, (string)$wide->getId());
        $service = (hrtime(true) - $started) / 1e6;
        $started = hrtime(true);
        $last = $this->candidates('files', (string)$wide->getId(), 20);
        $route = (hrtime(true) - $started) / 1e6;

        $this->assertSame([self::WIDE, 50, true], [$page['total'], count($page['items']), $page['hasMore']]);
        $this->assertSame([200, 50, false], [$last['status'], count($last['body']['items'] ?? []), $last['body']['hasMore'] ?? null], 'the last page');
        file_put_contents('/tmp/mcp-it-observations.txt', sprintf("[%s] folder picker over %d subfolders: service page 1 %.0f ms; last page through the route %.0f ms; memory peak %d MiB\n",
            self::class, self::WIDE, $service, $route, intdiv(memory_get_peak_usage(true), 1048576)), FILE_APPEND);
        $this->assertLessThan(30000.0, $service, 'a page of a wide folder stays usable');
    }
}
