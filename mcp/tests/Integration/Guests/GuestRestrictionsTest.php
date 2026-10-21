<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\DavClient;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IShare;
use RuntimeException;

/**
 * The restrictions of the Guests app hold for a guest account on the MCP endpoint whatever the login: an app password
 * (Basic, logged in by the core, which dispatches the login event Guests listens to) or an access token of this app
 * (Bearer, validated inside the controller after every app booted, with no login event). Each scenario sends the
 * same calls with both logins and expects the same refusal.
 *
 * The Guests release is unpacked by `ci/harness.sh provision` and enabled here only for this class: it adds a user
 * and a group backend every other test would otherwise see.
 */
final class GuestRestrictionsTest extends IntegrationTestCase {
    private const GUEST = 'mcp-it-guest';
    private const MOUNT = 'mcp-it-guest-ext';
    /** Modules of an app Guests does not always allow, with that app (GrantMatrix::MODULE_APPS). */
    private const OTHER_APPS = ['calendar' => 'calendar', 'notes' => 'notes', 'talk' => 'spreed', 'deck' => 'deck', 'contacts' => 'contacts'];

    private static bool $enabledHere = false;
    /** @var array<string, Credential> */
    private static array $logins = [];

    /** @var list<string> ids of the shares a scenario created, removed afterwards */
    private array $shares = [];
    private ?int $mountId = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $apps = Server::get(IAppManager::class);
        if (!$apps->isEnabledForAnyone('guests')) {
            self::occ('app:enable', 'guests');
            self::$enabledHere = true;
        }
        // Created before this process looks the guest up: the backend remembers an account it did not find.
        putenv('OC_PASS=' . bin2hex(random_bytes(24)));
        try {
            self::occ('guests:add', Fixtures::ALICE, 'mcp-it-guest@example.org', '--uid=' . self::GUEST, '--password-from-env', '--display-name=Guest');
        } finally {
            putenv('OC_PASS');
        }
        self::registerGuestBackendHere($apps);
        $guest = Server::get(IUserManager::class)->get(self::GUEST)
            ?? throw new RuntimeException('The guest account is not visible in the test process: ' . implode(' ', self::occ('user:info', self::GUEST)));
        // A guest gets no disk space by default; the sharing scenario uploads one small file.
        $guest->setQuota('10 MB');
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible(self::GUEST, true);
        $policy->setConnected(self::GUEST, true);
        $policy->setGrant(self::GUEST, 'files', 'share', true);
        // files_checkout is a files.edit; alice is the control, a user who is no guest.
        $policy->setGrant(self::GUEST, 'files', 'edit', true);
        $policy->setEligible(Fixtures::ALICE, true);
        $policy->setConnected(Fixtures::ALICE, true);
        $policy->setGrant(Fixtures::ALICE, 'files', 'edit', true);
        foreach (self::OTHER_APPS + ['tasks' => 'dav'] as $module => $app) {
            $policy->setGrant(self::GUEST, $module, 'read', true);
        }
    }

    public static function tearDownAfterClass(): void {
        self::$logins = [];
        try {
            self::occ('user:delete', self::GUEST);
        } finally {
            foreach (['whitelist', 'usewhitelist', 'hide_users', 'allow_external_storage'] as $key) {
                self::occ('config:app:delete', 'guests', $key);
            }
            if (self::$enabledHere) {
                self::occ('app:disable', 'guests');
            }
        }
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void {
        if ($this->shares !== []) {
            // Rows of this test only; the shared file stays in the guest's home.
            $qb = $this->official()->getQueryBuilder();
            $qb->delete('share')->where($qb->expr()->in('id', $qb->createNamedParameter($this->shares, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
            $this->shares = [];
        }
        if ($this->mountId !== null) {
            self::occ('files_external:delete', '--yes', (string)$this->mountId);
            $this->mountId = null;
        }
        parent::tearDown();
    }

    /**
     * With a list of apps for guests that does not hold this app, the endpoint refuses the guest. Over Basic the refusal
     * is Guests' own error page, whose status the core turns into 500 on Nextcloud 35 (header() before
     * http_response_code()); over Bearer it is this app's 403.
     */
    public function testTheAppWhitelistRefusesTheGuestOnBothLogins(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'files_sharing,activity']);
        $basic = $this->mcp('basic')->listStatus();
        $bearer = $this->mcp('bearer')->listStatus();
        $this->observe("whitelist without mcp, tools/list: basic HTTP $basic, bearer HTTP $bearer");
        $this->assertNotSame(200, $basic, 'basic login of a guest while this app is not in the Guests whitelist');
        $this->assertSame(403, $bearer, 'bearer login of a guest while this app is not in the Guests whitelist');
    }

    /** Guests hides external storage from guests unless the administrator allows it. */
    public function testExternalStorageStaysHiddenFromTheGuestOnBothLogins(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'files_sharing,activity,mcp', 'allow_external_storage' => 'no']);
        $out = self::occ('files_external:create', '--output=json', '/' . self::MOUNT, 'local', 'null::null', '-c', 'datadir=/mcp-it-ext');
        $this->mountId = (int)trim((string)end($out));
        $this->assertGreaterThan(0, $this->mountId, 'files_external:create gave a mount id');
        self::occ('files_external:applicable', (string)$this->mountId, '--add-user=' . self::GUEST);
        $visible = [];
        foreach (['basic', 'bearer'] as $kind) {
            $reply = $this->mcp($kind)->tool('files_list', ['path' => '/']);
            $this->assertTrue($reply->ok(), "$kind files_list: " . $reply->raw);
            $visible[$kind] = in_array(self::MOUNT, array_column((array)$reply->json(), 'name'), true);
            $this->observe("external storage hidden for guests, $kind files_list /: mount " . ($visible[$kind] ? 'VISIBLE' : 'hidden'));
        }
        foreach ($visible as $kind => $seen) {
            $this->assertFalse($seen, "$kind login of a guest must not see the external storage");
        }
    }

    /** Control: with "hide other accounts" off, the same sharing goes through on both logins. */
    public function testWithoutHideUsersTheGuestSharesWithBobOnBothLogins(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'files_sharing,activity,mcp', 'hide_users' => 'no']);
        foreach (['basic', 'bearer'] as $kind) {
            [$accepted, $shared, $raw] = $this->shareWithBob($kind, 'hide_users off');
            $this->assertTrue($accepted, "$kind files_share with bob, control: $raw");
            $this->assertTrue($shared, "$kind: the control share reaches bob");
        }
    }

    /**
     * With "hide other accounts" on (the Guests default), Guests turns "share only with members of my groups" on for
     * guests. The guest shares no group with bob, so sharing a file with bob is refused.
     */
    public function testHideUsersRefusesASharingOutsideTheGuestsGroupsOnBothLogins(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'files_sharing,activity,mcp', 'hide_users' => 'yes']);
        $results = ['basic' => $this->shareWithBob('basic', 'hide_users on'), 'bearer' => $this->shareWithBob('bearer', 'hide_users on')];
        foreach ($results as $kind => [$accepted, $shared, $raw]) {
            $this->assertFalse($accepted, "$kind login of a guest must not share outside the guest's groups: $raw");
            $this->assertFalse($shared, "$kind: no share reaches bob");
        }
    }

    /** @return array{bool, bool, string} whether files_share answered success, whether bob got a share, and the raw answer */
    private function shareWithBob(string $kind, string $label): array {
        $path = '/guest-share-' . $kind . '-' . bin2hex(random_bytes(3)) . '.txt';
        (new DavClient(self::$logins['basic'] ??= LoginFactory::basic(self::GUEST)))->put($path, 'guest');
        // The plan, then its confirmation, as a client does; a refusal at either step is a refusal.
        $arguments = ['path' => $path, 'with' => 'user:' . Fixtures::BOB, 'permission' => 'view'];
        $reply = $this->mcp($kind)->tool('files_share', $arguments);
        $state = $reply->structured()['plan_state'] ?? null;
        if ($reply->ok() && is_string($state)) {
            $reply = $this->mcp($kind)->write('files_share', $arguments + ['plan_state' => $state]);
        }
        $shared = $this->sharedWithBob($path);
        $this->observe("$label, $kind files_share with bob: " . ($reply->ok() ? 'accepted' : 'refused') . '; share in the core: '
            . ($shared ? 'EXISTS' : 'none') . ($reply->ok() ? '' : ' (' . mb_substr($reply->raw, 0, 300) . ')'));
        return [$reply->ok(), $shared, $reply->raw];
    }

    /**
     * With only this app on the Guests list, a guest gets no tool of a module whose app is not on it, on both logins;
     * the call is refused as an unknown tool and the notes template is gone. Files (and tasks, over dav) stay: Guests
     * always allows those apps.
     */
    public function testWithOnlyThisAppOnTheGuestsListNoModuleOfAnotherAppIsReachable(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'mcp']);
        $seen = [];
        foreach (['basic', 'bearer'] as $kind) {
            $modules = $this->modules($kind);
            $call = $this->mcp($kind)->tool('calendar_list_calendars');
            $templates = $this->templates($kind);
            $this->observe("whitelist mcp, $kind: modules " . implode(',', $modules) . '; calendar_list_calendars '
                . ($call->ok() ? 'answered' : 'refused') . '; templates ' . implode(',', $templates));
            $seen[$kind] = [$modules, $call, $templates];
        }
        foreach ($seen as $kind => [$modules, $call, $templates]) {
            $this->assertSame([], array_values(array_intersect($modules, array_keys(self::OTHER_APPS))), "$kind: modules of apps off the Guests list");
            $this->assertContains('files', $modules, "$kind: files, which Guests always allows");
            $this->assertFalse($call->ok(), "$kind: calendar_list_calendars must be refused: " . $call->raw);
            $this->assertNotContains('nc://notes/{id}', $templates, "$kind: the notes template");
        }
    }

    /** With Calendar added to the list, the Calendar module comes back, and only it. */
    public function testWithCalendarOnTheGuestsListOnlyTheCalendarModuleIsAdded(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'mcp,calendar']);
        $seen = [];
        foreach (['basic', 'bearer'] as $kind) {
            $modules = $this->modules($kind);
            $call = $this->mcp($kind)->tool('calendar_list_calendars');
            $this->observe("whitelist mcp,calendar, $kind: modules " . implode(',', $modules) . '; calendar_list_calendars '
                . ($call->ok() ? 'answered' : 'refused'));
            $seen[$kind] = [$modules, $call];
        }
        foreach ($seen as $kind => [$modules, $call]) {
            $this->assertSame(['calendar'], array_values(array_intersect($modules, array_keys(self::OTHER_APPS))), "$kind: modules of apps of the list");
            $this->assertTrue($call->ok(), "$kind: calendar_list_calendars: " . $call->raw);
        }
    }

    /**
     * A checkout link carries no session, so Guests never restricted its owner. Links a guest got while this app was on
     * the Guests list stop working once it is taken off: the download and the upload are refused like an unknown link
     * and the file keeps its bytes. The links of alice, who is no guest, keep working.
     */
    public function testTakingThisAppOffTheGuestsListStopsTheGuestsEarlierCheckoutLinks(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'files_sharing,activity,mcp']);
        $links = [];
        foreach (['basic', 'bearer'] as $kind) {
            $path = '/guest-checkout-' . $kind . '-' . bin2hex(random_bytes(3)) . '.txt';
            $this->dav(self::GUEST)->put($path, 'original');
            $links[$kind] = [$path, $this->checkout($this->mcp($kind), $path)];
        }
        $alicePath = '/alice-checkout-' . bin2hex(random_bytes(3)) . '.txt';
        $this->dav(Fixtures::ALICE)->put($alicePath, 'original');
        $alice = $this->checkout(new McpClient(self::$logins['alice'] ??= LoginFactory::basic(Fixtures::ALICE)), $alicePath);

        $this->guests(['whitelist' => 'files_sharing,activity']);
        $seen = [];
        foreach ($links as $kind => [$path, [$download, $upload]]) {
            $got = Http::request('GET', $download);
            $put = Http::request('PUT', $upload, ['Content-Type: application/octet-stream'], 'rewritten');
            $kept = Http::request('GET', Http::BASE . '/remote.php/dav/files/' . self::GUEST . $path,
                ['Authorization: ' . self::$logins['basic']->authorization()])['body'];
            $this->observe("mcp taken off the list, $kind link of the guest: download HTTP {$got['status']}, upload HTTP {$put['status']}, file holds \"$kept\"");
            $seen[$kind] = [$got['status'], $put['status'], $kept];
        }
        $control = Http::request('GET', $alice[0]);
        $this->observe("mcp taken off the list, link of alice (no guest): download HTTP {$control['status']}");
        foreach ($seen as $kind => [$got, $put, $kept]) {
            $this->assertSame(404, $got, "$kind link of the guest: download");
            $this->assertSame(404, $put, "$kind link of the guest: upload");
            $this->assertSame('original', $kept, "$kind: the file keeps its bytes");
        }
        $this->assertSame(200, $control['status'], 'the link of alice, who is no guest');
        $this->assertSame('original', $control['body']);
    }

    /** External storage hidden after the link was issued: the link no longer reaches a file on it. */
    public function testExternalStorageHiddenAfterTheCheckoutIsOutOfReachOfTheLink(): void {
        $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'files_sharing,activity,mcp', 'allow_external_storage' => 'yes']);
        $out = self::occ('files_external:create', '--output=json', '/' . self::MOUNT, 'local', 'null::null', '-c', 'datadir=/mcp-it-ext');
        $this->mountId = (int)trim((string)end($out));
        self::occ('files_external:applicable', (string)$this->mountId, '--add-user=' . self::GUEST);
        // A full setup of the guest's files records the new mount in the mount cache: on Nextcloud 32 a file of a mount
        // the cache does not know yet is not found by its id.
        self::occ('files:scan', self::GUEST);
        $path = '/' . self::MOUNT . '/guest-ext-' . bin2hex(random_bytes(3)) . '.txt';
        $this->dav(self::GUEST)->put($path, 'external');
        [$download] = $this->checkout($this->mcp('bearer'), $path);

        $this->guests(['allow_external_storage' => 'no']);
        $got = Http::request('GET', $download);
        $read = ['basic' => $this->mcp('basic')->tool('files_read', ['path' => $path]), 'bearer' => $this->mcp('bearer')->tool('files_read', ['path' => $path])];
        $this->observe("external storage hidden after the checkout, guest link: download HTTP {$got['status']}; files_read by path: basic "
            . ($read['basic']->ok() ? 'READ' : 'refused') . ', bearer ' . ($read['bearer']->ok() ? 'READ' : 'refused'));
        $this->assertSame(404, $got['status'], 'the link of a file on hidden external storage');
        foreach ($read as $kind => $reply) {
            $this->assertFalse($reply->ok(), "$kind files_read of a file on hidden external storage: " . $reply->raw);
        }
    }

    private function dav(string $uid): DavClient {
        $key = $uid === self::GUEST ? 'basic' : 'alice';
        return new DavClient(self::$logins[$key] ??= LoginFactory::basic($uid));
    }

    /** @return array{string, string} the download and the upload link of a checkout of the path */
    private function checkout(McpClient $client, string $path): array {
        $reply = $client->write('files_checkout', ['path' => $path]);
        $this->assertTrue($reply->ok(), 'files_checkout: ' . $reply->raw);
        $out = (array)$reply->json();
        return [Http::BASE . (string)parse_url((string)$out['download_url'], PHP_URL_PATH),
            Http::BASE . (string)parse_url((string)$out['upload_url'], PHP_URL_PATH)];
    }

    /** @return list<string> the modules (tool name prefix) of the tools/list answer, guide left out */
    private function modules(string $kind): array {
        $names = array_column((array)($this->rpc($kind, 'tools/list')['result']['tools'] ?? []), 'name');
        $this->assertNotSame([], $names, "$kind tools/list answered");
        $modules = array_unique(array_map(static fn (string $name): string => explode('_', $name, 2)[0], $names));
        sort($modules);
        return array_values(array_diff($modules, ['mcp']));
    }

    /** @return list<string> the uri templates of resources/templates/list */
    private function templates(string $kind): array {
        return array_column((array)($this->rpc($kind, 'resources/templates/list')['result']['resourceTemplates'] ?? []), 'uriTemplate');
    }

    /** @return array<string, mixed> the decoded JSON-RPC answer of one method without parameters */
    private function rpc(string $kind, string $method): array {
        $response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
            'Authorization: ' . (self::$logins[$kind] ??= $kind === 'bearer' ? LoginFactory::bearer(self::GUEST) : LoginFactory::basic(self::GUEST))->authorization(),
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method], JSON_THROW_ON_ERROR));
        $this->assertSame(200, $response['status'], "$kind $method: " . $response['body']);
        $decoded = json_decode($response['body'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, string> $values Guests settings for this scenario */
    private function guests(array $values): void {
        foreach ($values as $key => $value) {
            self::occ('config:app:set', 'guests', $key, '--value=' . $value);
        }
    }

    private function mcp(string $kind): McpClient {
        return new McpClient(self::$logins[$kind] ??= $kind === 'bearer' ? LoginFactory::bearer(self::GUEST) : LoginFactory::basic(self::GUEST));
    }

    /** Read from the share table, so this process never sets up the guest's files (Guests is not booted here). */
    private function sharedWithBob(string $path): bool {
        $qb = $this->official()->getQueryBuilder();
        $result = $qb->select('id')->from('share')
            ->where($qb->expr()->eq('uid_initiator', $qb->createNamedParameter(self::GUEST)))
            ->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter(Fixtures::BOB)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_USER)))
            ->andWhere($qb->expr()->eq('file_target', $qb->createNamedParameter($path)))
            ->executeQuery();
        $ids = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
        $result->closeCursor();
        array_push($this->shares, ...$ids);
        return $ids !== [];
    }

    private function observe(string $line): void {
        file_put_contents('/tmp/mcp-it-observations.txt', '[' . self::class . '] ' . $line . "\n", FILE_APPEND);
    }

    /**
     * The user backend of the guest accounts in this process too (the consent of the token, the policy setters and
     * the file checks look the guest up). The app was enabled after this process loaded its apps, so its classes and
     * backend are registered here by hand, as its boot would.
     */
    private static function registerGuestBackendHere(IAppManager $apps): void {
        $path = $apps->getAppPath('guests');
        if (!class_exists(\OCA\Guests\UserBackend::class)) {
            if (is_file($path . '/composer/autoload.php')) {
                require_once $path . '/composer/autoload.php';
            }
            spl_autoload_register(static function (string $class) use ($path): void {
                if (str_starts_with($class, 'OCA\\Guests\\')) {
                    $file = $path . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Guests\\'))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            });
        }
        $users = Server::get(IUserManager::class);
        foreach ($users->getBackends() as $backend) {
            if ($backend instanceof \OCA\Guests\UserBackend) {
                return;
            }
        }
        // Through the app's own container, which holds the app config the backend needs on every major.
        $users->registerBackend((new \OCA\Guests\AppInfo\Application())->getContainer()->get(\OCA\Guests\UserBackend::class));
    }

    /** @return list<string> output lines of one occ command, which must succeed */
    private static function occ(string ...$arguments): array {
        $command = 'php ' . escapeshellarg((getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/occ') . ' --no-interaction '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('occ ' . implode(' ', $arguments) . " failed ($status): " . implode("\n", $output));
        }
        return $output;
    }
}
