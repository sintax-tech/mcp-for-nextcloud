<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\OverridePreviewController;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Access\Grants\OverridePreview;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tests\Unit\ArrayGroupBackend;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * T16 (C2): the preview of a reset says, cell by cell, what the user has now and what the groups and defaults would give
 * once the individual value is gone. Read only, admin only, unknown never false.
 */
final class OverridePreviewTest extends TestCase {
    private InMemoryConfig $data;
    private \OCP\IConfig $config;
    private array $native = [];
    private array $ldap = [];
    private OverridePreview $preview;

    protected function setUp(): void {
        $this->data = new InMemoryConfig();
        $this->data->groupBackends = [new ArrayGroupBackend(fn () => $this->native, 'Database'), new ArrayGroupBackend(fn () => $this->ldap, 'LDAP')];
        $this->config = $this->data->mock($this);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!in_array($uid, ['ana', 'ben', 'cam'], true)) return null;
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $this->preview = new OverridePreview(InMemoryConfig::resolver($this->config), $users,
            InMemoryConfig::groupDatabase($this->config)->connection(), InMemoryConfig::state($this->config));
    }

    private static function reset(string $uid, ?string $module, string $operation): array {
        return ['uid' => $uid, 'module' => $module, 'operation' => $operation, 'state' => 'inherit'];
    }

    /** @return array<string, array{before: array, after: array}> by "uid:module:operation" */
    private function cells(array $commands): array {
        $out = [];
        foreach ($this->preview->preview($commands)['cells'] as $cell) {
            $out[$cell['uid'] . ':' . ($cell['module'] ?? '_') . ':' . $cell['operation']] = $cell;
        }
        return $out;
    }

    public function testAfterIsWhatGroupsAndDefaultsGiveAndBeforeIsTheIndividualValue(): void {
        $this->native['team'] = ['ana', 'ben'];
        InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->data->user = [
            'ana' => ['mcp' => ['eligible' => '1']],
            'ben' => ['mcp' => ['grant_notes_edit' => '0', 'grant_notes_read' => '0']],
            'cam' => ['mcp' => ['eligible' => '1', 'grant_notes_edit' => '1']],
        ];
        $cells = $this->cells([self::reset('ana', null, 'eligible'), self::reset('ben', 'notes', 'edit'), self::reset('ben', 'notes', 'read'),
            self::reset('cam', null, 'eligible'), self::reset('cam', 'notes', 'edit')]);
        // An individual Allow of "Can connect" kept by a group: the user stays eligible after the reset.
        $this->assertSame(['allowed' => true, 'source' => 'individual_allow', 'groupIds' => []], $cells['ana:_:eligible']['before']);
        $this->assertSame(['allowed' => true, 'source' => 'group', 'groupIds' => ['team']], $cells['ana:_:eligible']['after']);
        // An individual Deny of a grant a group gives: the reset turns it into an Allow.
        $this->assertSame(['allowed' => false, 'source' => 'individual_deny', 'groupIds' => []], $cells['ben:notes:edit']['before']);
        $this->assertSame(['allowed' => true, 'source' => 'group', 'groupIds' => ['team']], $cells['ben:notes:edit']['after']);
        // A Deny of a read nobody grants: the default (read is allowed) comes back.
        $this->assertSame(['allowed' => true, 'source' => 'default', 'groupIds' => []], $cells['ben:notes:read']['after']);
        // Nobody in a group: eligibility falls back to false and the grant to its default.
        $this->assertSame(['allowed' => false, 'source' => 'default', 'groupIds' => []], $cells['cam:_:eligible']['after']);
        $this->assertSame(['allowed' => false, 'source' => 'default', 'groupIds' => []], $cells['cam:notes:edit']['after']);
    }

    public function testAnInvalidStoredValueIsNamedAndItsAfterIsTheInheritance(): void {
        $this->data->user = ['ana' => ['mcp' => ['eligible' => 'yes']]];
        $cell = $this->cells([self::reset('ana', null, 'eligible')])['ana:_:eligible'];
        $this->assertSame(['allowed' => false, 'source' => 'invalid', 'groupIds' => []], $cell['before']);
        $this->assertSame('default', $cell['after']['source']);
    }

    public function testUnknownInheritanceIsNullNeverFalseAndNoTrustIsObserved(): void {
        $this->ldap['external'] = ['ana'];
        $this->native['pending'] = ['ben'];
        InMemoryConfig::groupPolicy($this->config, 'external', ['_system' => ['eligible']], 'active');
        InMemoryConfig::groupPolicy($this->config, 'pending', ['_system' => ['eligible']], 'needs_confirm');
        $this->data->user = ['ana' => ['mcp' => ['eligible' => '1']], 'ben' => ['mcp' => ['eligible' => '1']]];
        $revision = InMemoryConfig::state($this->config)->state()['revision'];
        $life = new GroupLifeMapper(InMemoryConfig::groupDatabase($this->config)->connection());
        $cells = $this->cells([self::reset('ana', null, 'eligible'), self::reset('ben', null, 'eligible')]);
        // A group that is not a native one, or that awaits confirmation, decides nothing: unknown, not "false".
        $this->assertNull($cells['ana:_:eligible']['after']['allowed']);
        $this->assertSame(['external'], $cells['ana:_:eligible']['after']['groupIds']);
        $this->assertNull($cells['ben:_:eligible']['after']['allowed']);
        $this->assertSame('unknown', $cells['ben:_:eligible']['after']['source']);
        // Read only: the revision did not move and the untrusted group's lifecycle was not rewritten.
        $this->assertSame($revision, InMemoryConfig::state($this->config)->state()['revision']);
        $this->assertSame('active', $life->get('external')['state']);
        $this->assertSame('needs_confirm', $life->get('pending')['state']);
    }

    public function testTheBodyIsValidatedLikeTheCommandAndAnythingItWouldRefuseIsRefused(): void {
        foreach ([
            'empty' => [],
            'not a list' => ['a' => self::reset('ana', null, 'eligible')],
            'another state' => [['uid' => 'ana', 'module' => null, 'operation' => 'eligible', 'state' => 'allow']],
            'a boolean instead of a state' => [['uid' => 'ana', 'module' => null, 'operation' => 'eligible', 'granted' => true]],
            'module missing' => [['uid' => 'ana', 'operation' => 'eligible', 'state' => 'inherit']],
            'unknown user' => [self::reset('zed', null, 'eligible')],
            'unknown operation' => [self::reset('ana', 'notes', 'fly')],
            'eligibility with a module' => [self::reset('ana', 'notes', 'eligible')],
            'a grant without module' => [self::reset('ana', null, 'read')],
            'extra key' => [self::reset('ana', null, 'eligible') + ['expectedRevision' => '1']],
        ] as $case => $body) {
            try {
                $this->preview->preview($body);
                $this->fail($case . ' was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->preview->preview(array_map(static fn (int $i): array => self::reset('ana', 'notes', 'read') + ['n' => $i], range(1, 3)));
    }

    public function testMoreThanFiftyUsersOrTwoThousandFiveHundredCellsAreRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->preview->preview(array_map(static fn (int $i): array => self::reset('user-' . $i, null, 'eligible'), range(1, 51)));
    }

    public function testDuplicatesAreDroppedAndNothingIsWritten(): void {
        $this->data->user = ['ana' => ['mcp' => ['eligible' => '1']]];
        $before = $this->data->user;
        $result = $this->preview->preview([self::reset('ana', null, 'eligible'), self::reset('ana', null, 'eligible')]);
        $this->assertCount(1, $result['cells']);
        $this->assertSame($before, $this->data->user);
    }

    public function testNoReadRunsInsideATransaction(): void {
        $db = InMemoryConfig::groupDatabase($this->config)->connection();
        $db->beginTransaction();
        try {
            $this->expectException(PolicyUnavailable::class);
            $this->preview->preview([self::reset('ana', null, 'eligible')]);
        } finally {
            $db->rollBack();
        }
    }

    private function controller(array $body, bool $admin, OverridePreview $service): OverridePreviewController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $body[$key] ?? $default);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('someone');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new OverridePreviewController('mcp', $request, $service, $session, $groups);
    }

    public function testNonAdminGets403BeforeAnythingIsResolved(): void {
        $service = $this->createMock(OverridePreview::class);
        $service->expects($this->never())->method('preview');
        $response = $this->controller(['commands' => [self::reset('ana', null, 'eligible')]], false, $service)->index();
        $this->assertSame(403, $response->getStatus());
        $this->assertSame(['error' => 'Forbidden'], $response->getData());
    }

    public function testAdminGetsTheCellsAndErrorsMapToTheirStatus(): void {
        $this->data->user = ['ana' => ['mcp' => ['eligible' => '1']]];
        $ok = $this->controller(['commands' => [self::reset('ana', null, 'eligible')]], true, $this->preview)->index();
        $this->assertSame(200, $ok->getStatus());
        $this->assertSame('ana', $ok->getData()['cells'][0]['uid']);
        $this->assertSame(400, $this->controller(['commands' => 'nope'], true, $this->preview)->index()->getStatus());
        $broken = $this->createMock(OverridePreview::class);
        $broken->method('preview')->willThrowException(new PolicyUnavailable('down'));
        $unavailable = $this->controller(['commands' => [self::reset('ana', null, 'eligible')]], true, $broken)->index();
        $this->assertSame(503, $unavailable->getStatus());
        $this->assertSame(['error' => 'Unavailable'], $unavailable->getData());
    }
}
