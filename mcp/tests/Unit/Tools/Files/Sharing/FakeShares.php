<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCP\Constants;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * In-memory share store behind an IShareManager double, answering getSharesBy() the way the default provider
 * does with reshares=false: the shares the user initiated, of one type, optionally of one node, sliced by
 * limit and offset. Every call is recorded so a test can prove what was (not) asked.
 */
final class FakeShares {
    /** @var list<IShare> */
    public array $shares = [];
    /** @var list<array{uid:string, type:int, node:int|null, reshares:bool, limit:int, offset:int}> */
    public array $calls = [];
    /** Whether the room provider is missing, as when Talk is disabled: getSharesBy throws for TYPE_ROOM. */
    public bool $roomUnavailable = false;
    private int $nextId = 1;

    public function __construct(private TestCase $test) {}

    /**
     * Adds a share to the store.
     *
     * @param array{type?:int, with?:string|null, node?:int, nodeType?:string, by?:string, owner?:string, permissions?:int, password?:string|null, expires?:\DateTime|null, token?:string|null, withName?:string|null, note?:string} $o
     * @return IShare the share double
     */
    public function add(array $o = []): IShare {
        $id = $this->nextId++;
        $share = $this->mock(IShare::class);
        $share->method('getId')->willReturn((string)$id);
        $share->method('getFullId')->willReturn('ocinternal:' . $id);
        $share->method('getShareType')->willReturn($o['type'] ?? IShare::TYPE_USER);
        $share->method('getSharedWith')->willReturn(array_key_exists('with', $o) ? $o['with'] : 'bruno');
        $share->method('getSharedWithDisplayName')->willReturn($o['withName'] ?? null);
        $share->method('getNodeId')->willReturn($o['node'] ?? 0);
        $share->method('getNodeType')->willReturn($o['nodeType'] ?? 'file');
        $share->method('getSharedBy')->willReturn($o['by'] ?? 'alice');
        $share->method('getShareOwner')->willReturn($o['owner'] ?? 'alice');
        $share->method('getPermissions')->willReturn($o['permissions'] ?? Constants::PERMISSION_READ);
        $share->method('getPassword')->willReturn($o['password'] ?? null);
        $share->method('getExpirationDate')->willReturn($o['expires'] ?? null);
        $share->method('getToken')->willReturn($o['token'] ?? null);
        $share->method('getNote')->willReturn($o['note'] ?? '');
        $this->shares[] = $share;
        return $share;
    }

    /** @return IShareManager the manager double reading this store */
    public function manager(): IShareManager {
        $manager = $this->mock(IShareManager::class);
        $manager->method('getSharesBy')->willReturnCallback(
            function (string $uid, int $type, ?\OCP\Files\Node $path = null, bool $reshares = false, int $limit = 50, int $offset = 0): array {
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
        return $manager;
    }

    private function mock(string $interface): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $interface);
    }
}
