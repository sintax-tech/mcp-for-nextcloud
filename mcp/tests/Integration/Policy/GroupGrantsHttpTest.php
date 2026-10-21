<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/** Issue #11: actual JSON requests through Apache, including the preview receipt and a fresh read. */
final class GroupGrantsHttpTest extends IntegrationTestCase {
    public function testHttpSavePersistsCanConnectAndModuleGrants(): void {
        $gid = 'mcp-it-http-' . bin2hex(random_bytes(6));
        $group = Server::get(IGroupManager::class)->createGroup($gid);
        $this->assertNotNull($group);
        $config = Server::get(IAppConfig::class);
        $hadLegacy = $config->hasKey('mcp', 'allowed_groups');
        $legacy = $config->getValueString('mcp', 'allowed_groups', '');
        $group->addUser(Server::get(IUserManager::class)->get(Fixtures::ALICE));
        try {
            // The old appconfig key is not an authorization source in 1.1.0.
            $config->setValueString('mcp', 'allowed_groups', $gid);
            $context = Server::get(PolicyResolver::class)->begin(Fixtures::ALICE);
            try {
                $this->assertFalse($context->eligible()->allowed);
            } finally {
                $context->close();
            }
            $headers = ['Authorization: ' . LoginFactory::basic('admin')->authorization(),
                'OCS-APIRequest: true', 'Content-Type: application/json', 'Accept: application/json'];
            $request = function (string $method, string $path, ?array $body = null) use ($headers): array {
                $response = Http::request($method, Http::BASE . '/index.php/apps/mcp/api/' . $path, $headers,
                    $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
                $this->assertSame(200, $response['status'], $method . ' ' . $path . ': ' . $response['body']);
                return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            };
            $before = $request('GET', 'group-grants?gid=' . rawurlencode($gid));
            $grants = ['_system' => ['eligible'], 'notes' => ['edit']];
            $body = ['gid' => $gid, 'expectedRevision' => $before['revision'], 'grants' => $grants];
            $preview = $request('POST', 'group-grants/preview', $body);
            $request('PUT', 'group-grants', $body + ['previewId' => $preview['previewId']]);
            $this->assertSame($grants, $request('GET', 'group-grants?gid=' . rawurlencode($gid))['grants']);
            $this->assertSame($grants, (new GroupGrantsMapper($this->openIndependentConnection()))->forGroups([$gid])[0]['grants']);
            $context = Server::get(PolicyResolver::class)->begin(Fixtures::ALICE);
            try {
                $this->assertTrue($context->eligible()->allowed);
                $this->assertSame([$gid], $context->eligible()->groupIds);
                $this->assertTrue($context->granted('notes', 'edit')->allowed);
            } finally {
                $context->close();
            }
        } finally {
            if ($hadLegacy) {
                $config->setValueString('mcp', 'allowed_groups', $legacy);
            } else {
                $config->deleteKey('mcp', 'allowed_groups');
            }
            $group?->delete();
        }
    }
}
