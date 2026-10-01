<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCP\Constants;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * In-memory share store behind an IShareManager double.
 *
 * Reads answer getSharesBy() the way the default provider does with reshares=false: the shares the user initiated, of
 * one type, optionally of one node, sliced by limit and offset. Writes go through newShare(), createShare() and
 * updateShare() on stateful share doubles, so a test reads back exactly what the code set; deleteShare() removes from
 * the store. Every call is recorded, and the administrator's sharing settings are public properties.
 */
final class FakeShares {
    /** @var list<IShare> */
    public array $shares = [];
    /** @var list<array{uid:string, type:int, node:int|null, reshares:bool, limit:int, offset:int}> */
    public array $calls = [];
    /** @var list<array{0:string, 1:IShare}> createShare, updateShare and deleteShare calls, in order */
    public array $writes = [];
    /** Whether the room provider is missing, as when Talk is disabled: getSharesBy throws for TYPE_ROOM. */
    public bool $roomUnavailable = false;
    /** Exception createShare() or updateShare() throws instead of writing, as the core would. */
    public ?\Throwable $failWrite = null;
    /** @var array<string, mixed> administrator settings, by IShareManager getter name */
    public array $settings = [
        'shareApiEnabled' => true,
        'allowGroupSharing' => true,
        'shareWithGroupMembersOnly' => false,
        'shareWithGroupMembersOnlyExcludeGroupsList' => [],
        'shareApiInternalDefaultExpireDate' => false,
        'shareApiInternalDefaultExpireDateEnforced' => false,
        'shareApiInternalDefaultExpireDays' => 7,
        'shareApiAllowLinks' => true,
        'shareApiLinkEnforcePassword' => false,
        'shareApiLinkDefaultExpireDate' => false,
        'shareApiLinkDefaultExpireDateEnforced' => false,
        'shareApiLinkDefaultExpireDays' => 7,
    ];
    /** @var list<string> users the administrator excluded from sharing */
    public array $sharingDisabledFor = [];
    private int $nextId = 1;
    /** Marks a stored password as a hash, the way the core's hasher output differs from any plain password. */
    private const HASH_PREFIX = 'hash$';

    public function __construct(private TestCase $test) {}

    /**
     * Adds an existing share to the store.
     *
     * @param array{type?:int, with?:string|null, node?:int, nodeType?:string, by?:string, owner?:string, permissions?:int, password?:string|null, expires?:\DateTime|null, token?:string|null, withName?:string|null, note?:string, nodeObject?:Node} $o
     * @return IShare the share double
     */
    public function add(array $o = []): IShare {
        $id = $this->nextId++;
        $share = $this->share([
            'id' => (string)$id,
            'shareType' => $o['type'] ?? IShare::TYPE_USER,
            'sharedWith' => array_key_exists('with', $o) ? $o['with'] : 'bruno',
            'sharedWithDisplayName' => $o['withName'] ?? null,
            'nodeId' => $o['node'] ?? 0,
            'nodeType' => $o['nodeType'] ?? 'file',
            'node' => $o['nodeObject'] ?? null,
            'sharedBy' => $o['by'] ?? 'alice',
            'shareOwner' => $o['owner'] ?? 'alice',
            'permissions' => $o['permissions'] ?? Constants::PERMISSION_READ,
            'password' => $o['password'] ?? null,
            'expirationDate' => $o['expires'] ?? null,
            'token' => $o['token'] ?? null,
            'note' => $o['note'] ?? '',
        ]);
        $this->shares[] = $share;
        return $share;
    }

