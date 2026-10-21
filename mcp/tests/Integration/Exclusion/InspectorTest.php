<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Inspection\AccessInspector;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\IConfig;
use OCP\Server;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

/**
 * The inspector on the real server (ticket 13 T9; issue #4 A5/S13, B6): its answers are what the tools really do.
 * A path's reason (direct tag, tagged folder, share origin, invalid configuration) comes with the tools refusing that
 * path as missing, and a readable path is read by them; the effective access of an operation is whether the
 * endpoint lists its tool; the container wires the calendar explanation to the scope; and the routes refuse a
 * non-administrator before anything is inspected.
 */
final class InspectorTest extends ExclusionTestCase {
    /** @var list<int> calendars to delete */
    private array $calendars = [];

    protected function tearDown(): void {
        foreach ($this->calendars as $id) {
            Server::get(CalDavBackend::class)->deleteCalendar($id, true);
        }
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(self::ALICE))))->executeStatement();
        }
        parent::tearDown();
    }

    private function inspector(): AccessInspector {
        return Server::get(AccessInspector::class);
    }

    public function testA5EveryPathReasonIsWhatTheFilesToolsDo(): void {
        $secret = $this->makeFolder('home', 'Secret');
        $this->makeFile('home', 'Secret/plan.md', 'plan');
        $deep = $this->makeFolder('home', 'Secret/Deep');
        $this->makeFile('home', 'Secret/Deep/inner.md', 'inner');
        $this->makeFile('home', 'open.md', 'open-' . $this->run);
        $this->tag($secret);
        $this->exclude();
        $mcp = $this->mcp();

        $this->assertSame(['reason' => AccessInspector::EXCLUDED, 'gate' => 'exclusion', 'exclusion' => VisibilityGuard::EXPLAINED_TAG_DIRECT, 'folder' => null],
            $this->inspector()->path(self::ALICE, $this->path('home', 'Secret')));
        $this->assertSame(['reason' => AccessInspector::EXCLUDED, 'gate' => 'exclusion', 'exclusion' => VisibilityGuard::EXPLAINED_TAG_FOLDER, 'folder' => $this->path('home', 'Secret')],
            $this->inspector()->path(self::ALICE, $this->path('home', 'Secret', 'plan.md')));
        $this->assertHiddenFromRead($mcp, $this->path('home', 'Secret', 'plan.md'), 'a file the inspector says is excluded');

        $this->assertSame(AccessInspector::ALLOWED, $this->inspector()->path(self::ALICE, $this->path('home', 'open.md'))['reason']);
        $this->assertReadable($mcp, $this->path('home', 'open.md'), 'open-' . $this->run, 'a file the inspector says is reached');
        $this->assertSame(['reason' => AccessInspector::NOT_FOUND, 'gate' => 'native_acl', 'exclusion' => null, 'folder' => null],
            $this->inspector()->path(self::ALICE, $this->path('home', 'nowhere.md')));

        // bob receives a subfolder of the excluded folder: its origin in alice's tree is what excludes it.
        $this->shareToUser($deep, self::ALICE, self::BOB);
        // An administrator asks in a request of their own; this long process set bob's mounts up before the share.
        Server::get(\OC\Files\SetupManager::class)->tearDown();
        // Where the share is mounted in bob's tree is the server's choice (it differs between majors): ask the tree.
        $mounted = $this->userFolder(self::BOB)->getById($deep->getId());
        $this->assertNotSame([], $mounted, 'the share is mounted for bob');
        $target = rtrim((string)$this->userFolder(self::BOB)->getRelativePath($mounted[0]->getPath()), '/');
        $answer = $this->inspector()->path(self::BOB, $target . '/inner.md');
        $this->assertSame(VisibilityGuard::EXPLAINED_TAG_SHARE_ORIGIN, $answer['exclusion'], json_encode($answer));
        $this->assertHiddenFromRead($this->mcp(self::BOB), $target . '/inner.md', 'a shared file whose origin is excluded');

        // A stored value that cannot be read refuses every file, and the inspector says why.
        Server::get(IConfig::class)->setAppValue('mcp', VisibilityGuard::CONFIG_KEY, '{}');
        $this->assertSame(VisibilityGuard::EXPLAINED_INVALID_CONFIGURATION, $this->inspector()->path(self::ALICE, $this->path('home', 'open.md'))['exclusion']);
        $this->assertHiddenFromRead($mcp, $this->path('home', 'open.md'), 'any file with an unreadable configuration');
    }

    /** @return list<string> names of the tools the endpoint lists to the client */
    private function listed(McpClient $client): array {
        $response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
            'Authorization: ' . LoginFactory::basic($client->uid())->authorization(), 'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
        $this->assertSame(200, $response['status'], $response['body']);
        return array_column(json_decode($response['body'], true)['result']['tools'] ?? [], 'name');
    }

    /** @return array<string, ?bool> allowed by "module.operation" */
    private function effective(string $uid): array {
        $out = [];
        foreach ($this->inspector()->effective($uid)['operations'] as $row) {
            $out[$row['module'] . '.' . $row['operation']] = $row['allowed'];
        }
        return $out;
    }

    public function testT9TheEffectiveAccessIsWhatTheEndpointLists(): void {
        $policy = Server::get(GrantPolicy::class);
        $mcp = $this->mcp();
        try {
            foreach ([true, false] as $granted) {
                $policy->setGrant(self::ALICE, 'notes', 'delete', $granted);
                $this->assertSame($granted, $this->effective(self::ALICE)['notes.delete']);
                $this->assertSame($granted, in_array('notes_delete', $this->listed($mcp), true));
            }
            $this->assertTrue($this->effective(self::ALICE)['files.create']);
            $this->assertContains('files_mkdir', $this->listed($mcp));

            // The raw switch only: the writer's disable would revoke every credential of the instance, the baseline's
            // ones included, which a later test checks.
            Server::get(IConfig::class)->setAppValue('mcp', GrantPolicy::SERVICE_KEY, '0');
            $rows = $this->inspector()->effective(self::ALICE)['operations'];
            $this->assertSame(['service'], array_values(array_unique(array_column($rows, 'blockingGate'))));
            $this->assertNotSame(200, $mcp->listStatus(), 'with the service off the endpoint admits nothing');
        } finally {
            Server::get(IConfig::class)->setAppValue('mcp', GrantPolicy::SERVICE_KEY, '1');
            $policy->setGrant(self::ALICE, 'notes', 'delete', true);
        }
    }

    public function testS13TheContainerExplainsATaskListThroughItsScope(): void {
        $id = (int)Server::get(CalDavBackend::class)->createCalendar('principals/users/' . self::ALICE, 'mcp-it-inspect-' . $this->run, [
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VTODO']),
        ]);
        $this->calendars[] = $id;
        $this->assertSame(['reason' => AccessInspector::ALLOWED, 'gate' => null], $this->inspector()->calendar(self::ALICE, 'tasks', (string)$id, 'read'));
        // The harness installs the Calendar app, so the app gate passes; the calendar module asks for events and a
        // task-only calendar gives alice no native access to them.
        $this->assertSame(['reason' => AccessInspector::NO_NATIVE_ACCESS, 'gate' => 'native_acl'], $this->inspector()->calendar(self::ALICE, 'calendar', (string)$id, 'read'));
        Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'], 'admin', self::ALICE, 'tasks', []);
        $this->assertSame(['reason' => AccessInspector::OUT_OF_SCOPE, 'gate' => 'resource_scope'], $this->inspector()->calendar(self::ALICE, 'tasks', (string)$id, 'read'));
    }

    public function testS13ANonAdministratorIsRefusedBeforeAnythingIsInspected(): void {
        $bob = LoginFactory::basic(self::BOB);
        foreach (['access?uid=' . self::ALICE, 'path?uid=' . self::ALICE . '&path=%2F', 'calendar?uid=' . self::ALICE . '&module=tasks&calendarId=1'] as $route) {
            $response = Http::request('GET', Http::BASE . '/index.php/apps/mcp/api/inspector/' . $route, [
                'Authorization: ' . $bob->authorization(), 'Accept: application/json', 'OCS-APIRequest: true',
            ]);
            $this->assertContains($response['status'], [403, 412], $route . ': ' . substr($response['body'], 0, 300));
            $this->assertStringNotContainsString('operations', $response['body']);
            $this->assertStringNotContainsString('reason', $response['body']);
        }
    }
}
