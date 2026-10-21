<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\DavClient;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base of the exclusion proof (issue #4, A4). Everything the tools see is real: files, groupfolders, local external
 * storage, shares, system tags and core events live in the Nextcloud container; the tools are called over HTTP with a
 * real app password or a real OAuth access token, and files change from outside the tools through WebDAV or the
 * server's own Files API. Setup uses the server's Files and tag APIs directly, as an administrator would through
 * their own interfaces; the exclusion list is written by the same VisibilityGuard method the admin screen calls.
 */
abstract class ExclusionTestCase extends IntegrationTestCase {
    protected const ALICE = Fixtures::ALICE;
    protected const BOB = Fixtures::BOB;

    /** Mount point (first path segment in the user's tree) of every place a file can live; '' is the home itself. */
    protected const EXTERNAL = 'mcp-it-ext-wide';
    protected const ALIAS = 'mcp-it-ext-alias';
    protected const NARROW = 'mcp-it-ext-narrow';

    private static bool $ready = false;
    private static int $tagId = 0;
    /** @var array<string, Credential> */
    private static array $logins = [];

    protected string $run = '';
    /** @var list<callable():void> */
    private array $cleanups = [];

    protected function setUp(): void {
        parent::setUp();
        if (!self::$ready) {
            self::prepareWorld();
        }
        $this->run = 'it-' . bin2hex(random_bytes(4));
        $this->setHidden([]);
    }

    protected function tearDown(): void {
        try {
            $this->setHidden([]);
        } catch (Throwable) {
            // The next test sets its own configuration.
        }
        foreach (array_reverse($this->cleanups) as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable) {
                // Best effort: a failed cleanup must not hide the failure that is being reported.
            }
        }
        $this->cleanups = [];
        foreach ([self::ALICE, self::BOB] as $uid) {
            foreach (['', $this->groupfolder(), self::EXTERNAL, self::NARROW] as $mount) {
                $this->deleteQuietly($uid, ($mount === '' ? '' : $mount . '/') . $this->run);
            }
        }
        parent::tearDown();
    }

    private static function prepareWorld(): void {
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        foreach ([self::ALICE, self::BOB] as $uid) {
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            foreach (['create', 'move', 'edit', 'restore', 'share', 'link'] as $operation) {
                $policy->setGrant($uid, 'files', $operation, true);
            }
            foreach (['reply', 'attach', 'quote', 'create'] as $operation) {
                $policy->setGrant($uid, 'talk', $operation, true);
            }
            foreach (['create', 'edit', 'move', 'delete'] as $operation) {
                $policy->setGrant($uid, 'notes', $operation, true);
            }
        }
        self::$tagId = (int)Server::get(ISystemTagManager::class)
            ->createTag('mcp-it-excluded-' . bin2hex(random_bytes(3)), true, true)->getId();
        self::$ready = true;
    }

    protected function groupfolder(): string {
        $name = getenv('MCP_IT_GF_NAME');
        return is_string($name) && $name !== '' ? $name : 'mcp-it-gf';
    }

    /** @return array<string, string> the places a file can live, by the first segment of its path ('' for the home) */
    protected function arenas(): array {
        return ['home' => '', 'groupfolder' => $this->groupfolder(), 'external' => self::EXTERNAL, 'alias' => self::ALIAS, 'narrow' => self::NARROW];
    }

    // --- logins and clients -------------------------------------------------------------------------------------

    protected function login(string $uid, string $kind = 'basic'): Credential {
        return self::$logins[$uid . '/' . $kind] ??= $kind === 'bearer' ? LoginFactory::bearer($uid) : LoginFactory::basic($uid);
    }

    protected function mcp(string $uid = self::ALICE, string $kind = 'basic'): McpClient {
        return new McpClient($this->login($uid, $kind));
    }

    protected function dav(string $uid = self::ALICE): DavClient {
        return new DavClient($this->login($uid));
    }

    // --- files and tags -----------------------------------------------------------------------------------------

    protected function userFolder(string $uid): Folder {
        return Server::get(IRootFolder::class)->getUserFolder($uid);
    }

    /** @return string the path a tool takes, inside the run folder of a place: path('groupfolder', 'a', 'b.txt') */
    protected function path(string $arena, string ...$parts): string {
        $mount = $this->arenas()[$arena];
        return '/' . implode('/', array_filter([$mount, $this->run, ...$parts], static fn (string $s): bool => $s !== ''));
    }

    /** Fresh node at a path of the user's tree (never a node held from before an outside change). */
    protected function node(string $uid, string $path): Node {
        return $this->userFolder($uid)->get(trim($path, '/'));
    }

    protected function exists(string $uid, string $path): bool {
        return $this->userFolder($uid)->nodeExists(trim($path, '/'));
    }

    protected function ensureFolder(string $uid, string $path): Folder {
        $folder = $this->userFolder($uid);
        foreach (array_filter(explode('/', $path), static fn (string $s): bool => $s !== '') as $segment) {
            $next = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
            if (!$next instanceof Folder) {
                $this->fail("$path crosses a file at $segment");
            }
            $folder = $next;
        }
        return $folder;
    }

    protected function makeFolder(string $arena, string $relative = '', string $uid = self::ALICE): Folder {
        $path = $this->path($arena, ...($relative === '' ? [] : [$relative]));
        $this->assertMountReachable($uid, $arena);
        return $this->ensureFolder($uid, $path);
    }

    protected function makeFile(string $arena, string $relative, string $content, string $uid = self::ALICE): File {
        $this->assertMountReachable($uid, $arena);
        $full = trim($this->path($arena, $relative), '/');
        $name = basename($full);
        $parent = $this->ensureFolder($uid, dirname($full) === '.' ? '' : dirname($full));
        $file = $parent->newFile($name, $content);
        $this->assertInstanceOf(File::class, $file);
        return $file;
    }

    private function assertMountReachable(string $uid, string $arena): void {
        $mount = $this->arenas()[$arena];
        if ($mount !== '' && !$this->userFolder($uid)->nodeExists($mount)) {
            $this->fail("The $arena mount '$mount' is not in the tree of $uid; check the provisioning log of this cell.");
        }
    }

    protected function tag(Node $node): void {
        Server::get(ISystemTagObjectMapper::class)->assignTags((string)$node->getId(), 'files', [(string)self::$tagId]);
    }

    protected function untag(Node $node): void {
        Server::get(ISystemTagObjectMapper::class)->unassignTags((string)$node->getId(), 'files', [(string)self::$tagId]);
    }

    protected function tagId(): int {
        return self::$tagId;
    }

    /** @param list<int|string> $tagIds exclusion list, written by VisibilityGuard as the admin screen does */
    protected function setHidden(array $tagIds): void {
        $this->newGuard()->setHiddenTagIds($tagIds);
    }

    /** Excludes the proof's tag. */
    protected function exclude(): void {
        $this->setHidden([self::$tagId]);
    }

    protected function newGuard(bool $withEvents = false): VisibilityGuard {
        return new VisibilityGuard(
            Server::get(IConfig::class),
            Server::get(ISystemTagObjectMapper::class),
            Server::get(IRootFolder::class),
            Server::get(ISystemTagManager::class),
            Server::get(LoggerInterface::class),
            $withEvents ? Server::get(IEventDispatcher::class) : null,
        );
    }

    protected function deleteQuietly(string $uid, string $path): void {
        try {
            $folder = $this->userFolder($uid);
            if ($folder->nodeExists($path)) {
                $folder->get($path)->delete();
            }
        } catch (Throwable) {
            // Cleanup only.
        }
    }

    /** @param callable():void $cleanup */
    protected function later(callable $cleanup): void {
        $this->cleanups[] = $cleanup;
    }

    // --- shares -------------------------------------------------------------------------------------------------

    /** Shares a node of a user with another user through the server's share manager: read only, or with every permission the node has. */
    protected function shareToUser(Node $node, string $by, string $with, bool $everyPermission = false): IShare {
        $manager = Server::get(IManager::class);
        $share = $manager->newShare();
        $share->setNode($node)->setShareType(IShare::TYPE_USER)->setSharedWith($with)->setSharedBy($by)
            ->setPermissions($everyPermission ? $this->everyPermissionOf($node) : \OCP\Constants::PERMISSION_READ);
        $created = $manager->createShare($share);
        $this->later(static function () use ($manager, $created): void {
            $manager->deleteShare($created);
        });
        return $created;
    }

    /** Every permission a share of this node may carry: a file share cannot have create or delete. */
    private function everyPermissionOf(Node $node): int {
        $permissions = $node->getPermissions() & \OCP\Constants::PERMISSION_ALL;
        return $node instanceof File ? $permissions & ~(\OCP\Constants::PERMISSION_CREATE | \OCP\Constants::PERMISSION_DELETE) : $permissions;
    }

    /** Public link of a node, through the server's share manager. */
    protected function linkTo(Node $node, string $by): IShare {
        $manager = Server::get(IManager::class);
        $share = $manager->newShare();
        $share->setNode($node)->setShareType(IShare::TYPE_LINK)->setSharedBy($by)->setPermissions(\OCP\Constants::PERMISSION_READ);
        $created = $manager->createShare($share);
        $this->later(static function () use ($manager, $created): void {
            $manager->deleteShare($created);
        });
        return $created;
    }

    /** @return list<IShare> shares of a node made by a user to users, read back from the share manager */
    protected function userSharesOf(Node $node, string $by): array {
        return array_values(Server::get(IManager::class)->getSharesBy($by, IShare::TYPE_USER, $node, true, -1, 0));
    }

    // --- assertions ---------------------------------------------------------------------------------------------

    /** @return list<string>|null names listed at a path, null when the listing was refused */
    protected function listNames(McpClient $client, string $path): ?array {
        $reply = $client->tool('files_list', ['path' => $path]);
        if (!$reply->ok()) {
            return null;
        }
        $entries = $reply->json();
        $this->assertIsArray($entries, 'files_list is a JSON list: ' . $reply->describe());
        return array_values(array_map(static fn (array $e): string => (string)$e['name'], $entries));
    }

    /** The reference refusal: what the same client gets for a path that does not exist at all. */
    protected function missingReply(McpClient $client): ToolReply {
        return $client->tool('files_read', ['path' => '/mcp-it-missing-' . $this->run . '.txt']);
    }

    /** The reply is a refusal and, byte for byte, the one a path that does not exist gets. */
    protected function assertRefusedAsMissing(McpClient $client, ToolReply $reply, string $label, ?ToolReply $reference = null): void {
        $missing = $reference ?? $this->missingReply($client);
        $this->assertTrue($missing->isError(), 'The reference reply of a missing path is a refusal: ' . $missing->describe());
        $this->assertTrue($reply->isError(), "$label must be refused: " . $reply->describe());
        $this->assertSame($missing->fingerprint(), $reply->fingerprint(), "$label must answer exactly like a path that does not exist: same HTTP status and same whole result");
    }

    /** The reply is the generic refusal of a resource that is not there (the text may carry a tool-specific hint). */
    protected function assertRefusedAsNotFound(ToolReply $reply, string $label): void {
        $this->assertTrue($reply->isError(), "$label must be refused: " . $reply->describe());
        $this->assertStringStartsWith('Resource not found in Nextcloud.', $reply->text(), $label);
    }

    protected function assertReadable(McpClient $client, string $path, string $content, string $label): void {
        $reply = $client->tool('files_read', ['path' => $path]);
        $this->assertTrue($reply->ok(), "$label must be readable: " . $reply->describe());
        $this->assertStringContainsString($content, $reply->text(), $label);
    }

    protected function assertHiddenFromRead(McpClient $client, string $path, string $label): void {
        $this->assertRefusedAsMissing($client, $client->tool('files_read', ['path' => $path]), $label);
    }

    protected function assertNotInTree(McpClient $client, string $needle, string $label): void {
        $reply = $client->tool('files_tree', ['path' => '/']);
        $this->assertTrue($reply->ok(), 'files_tree answered: ' . $reply->describe());
        $this->assertStringNotContainsString($needle, $reply->text(), $label);
    }

    /** Records what a cell observed where the suite documents behavior instead of asserting it (collected by CI). */
    protected function observe(string $line): void {
        file_put_contents('/tmp/mcp-it-observations.txt', '[' . static::class . '] ' . $line . "\n", FILE_APPEND);
    }
}