    /** @return IShareManager the manager double reading and writing this store */
    public function manager(): IShareManager {
        $manager = $this->mock(IShareManager::class);
        $manager->method('getSharesBy')->willReturnCallback(
            function (string $uid, int $type, ?Node $path = null, bool $reshares = false, int $limit = 50, int $offset = 0): array {
                $this->calls[] = ['uid' => $uid, 'type' => $type, 'node' => $path?->getId(), 'reshares' => $reshares, 'limit' => $limit, 'offset' => $offset];
                if ($type === IShare::TYPE_ROOM && $this->roomUnavailable) {
                    throw new \RuntimeException('No share provider for share type 10');
                }
                $found = array_values(array_filter($this->shares, static fn (IShare $s): bool => $s->getSharedBy() === $uid
                    && $s->getShareType() === $type
                    && ($path === null || $s->getNodeId() === $path->getId())));
                return array_slice($found, $offset, $limit < 0 ? null : $limit);
            },
        );
        $manager->method('newShare')->willReturnCallback(fn (): IShare => $this->share([]));
        $manager->method('createShare')->willReturnCallback(function (IShare $share): IShare {
            $this->writes[] = ['create', $share];
            if ($this->failWrite !== null) {
                throw $this->failWrite;
            }
            $share->setId((string)$this->nextId++);
            $share->setShareOwner($share->getNode()->getOwner()?->getUID() ?? (string)$share->getSharedBy());
            if ($share->getShareType() === IShare::TYPE_LINK) {
                // As Manager::createShare: a unique token, and the plain password replaced by its hash.
                $share->setToken('tok' . $share->getId());
                $this->hashPassword($share);
            }
            $this->shares[] = $share;
            return $share;
        });
        $manager->method('updateShare')->willReturnCallback(function (IShare $share): IShare {
            $this->writes[] = ['update', $share];
            if ($this->failWrite !== null) {
                throw $this->failWrite;
            }
            // As Manager::updateSharePasswordIfNeeded: a new plain password is hashed, the stored hash is left alone.
            $this->hashPassword($share);
            return $share;
        });
        $manager->method('deleteShare')->willReturnCallback(function (IShare $share): void {
            $this->writes[] = ['delete', $share];
            if ($this->failWrite !== null) {
                throw $this->failWrite;
            }
            $this->shares = array_values(array_filter($this->shares, static fn (IShare $s): bool => $s !== $share));
        });
        foreach ($this->settings as $getter => $_) {
            $manager->method($getter)->willReturnCallback(fn () => $this->settings[$getter]);
        }
        $manager->method('sharingDisabledForUser')->willReturnCallback(fn (?string $uid): bool => in_array($uid, $this->sharingDisabledFor, true));
        return $manager;
    }

    /** @return \OCA\Mcp\Service\VisibilityGuard a guard that hides nothing, for tests where visibility is not the point */
    public function guardShowingAll(): \OCA\Mcp\Service\VisibilityGuard {
        $guard = $this->mock(\OCA\Mcp\Service\VisibilityGuard::class);
        $guard->method('isVisible')->willReturn(true);
        $guard->method('filter')->willReturnCallback(static fn (iterable $nodes): array => is_array($nodes) ? array_values($nodes) : iterator_to_array($nodes, false));
        return $guard;
    }

    /**
     * A share double whose getters read and setters write one state array, so what the code sets is what it reads back.
     *
     * @param array<string, mixed> $state initial values by property name (id, shareType, sharedWith, node, ...)
     * @return IShare the share
     */
    private function share(array $state): IShare {
        $share = $this->mock(IShare::class);
        $get = static function (string $key) use (&$state): mixed {
            return $state[$key] ?? null;
        };
        $set = static function (string $key) use (&$state, $share): \Closure {
            return static function (mixed $value) use (&$state, $key, $share): IShare {
                $state[$key] = $value;
                return $share;
            };
        };
        foreach (['shareType', 'sharedWith', 'sharedWithDisplayName', 'sharedBy', 'shareOwner', 'permissions', 'password',
            'expirationDate', 'token', 'note', 'id'] as $property) {
            $share->method('get' . ucfirst($property))->willReturnCallback(static fn () => $get($property));
            $share->method('set' . ucfirst($property))->willReturnCallback($set($property));
        }
        $share->method('getFullId')->willReturnCallback(static function () use ($get): string {
            $id = $get('id');
            if ($id === null) {
                throw new \UnexpectedValueException('A share needs an id to have a full id');
            }
            return 'ocinternal:' . $id;
        });
        $share->method('setNode')->willReturnCallback(static function (Node $node) use (&$state, $share): IShare {
            $state['node'] = $node;
            $state['nodeId'] = $node->getId();
            $state['nodeType'] = $node instanceof \OCP\Files\Folder ? 'folder' : 'file';
            return $share;
        });
        $share->method('getNode')->willReturnCallback(static fn () => $get('node') ?? throw new \OCP\Files\NotFoundException());
        $share->method('getNodeId')->willReturnCallback(static fn (): int => (int)$get('nodeId'));
        $share->method('getNodeType')->willReturnCallback(static fn () => $get('nodeType') ?? 'file');
        $share->method('getNote')->willReturnCallback(static fn (): string => (string)$get('note'));
        return $share;
    }

    /** @return string the hash this store keeps instead of a plain password, as the core's IHasher would */
    public static function hashOf(string $password): string {
        return self::HASH_PREFIX . hash('sha256', $password);
    }

    /** Replaces a plain password of the share by its hash; a hash already stored stays as it is. */
    private function hashPassword(IShare $share): void {
        $password = $share->getPassword();
        if (is_string($password) && $password !== '' && !str_starts_with($password, self::HASH_PREFIX)) {
            $share->setPassword(self::hashOf($password));
        }
    }

    private function mock(string $interface): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $interface);
    }
}
