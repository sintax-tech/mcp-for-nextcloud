<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\NativeClient;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class GrantsControllerTest extends TestCase {
    private MatrixFixture $fx;
    /** @var array<string, mixed> JSON body parameters seen through IRequest::getParam */
    private array $body = [];

    protected function setUp(): void {
        $this->fx = new MatrixFixture($this);
        $this->fx->addUser('ana', 'Ana Souza', 'ana@corp.example');
        $this->fx->addUser('bob', 'Bob', 'bob@corp.example');
        $this->fx->addUser('carl', 'Carl', 'carl@corp.example', false);
    }

    private function controller(array $body = []): GrantsController {
        $this->body = $body;
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $this->body) ? $this->body[$key] : $default);
        return new GrantsController('mcp', $request, $this->fx->matrix(), $this->fx->policy, $this->fx->userManager(), $this->fx->config->mock($this), $this->fx->oauth, $this->fx->logsAccess());
    }

    private static function assertBad(JSONResponse $response): void {
        self::assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()]);
    }

    public function testEveryEndpointIsAdminOnlyAndCsrfProtected(): void {
        foreach (['index', 'update', 'bulk', 'service', 'oauthClients', 'updateOauthClients', 'logsAccess', 'updateLogsAccess'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(GrantsController::class, $method))->getAttributes(), $method);
        }
    }

    public function testIndexReturnsTheMatrixPage(): void {
        $response = $this->controller()->index('', '', 1);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['ana', 'bob', 'carl'], array_column($response->getData()['users'], 'uid'));
        $this->assertStringNotContainsString('corp.example', json_encode($response->getData()));
        self::assertBad($this->controller()->index('', 'nope', 1));
        self::assertBad($this->controller()->index('', '', 0));
    }

    public function testIndexFiltersEligibleAndConnectedUsers(): void {
        $this->fx->policy->setEligible('bob', true);
        $this->fx->policy->setConnected('bob', true);
        $this->fx->policy->setEligible('carl', true);
        $this->assertSame(['bob', 'carl'], array_column($this->controller()->index('', '', 1, 'eligible')->getData()['users'], 'uid'));
        $this->assertSame(['bob'], array_column($this->controller()->index('', '', 1, 'connected')->getData()['users'], 'uid'));
        self::assertBad($this->controller()->index('', '', 1, 'everyone'));
    }

    public function testToggleEligibilityAndGrantIsReflectedInThePolicy(): void {
        $row = $this->controller(['eligible' => true])->update('ana')->getData();
        $this->assertTrue($row['eligible']);
        $this->assertTrue($this->fx->policy->eligible('ana'));
        $row = $this->controller(['module' => 'files', 'operation' => 'edit', 'granted' => true])->update('ana')->getData();
        $this->assertTrue($row['grants']['files']['edit']);
        $this->assertTrue($this->fx->policy->granted('ana', 'files', 'edit'));
        $this->controller(['module' => 'files', 'operation' => 'read', 'granted' => false])->update('ana');
        $this->assertFalse($this->fx->policy->granted('ana', 'files', 'read'));
        $this->assertFalse($this->fx->policy->granted('bob', 'files', 'edit'));
    }

    public function testUpdateValidatesInput(): void {
        foreach ([
            ['ghost', ['eligible' => true]],
            ['ana', []],
            ['ana', ['eligible' => 'yes']],
            ['ana', ['eligible' => true, 'module' => 'files', 'operation' => 'read', 'granted' => true]],
            ['ana', ['module' => 'files', 'operation' => 'delete', 'granted' => true]],
            ['ana', ['module' => 'mail', 'operation' => 'read', 'granted' => true]],
            ['ana', ['module' => 'files', 'operation' => 'read', 'granted' => 1]],
            ['ana', ['module' => 'files', 'operation' => 'read']],
        ] as [$uid, $body]) {
            self::assertBad($this->controller($body)->update($uid));
        }
        $this->assertSame([], $this->fx->config->user['ana']['mcp'] ?? []);
    }

    public function testBulkUpdatesThePageAtomically(): void {
        $response = $this->controller(['uids' => ['ana', 'bob', 'ana'], 'module' => 'notes', 'operation' => 'create', 'granted' => true])->bulk();
        $this->assertSame(['updated' => 2], $response->getData());
        $this->assertTrue($this->fx->policy->granted('ana', 'notes', 'create'));
        $this->assertTrue($this->fx->policy->granted('bob', 'notes', 'create'));
        $this->controller(['uids' => ['ana', 'bob'], 'module' => null, 'operation' => 'eligible', 'granted' => true])->bulk();
        $this->assertTrue($this->fx->policy->eligible('bob'));

        self::assertBad($this->controller(['uids' => ['ana', 'ghost'], 'module' => 'notes', 'operation' => 'edit', 'granted' => true])->bulk());
        $this->assertFalse($this->fx->policy->granted('ana', 'notes', 'edit'));
        foreach ([
            ['uids' => [], 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => array_fill(0, 51, 'ana'), 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => 'ana', 'module' => 'notes', 'operation' => 'edit', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'eligible', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'files', 'operation' => 'delete', 'granted' => true],
            ['uids' => ['ana'], 'module' => 'notes', 'operation' => 'edit', 'granted' => 'true'],
        ] as $body) {
            self::assertBad($this->controller($body)->bulk());
        }
    }

    public function testBulkEligibilityRevokesSelectedGroupMembersOnly(): void {
        $this->fx->groups['sales'] = ['ana', 'bob'];
        foreach (['ana', 'bob', 'carl'] as $uid) {
            $this->fx->oauth->insertToken(['user_id' => $uid], 0);
            $this->fx->oauth->insertCode(['user_id' => $uid], 0);
        }
        $this->controller(['uids' => $this->fx->groups['sales'], 'module' => null,
            'operation' => 'eligible', 'granted' => false])->bulk();
        $this->assertSame(['carl'], array_values(array_column($this->fx->oauth->tokens, 'user_id')));
        $this->assertSame(['carl'], array_values(array_column($this->fx->oauth->codes, 'user_id')));
        $this->controller(['uids' => $this->fx->groups['sales'], 'module' => null,
            'operation' => 'eligible', 'granted' => true])->bulk();
        $this->assertCount(1, $this->fx->oauth->tokens);
    }

    public function testServiceUsesItsOwnKey(): void {
        $this->assertSame(['enabled' => true], $this->controller(['enabled' => true])->service()->getData());
        $this->assertSame('1', $this->fx->config->app['mcp']['service_enabled']);
        $this->assertArrayNotHasKey('enabled', $this->fx->config->app['mcp']);
        self::assertBad($this->controller(['enabled' => '1'])->service());
        $this->assertTrue($this->fx->policy->globalEnabled());
    }

    /** The general switch off must reach the policy and take every credential with it, not just flip a flag. */
    public function testSwitchingTheServiceOffReachesThePolicyAndRevokesEverything(): void {
        $this->fx->policy->setGlobalEnabled(true);
        $this->fx->oauth->insertToken(['user_id' => 'ana'], 0);
        $this->fx->oauth->insertCode(['user_id' => 'bob'], 0);

        $this->assertSame(['enabled' => false], $this->controller(['enabled' => false])->service()->getData());

        $this->assertFalse($this->fx->policy->globalEnabled());
        $this->assertSame('0', $this->fx->config->app['mcp']['service_enabled']);
        $this->assertSame([], $this->fx->oauth->tokens);
        $this->assertSame([], $this->fx->oauth->codes);
    }

    public function testBulkRevokingAGrantRevokesItAndNeverGrantsIt(): void {
        $this->fx->policy->setEligible('ana', true);
        $this->fx->policy->setGrant('ana', 'notes', 'edit', true);
        $this->fx->policy->setEligible('bob', true);
        $this->fx->policy->setGrant('bob', 'notes', 'edit', true);

        $this->assertSame(200, $this->controller(['uids' => ['ana'], 'module' => 'notes', 'operation' => 'edit', 'granted' => false])->bulk()->getStatus());

        $this->assertFalse($this->fx->policy->granted('ana', 'notes', 'edit'));
        $this->assertTrue($this->fx->policy->granted('bob', 'notes', 'edit'), 'only the selected users');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badBulkUidsProvider(): array {
        return [
            'a number among the ids' => [['uids' => [42], 'module' => 'notes', 'operation' => 'edit', 'granted' => true]],
            'an object instead of a list' => [['uids' => ['a' => 'ana'], 'module' => 'notes', 'operation' => 'edit', 'granted' => true]],
            'a null among the ids' => [['uids' => ['ana', null], 'module' => 'notes', 'operation' => 'edit', 'granted' => true]],
            'no module for a grant' => [['uids' => ['ana'], 'module' => null, 'operation' => 'edit', 'granted' => true]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('badBulkUidsProvider')]
    public function testABadBulkIsRefusedAndChangesNothing(array $body): void {
        self::assertBad($this->controller($body)->bulk());

        $this->assertFalse($this->fx->policy->granted('ana', 'notes', 'edit'));
    }

    public function testOauthClientsShowsTheDefaultHostsWhenUnset(): void {
        $data = $this->controller()->oauthClients()->getData();
        $this->assertSame([
            'hosts' => explode(',', ClientMetadataFetcher::DEFAULT_HOSTS),
            'hostsDefault' => true,
            'nativeClientEnabled' => false,
            'nativeClientId' => NativeClient::CLIENT_ID,
            'nativeRedirectUris' => NativeClient::REDIRECT_URIS,
        ], $data);
    }

    public function testOauthClientsShowsDefaultHostsForEmptyConfig(): void {
        foreach (['', " , , \t "] as $raw) {
            $this->fx->config->app['mcp'][ClientMetadataFetcher::HOSTS_KEY] = $raw;
            $data = $this->controller()->oauthClients()->getData();
            $this->assertSame(explode(',', ClientMetadataFetcher::DEFAULT_HOSTS), $data['hosts']);
            $this->assertTrue($data['hostsDefault']);
        }
    }

    public function testOauthClientsReadsExplicitConfig(): void {
        $this->fx->config->app['mcp']['oauth_client_hosts'] = 'claude.ai,gemini.example.com';
        $this->fx->config->app['mcp']['oauth_native_client_enabled'] = '1';
        $data = $this->controller()->oauthClients()->getData();
        $this->assertSame(['claude.ai', 'gemini.example.com'], $data['hosts']);
        $this->assertFalse($data['hostsDefault']);
        $this->assertTrue($data['nativeClientEnabled']);
    }

    public function testUpdateOauthClientsStoresHostsAndToggle(): void {
        $data = $this->controller(['hosts' => ['claude.ai', 'my-host.example.org', 'claude.ai']])->updateOauthClients()->getData();
        $this->assertSame('claude.ai,my-host.example.org', $this->fx->config->app['mcp']['oauth_client_hosts']);
        $this->assertSame(['claude.ai', 'my-host.example.org'], $data['hosts']);
        $this->assertFalse($data['hostsDefault']);
        $this->assertArrayNotHasKey('oauth_native_client_enabled', $this->fx->config->app['mcp']);

        $data = $this->controller(['nativeClientEnabled' => true])->updateOauthClients()->getData();
        $this->assertSame('1', $this->fx->config->app['mcp']['oauth_native_client_enabled']);
        $this->assertTrue($data['nativeClientEnabled']);
        $this->controller(['nativeClientEnabled' => false])->updateOauthClients();
        $this->assertSame('0', $this->fx->config->app['mcp']['oauth_native_client_enabled']);
    }

    public function testDisablingTheNativeClientDeletesOnlyItsGrants(): void {
        $this->fx->oauth->insertToken(['user_id' => 'ana', 'client_id' => NativeClient::CLIENT_ID], 0);
        $this->fx->oauth->insertCode(['user_id' => 'bob', 'client_id' => NativeClient::CLIENT_ID], 0);
        $this->fx->oauth->insertToken(['user_id' => 'ana', 'client_id' => 'https://claude.ai/x'], 0);
        $this->controller(['nativeClientEnabled' => true])->updateOauthClients();
        $this->assertCount(2, $this->fx->oauth->tokens);
        $this->controller(['nativeClientEnabled' => false])->updateOauthClients();
        $this->assertSame(['https://claude.ai/x'], array_values(array_column($this->fx->oauth->tokens, 'client_id')));
        $this->assertSame([], $this->fx->oauth->codes);
    }

    public function testSendingTheDefaultHostsDeletesTheKeyAndAnotherListStoresIt(): void {
        $this->fx->config->app['mcp']['oauth_client_hosts'] = 'claude.ai,example.org';
        $data = $this->controller(['hosts' => ['chatgpt.com', 'claude.ai']])->updateOauthClients()->getData();
        $this->assertArrayNotHasKey('oauth_client_hosts', $this->fx->config->app['mcp']);
        $this->assertTrue($data['hostsDefault']);
        $this->controller(['hosts' => ['claude.ai']])->updateOauthClients();
        $this->assertSame('claude.ai', $this->fx->config->app['mcp']['oauth_client_hosts']);
    }

    public function testUpdateOauthClientsNormalizesHostsBeforeValidationAndDeduplication(): void {
        $response = $this->controller(['hosts' => [' ChatGPT.com ', 'CLAUDE.AI', 'chatgpt.COM', 'Extra.Example.org']])->updateOauthClients();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['chatgpt.com', 'claude.ai', 'extra.example.org'], $response->getData()['hosts']);
        $this->assertSame('chatgpt.com,claude.ai,extra.example.org', $this->fx->config->app['mcp'][ClientMetadataFetcher::HOSTS_KEY]);
    }

    public function testUpdateOauthClientsRejectsInvalidInputWithoutWriting(): void {
        foreach ([
            [],
            ['hosts' => []],
            ['hosts' => 'claude.ai'],
            ['hosts' => ['https://claude.ai']],
            ['hosts' => ['claude.ai:443']],
            ['hosts' => ['claude.ai/path']],
            ['hosts' => ['*.claude.ai']],
            ['hosts' => ['claude .ai']],
            ['hosts' => ['']],
            ['hosts' => ['   ']],
            ['hosts' => ['claude..ai']],
            ['hosts' => [42]],
            ['hosts' => ['claude.ai'], 'nativeClientEnabled' => 'yes'],
            ['nativeClientEnabled' => 1],
        ] as $body) {
            self::assertBad($this->controller($body)->updateOauthClients());
        }
        $this->assertSame([], array_intersect_key($this->fx->config->app['mcp'] ?? [], ['oauth_client_hosts' => 1, 'oauth_native_client_enabled' => 1]));
    }

    /** The groups that may read the server log: none by default (administrators only), every group offered. */
    public function testLogsAccessShowsTheListedGroupsAndTheLogType(): void {
        $data = $this->controller()->logsAccess()->getData();
        $this->assertSame([], $data['groups']);
        $this->assertTrue($data['available']);
        $this->assertSame('file', $data['logType']);
        $this->assertSame(['sales', 'admin'], array_column($data['allGroups'], 'id'));

        $this->fx->config->system['log_type'] = 'systemd';
        $data = $this->controller()->logsAccess()->getData();
        $this->assertFalse($data['available']);
        $this->assertSame('systemd', $data['logType']);
    }

    public function testUpdateLogsAccessStoresTheGroupsAndOpensTheGate(): void {
        $this->fx->addUser('tina', 'Tina', 'tina@corp.example', true, ['sales']);
        $this->assertFalse($this->fx->logsAccess()->permits('tina'));

        $response = $this->controller(['groups' => ['sales'], 'version' => 0, 'previous' => []])->updateLogsAccess();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['sales'], $response->getData()['groups']);
        $this->assertTrue($this->fx->logsAccess()->permits('tina'));
        $this->assertSame([], $this->controller(['groups' => [], 'version' => 1, 'previous' => ['sales']])->updateLogsAccess()->getData()['groups']);
        $this->assertFalse($this->fx->logsAccess()->permits('tina'));
    }

    public function testUpdateLogsAccessRefusesBadInputWithoutWriting(): void {
        $this->controller(['groups' => ['sales'], 'version' => 0, 'previous' => []])->updateLogsAccess();
        foreach ([[], ['groups' => 'sales', 'version' => 1, 'previous' => ['sales']], ['groups' => ['sales', 'ghost'], 'version' => 1, 'previous' => ['sales']], ['groups' => [3], 'version' => 1, 'previous' => ['sales']],
            ['groups' => ['a' => 'sales'], 'version' => 1, 'previous' => ['sales']], ['groups' => ['sales']], ['groups' => ['sales'], 'version' => 1, 'previous' => 'sales']] as $body) {
            self::assertBad($this->controller($body)->updateLogsAccess());
        }
        $this->assertSame(['sales'], $this->fx->logsAccess()->groups());
    }

    /** Two tabs or two administrators: the second save, based on the old list, gets 409 and the current state. */
    public function testAStaleSaveIsAConflictWithTheCurrentState(): void {
        $this->controller(['groups' => ['admin', 'sales'], 'version' => 0, 'previous' => []])->updateLogsAccess();
        $this->controller(['groups' => ['admin'], 'version' => 1, 'previous' => ['admin', 'sales']])->updateLogsAccess();

        $response = $this->controller(['groups' => ['admin', 'sales'], 'version' => 1, 'previous' => ['admin', 'sales']])->updateLogsAccess();

        $this->assertSame(409, $response->getStatus());
        $this->assertSame('Conflict', $response->getData()['error']);
        $this->assertSame(['admin'], $response->getData()['state']['groups']);
        $this->assertSame(['admin'], $this->fx->logsAccess()->groups());
    }

    /** A listed group deleted afterwards is reported, so the page can show it and offer its removal. */
    public function testADeletedGroupIsReportedAsMissing(): void {
        $this->controller(['groups' => ['sales'], 'version' => 0, 'previous' => []])->updateLogsAccess();
        unset($this->fx->groups['sales']);

        $data = $this->controller()->logsAccess()->getData();
        $this->assertSame(['sales'], $data['groups']);
        $this->assertSame(['sales'], $data['missing']);
        $this->assertSame(200, $this->controller(['groups' => [], 'version' => 1, 'previous' => ['sales']])->updateLogsAccess()->getStatus());
        $this->assertSame([], $this->controller()->logsAccess()->getData()['missing']);
    }
    public function testVersionIsReturnedAndRequiredAsANonNegativeInteger(): void {
        $this->assertSame(0, $this->controller()->logsAccess()->getData()['version']);
        foreach ([null, -1, '0', 0.0, true] as $version) {
            self::assertBad($this->controller(['groups' => ['sales'], 'previous' => [], 'version' => $version])->updateLogsAccess());
        }
        self::assertBad($this->controller(['groups' => ['sales'], 'previous' => []])->updateLogsAccess());
        $saved = $this->controller(['groups' => ['sales'], 'previous' => [], 'version' => 0])->updateLogsAccess();
        $this->assertSame(1, $saved->getData()['version']);
        $conflict = $this->controller(['groups' => [], 'previous' => ['sales'], 'version' => 0])->updateLogsAccess();
        $this->assertSame(409, $conflict->getStatus());
        $this->assertSame(1, $conflict->getData()['state']['version']);
        $this->assertSame(['sales'], $conflict->getData()['state']['groups']);
    }

}
