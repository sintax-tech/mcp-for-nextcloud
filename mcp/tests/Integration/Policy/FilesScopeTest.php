<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;
use Throwable;

/**
 * B5 on the real server (S2, S5, S6, F1 of the ticket review): the Files scope is saved by the container's writer, now
 * open for files, which checks every folder against alice's native tree; the tools, nc://files and a checkout link
 * are then used over HTTP through the MCP endpoint, which opens the policy operation of alice. Outside the scope a
 * node answers exactly like a missing one; "/" lists the selected folders; a destination may only create folders at or
 * below a selected folder, and a refusal creates nothing. The proof with shares across users is B8's.
 */
final class FilesScopeTest extends IntegrationTestCase {
    private string $run = '';
    /** @var list<string> top folders of alice created by the test */
    private array $created = [];
    /** @var array<string, ?string> raw values put back after the test; null = absent */
    private array $before = [];
    private McpClient $mcp;

    protected function setUp(): void {
        parent::setUp();
        $this->run = bin2hex(random_bytes(4));
        $apps = Server::get(IAppConfig::class);
        $users = Server::get(IUserConfig::class);
        $this->before['service'] = $apps->hasKey('mcp', GrantPolicy::SERVICE_KEY) ? $apps->getValueString('mcp', GrantPolicy::SERVICE_KEY) : null;
        foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_files_create', 'grant_files_edit'] as $key) {
            $this->before[$key] = $users->hasKey(Fixtures::ALICE, 'mcp', $key) ? $users->getValueString(Fixtures::ALICE, 'mcp', $key) : null;
        }
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible(Fixtures::ALICE, true);
        $policy->setConnected(Fixtures::ALICE, true);
        $policy->setGrant(Fixtures::ALICE, 'files', 'create', true);
        $policy->setGrant(Fixtures::ALICE, 'files', 'edit', true);
        $this->mcp = new McpClient(LoginFactory::basic(Fixtures::ALICE));
    }

    protected function tearDown(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE))))->executeStatement();
        }
        $config = Server::get(IConfig::class);
        $this->before['service'] === null ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->before['service']);
        foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_files_create', 'grant_files_edit'] as $key) {
            $this->before[$key] === null ? $config->deleteUserValue(Fixtures::ALICE, 'mcp', $key) : $config->setUserValue(Fixtures::ALICE, 'mcp', $key, $this->before[$key]);
        }
        Server::get(IUserConfig::class)->clearCache(Fixtures::ALICE, false);
        Server::get(IAppConfig::class)->clearCache(false);
        foreach ($this->created as $name) {
            try {
                $this->home()->get($name)->delete();
            } catch (Throwable) {
                // Already gone.
            }
        }
        parent::tearDown();
    }

    private function home(): Folder {
        return Server::get(IRootFolder::class)->getUserFolder(Fixtures::ALICE);
    }

    /** A top folder of alice for this run, with the given files below it. */
    private function folder(string $name, array $files = []): Folder {
        $name .= '-' . $this->run;
        $this->created[] = $name;
        $folder = $this->home()->newFolder($name);
        foreach ($files as $path => $content) {
            $parent = dirname($path) === '.' ? $folder : $this->ensure($folder, dirname($path));
            $parent->newFile(basename($path), $content);
        }
        return $folder;
    }

    private function ensure(Folder $base, string $relative): Folder {
        foreach (explode('/', $relative) as $segment) {
            $base = $base->nodeExists($segment) ? $base->get($segment) : $base->newFolder($segment);
        }
        return $base;
    }

    /** @param list<Folder>|null $folders */
    private function scope(?array $folders): void {
        Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'], 'admin',
            Fixtures::ALICE, 'files', $folders === null ? null : array_map(static fn (Folder $f): string => (string)$f->getId(), $folders));
    }

    /** @return array{int, string} HTTP status and JSON-RPC error message of resources/read */
    private function resource(string $path): array {
        $response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
            'Authorization: ' . LoginFactory::basic(Fixtures::ALICE)->authorization(), 'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'nc://files' . $path]], JSON_THROW_ON_ERROR));
        $body = json_decode($response['body'], true);
        return [$response['status'], is_array($body) ? (string)($body['error']['message'] ?? 'served') : 'unreadable'];
    }

    private function relative(Folder $folder): string {
        return '/' . basename($folder->getPath());
    }

    public function testS2S5TheToolsReachOnlyTheSelectedFoldersAndCreateNothingOutside(): void {
        $projects = $this->folder('mcp-it-projects', ['plan.md' => "# Plan {$this->run}"]);
        $other = $this->folder('mcp-it-other', ['secret.md' => "# Secret {$this->run}"]);
        $nested = $this->folder('mcp-it-a', ['B/C/.keep' => '']);
        $c = $this->ensure($nested, 'B/C');
        $p = $this->relative($projects);
        $o = $this->relative($other);
        $a = $this->relative($nested);

        // A link minted before the scope: it is judged by the scope of its use.
        $checkout = $this->mcp->write('files_checkout', ['path' => "$o/secret.md"]);
        $this->assertTrue($checkout->ok(), $checkout->describe());
        $download = (string)parse_url((string)$checkout->json()['download_url'], PHP_URL_PATH);

        $this->scope([$projects, $c]);

        $listed = array_column($this->mcp->tool('files_list', ['path' => '/'])->json(), 'path');
        sort($listed);
        $this->assertSame(["$a/B/C", $p], $listed, '"/" lists the selected folders at their real paths');
        $this->assertTrue($this->mcp->tool('files_read', ['path' => "$p/plan.md"])->ok());
        foreach ([['files_read', ['path' => "$o/secret.md"], ['path' => "$o/missing.md"]], ['files_list', ['path' => $a], ['path' => "$a-missing"]],
            ['files_list', ['path' => "$a/B"], ['path' => "$a/missing"]]] as [$name, $outside, $missing]) {
            $refused = $this->mcp->tool($name, $outside);
            $this->assertTrue($refused->isError(), $refused->describe());
            $this->assertSame($this->mcp->tool($name, $missing)->fingerprint(), $refused->fingerprint(), $name);
        }
        $found = array_column($this->mcp->tool('files_search', ['query' => $this->run, 'mode' => 'name'])->json()['files'] ?? [], 'path');
        $this->assertNotContains("$o/secret.md", $found);
        $this->assertSame($this->resource("$o/missing.md"), $this->resource("$o/secret.md"));
        $this->assertSame('served', $this->resource("$p/plan.md")[1]);
        $this->assertSame(404, Http::request('GET', Http::BASE . $download)['status'], 'the link issued before the scope is refused');

        // S5: inside a nested selected folder a new level is created without its ancestors becoming reachable.
        $made = $this->mcp->write('files_mkdir', ['path' => "$a/B/C/new/deeper"]);
        $this->assertTrue($made->ok(), $made->describe());
        $this->assertTrue($c->nodeExists('new/deeper'));
        foreach (["$a/new", "$o/x/y", "/mcp-it-fresh-{$this->run}/x"] as $path) {
            $refused = $this->mcp->write('files_mkdir', ['path' => $path]);
            $this->assertTrue($refused->isError(), $refused->describe());
        }
        $this->assertFalse($nested->nodeExists('new'));
        $this->assertFalse($other->nodeExists('x'));
        $this->assertFalse($this->home()->nodeExists("mcp-it-fresh-{$this->run}"), 'no folder above the boundary');
        $copy = $this->mcp->write('files_copy', ['from' => "$p/plan.md", 'to' => "$o/plan.md"]);
        $this->assertTrue($copy->isError(), $copy->describe());
        $this->assertFalse($other->nodeExists('plan.md'));

        // S5: moved inside the scope outside MCP, the file is reachable from the next call on.
        $other->get('secret.md')->move($projects->getPath() . '/secret.md');
        $this->assertTrue($this->mcp->tool('files_read', ['path' => "$p/secret.md"])->ok());

        // S6: restricted to nothing reaches nothing; unrestricted again reaches everything.
        $this->scope([]);
        $this->assertSame([], $this->mcp->tool('files_list', ['path' => '/'])->json());
        $this->assertTrue($this->mcp->tool('files_read', ['path' => "$p/plan.md"])->isError());
        $this->scope(null);
        $this->assertTrue($this->mcp->tool('files_list', ['path' => $a])->ok());
    }
}
