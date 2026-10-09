<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use OCP\Lock\ManuallyLockedException;
use Psr\Log\LoggerInterface;

/**
 * Whether a file lock of files_lock (an app up to Nextcloud 34, bundled in 35) stops a write, and the words that
 * explain it: which app or person holds it, since when, until when and what to do.
 *
 * The rules are the storage wrapper's, read from files_lock 35.0.0, made stricter where the MCP cannot act as the
 * lock holder: a manual lock of the user themselves lets the write through, as in the Files app; a manual lock of
 * someone else, an app lock (Text, Office) and a WebDAV token lock, when the provider reports them, refuse it, the
 * last two even for their own user. This check only refuses the locks the provider observes.
 * What enforces what: a manual lock of someone else and an app lock are also refused by the storage wrapper of
 * files_lock itself, which reads the lock afresh at the write; {@see run()} catches that refusal and explains it.
 * Not before a delete, or a rename to or from Markdown, of a document of Text: Text's listener for that event
 * (BeforeNodeDeletedListener, BeforeNodeRenamedListener) runs before the storage and resets the document, releasing
 * its app lock, so an app lock of Text taken after this check's lookup does not stop that delete or rename. A
 * WebDAV token lock is never enforced by that wrapper, only by Nextcloud's DAV layer, so outside DAV this check is
 * the only thing that refuses it, and it is not atomic with the write: there is no atomic lock acquisition in
 * files_lock 32 to 35 to make it so. Worse, getLocks() is answered from files_lock's per-request cache, negative
 * results included, so {@see run()} repeating the check right before the write does not see a token lock taken after
 * the first lookup of that node in the same request. Nothing here guarantees a write is never made under a token lock.
 *
 * In doubt, the write is refused: a lock provider that cannot answer, a folder too large to check all of it, a type
 * of lock this code does not know. A folder is only free when everything the user can reach inside it is.
 *
 * Nothing here takes, releases or borrows a lock, and no lock token or uid reaches the answer or the log. A refusal
 * is an expected condition: it is logged at debug level, without the path.
 */
class LockAwareWrite {
    /** Most nodes of a folder whose locks are read before it moves. */
    public const TREE_LIMIT = 5000;
    /** Longest holder name shown, in characters. */
    private const NAME_MAX = 80;
    /** Longest path shown, in characters: long enough to tell two deep paths apart. */
    private const PATH_MAX = 400;
    private const TYPES = [ILock::TYPE_USER => 'user', ILock::TYPE_APP => 'app', ILock::TYPE_TOKEN => 'token'];

    /** Most nodes of a folder checked; {@see TREE_LIMIT}, lowered only by tests. */
    public int $treeLimit = self::TREE_LIMIT;

    public function __construct(
        private ILockManager $lockManager,
        private IUserManager $userManager,
        private IAppManager $appManager,
        private ITimeFactory $time,
        private UserTimezone $timezone,
        private LoggerInterface $logger,
        private ?VisibilityGuard $visibility = null,
    ) {}

    /** @return bool whether a lock provider (files_lock) is there; without one, no lock can exist */
    public function providerAvailable(): bool {
        try {
            return $this->lockManager->isLockProviderAvailable();
        } catch (\Throwable $e) {
            $this->logger->debug('MCP could not ask for the lock provider', ['app' => 'mcp', 'exception_class' => $e::class]);
            return true;
        }
    }

    /**
     * Refuses the write before anything changes when the node, or anything the user can reach inside a folder, is
     * locked against it, or when that cannot be told.
     *
     * @param Node $node the file or folder about to change, already resolved through the user's view
     * @param string $userId authenticated user
     * @param string|null $path user-relative path the message names, taken from the node when null
     * @param bool $asUser whether the write runs as that user; false on a route without user session, where even
     *                     the user's own manual lock refuses the write, as files_lock does there
     * @param bool $untouched whether nothing of the call was written yet; false when an earlier step of the same call
     *                        already changed the node, so the refusal never claims that nothing changed
     * @throws LockWriteFailure when a lock stops the write, or the locks could not be read
     */
    public function assertWritable(Node $node, string $userId, ?string $path = null, bool $asUser = true, bool $untouched = true): void {
        $found = $this->evaluate($node, $userId, $asUser);
        if ($found['state'] === 'free' || $found['state'] === 'own') {
            return;
        }
        $this->logRefusal($found['described']['type'] ?? 'unknown', 'check');
        $message = $this->explain($found, $userId, $path ?? self::userPath($node, $userId), false, $untouched);
        throw new LockWriteFailure($found['state'] === 'blocked' && $untouched ? $message . ' ' . LockMessages::nothingChanged() : $message,
            ($found['hidden'] ?? false) ? self::hiddenLock() : $found['described'] ?? null);
    }

    /**
     * What a plan says about the lock: nothing when the write is free, a warning otherwise. The plan is never refused
     * for it, so the person sees the whole change and why the confirmed call would fail.
     *
     * @param Node $node the file or folder the write would change
     * @param string $userId authenticated user
     * @param string|null $path user-relative path the message names, taken from the node when null
     * @param bool $asUser see {@see assertWritable()}; false for the plan of a checkout, whose upload has no session
     * @return array{lock?: array<string, mixed>, warnings?: list<array{type:string, message:string}>} keys to add to the plan
     */
    public function planNotice(Node $node, string $userId, ?string $path = null, bool $asUser = true): array {
        $found = $this->evaluate($node, $userId, $asUser);
        $path ??= self::userPath($node, $userId);
        $message = match ($found['state']) {
            'free' => null,
            'own' => LockMessages::planOwn(self::clean($path)),
            default => $this->explain($found, $userId, $path, true),
        };
        if ($message === null) {
            return [];
        }
        $lock = match (true) {
            $found['hidden'] ?? false => self::hiddenLock(),
            isset($found['described']) => $found['described'],
            default => ['blocking' => true, 'verified' => false],
        };
        return ['lock' => $lock, 'warnings' => [['type' => 'file_locked', 'message' => $message]]];
    }

    /**
     * Runs a write: checks the locks again right before it, then turns a lock refusal of the storage into the
     * explained one. Every write that changes or moves a node goes through here, so how the write is guarded can
     * change in one place. The repeated check may be answered from files_lock's per-request cache (see the class).
     *
     * @template T
     * @param callable():T $write the write itself
     * @param Node $node the node it changes
     * @param string $userId authenticated user
     * @param string|null $path user-relative path the message names, taken from the node when null
     * @param bool $asUser see {@see assertWritable()}
     * @param bool $untouched see {@see assertWritable()}
     * @return T what the write returned
     * @throws ToolFailure {@see LockWriteFailure} for a lock, the short retry message for a transactional lock
     */
    public function run(callable $write, Node $node, string $userId, ?string $path = null, bool $asUser = true, bool $untouched = true): mixed {
        $this->assertWritable($node, $userId, $path, $asUser, $untouched);
        return $this->capture($write, $node, $userId, $path);
    }

    /**
     * Runs an operation that only reads the node, such as the source of a copy, which a lock does not forbid, and
     * explains the lock refusal the storage may still raise (files_lock refuses a locked source copied across storages).
     *
     * @template T
     * @param callable():T $operation the copy or the write of a new file
     * @param Node $node the node it reads or creates
     * @param string $userId authenticated user
     * @param string|null $path user-relative path the message names, taken from the node when null
     * @return T what the operation returned
     * @throws ToolFailure {@see LockWriteFailure} for a lock, the short retry message for a transactional lock
     */
    public function capture(callable $operation, Node $node, string $userId, ?string $path = null): mixed {
        try {
            return $operation();
        } catch (LockedException $e) {
            throw $this->failure($e, $node, $userId, $path);
        }
    }

    /**
     * The failure a lock exception stands for. A ManuallyLockedException comes from files_lock: the lock of the node
     * is read again to name its holder, but only when its token is the one the exception carries. Without that match
     * the holder stays unidentified: the owner of the exception is a uid or an app id, and nothing says which. Any
     * other LockedException is the core's short transactional lock, which only asks to try again.
     *
     * @param LockedException $e what the storage threw
     * @param Node $node the node the write was changing
     * @param string $userId authenticated user
     * @param string|null $path user-relative path the message names, taken from the node when null
     * @return ToolFailure the failure to throw
     */
    public function failure(LockedException $e, Node $node, string $userId, ?string $path = null): ToolFailure {
        if (!$e instanceof ManuallyLockedException) {
            return new ToolFailure(CommonMessages::locked());
        }
        $path = self::clean($path ?? self::userPath($node, $userId));
        $token = (string)$e->getExistingLock();
        try {
            $lock = $token === '' || !$this->lockManager->isLockProviderAvailable() ? null : $this->lockOf($node);
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock !== null && $lock->getToken() === $token) {
            $described = ['blocking' => true] + $this->describe($lock, $userId, true);
            $sentences = $this->sentences($lock, $described, $userId, $path);
        } else {
            $described = ['type' => 'unknown', 'owner_display_name' => null, 'own' => false, 'blocking' => true,
                'created_at' => null, 'expires_at' => null, 'expiry_status' => 'unknown'];
            $sentences = [LockMessages::lockedUnidentified($path)];
            if ($e->getTimeout() > 0) {
                $sentences[] = LockMessages::endsIn((int)ceil($e->getTimeout() / 60));
            }
        }
        $this->logRefusal($described['type'], 'write');
        return new LockWriteFailure(implode(' ', [...$sentences, $this->advice($described)]), $described);
    }

    /**
     * Reads the locks of the node and, for a folder, of everything the user can reach inside it, up to the limit.
     *
     * @return array{state:string, described?:array<string, mixed>, lock?:ILock, node?:Node, hidden?:bool, root?:Node}
     *         state free, own (only the user's own manual lock, which lets the write through), blocked, unverified or too_many
     */
    private function evaluate(Node $node, string $userId, bool $asUser): array {
        if (!$this->providerAvailable()) {
            return ['state' => 'free'];
        }
        $own = null;
        $queue = new \SplQueue();
        $queue->enqueue($node);
        // Nodes that may still be queued: a listing larger than what is left refuses the folder at once, before any of
        // its entries is queued or has its lock read. The listing itself is read whole before that comparison:
        // Folder::getDirectoryListing() takes no limit in Nextcloud 32 to 35, and Folder::search(), which does, queries
        // the file cache rather than the listing (unscanned entries, mounts), so it cannot stand in as a faithful count.
        // A single very wide folder therefore costs its whole listing, though never a lock lookup per entry.
        $budget = $this->treeLimit - 1;
        try {
            while (!$queue->isEmpty()) {
                $current = $queue->dequeue();
                $lock = $this->lockOf($current);
                if ($lock !== null) {
                    $described = $this->describe($lock, $userId, $asUser);
                    if ($described['blocking']) {
                        $hidden = $current !== $node && $this->visibility !== null && !$this->visibility->isVisible($current);
                        return ['state' => 'blocked', 'described' => $described, 'lock' => $lock, 'node' => $current, 'hidden' => $hidden, 'root' => $node];
                    }
                    // The user's own manual lock lets the write through. Only the node's own one is reported: one inside a
                    // folder is not the folder's, and on a file the user cannot see it would tell what is hidden.
                    if ($current === $node) {
                        $own ??= $described;
                    }
                }
                if ($current instanceof Folder) {
                    $children = $current->getDirectoryListing();
                    if (count($children) > $budget) {
                        return ['state' => 'too_many', 'root' => $node];
                    }
                    $budget -= count($children);
                    foreach ($children as $child) {
                        $queue->enqueue($child);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('MCP could not read the file lock', ['app' => 'mcp', 'exception_class' => $e::class]);
            return ['state' => 'unverified', 'root' => $node];
        }
        return $own === null ? ['state' => 'free'] : ['state' => 'own', 'described' => $own];
    }

    /**
     * @param array<string, mixed> $found what evaluate() returned for a refused write
     * @param string $path user-relative path of the node the write targets
     * @param bool $plan whether the words are for a plan, which adds that the confirmed call would be refused
     * @param bool $untouched see {@see assertWritable()}
     * @return string the explanation
     */
    private function explain(array $found, string $userId, string $path, bool $plan, bool $untouched = true): string {
        $path = self::clean($path);
        if ($found['state'] === 'unverified') {
            return $untouched ? LockMessages::unverified($path) : LockMessages::unverifiedMidway($path);
        }
        if ($found['state'] === 'too_many') {
            return LockMessages::tooManyToCheck($path, $this->treeLimit);
        }
        if ($found['hidden']) {
            $sentences = [LockMessages::lockedInside($path)];
            $advice = LockMessages::adviceUnknown();
        } else {
            $named = $found['node'] === $found['root'] ? $path : self::clean(self::userPath($found['node'], $userId));
            $sentences = $this->sentences($found['lock'], $found['described'], $userId, $named);
            $advice = $this->advice($found['described']);
        }
        if ($plan) {
            $sentences[] = LockMessages::planRefused();
        }
        return implode(' ', [...$sentences, $advice]);
    }

    /**
     * @param Node $node node to look at
     * @return ILock|null the lock that matters most, a blocking-type one before a manual one, null when none
     * @throws \Throwable when the provider cannot answer
     */
    private function lockOf(Node $node): ?ILock {
        $found = null;
        foreach ($this->lockManager->getLocks((int)$node->getId()) as $lock) {
            if (!$lock instanceof ILock) {
                continue;
            }
            if ($lock->getType() !== ILock::TYPE_USER) {
                return $lock;
            }
            $found ??= $lock;
        }
        return $found;
    }

    /**
     * @return array{type:string, owner_display_name:string|null, own:bool, blocking:bool, created_at:string|null, expires_at:string|null, expiry_status:string}
     */
    private function describe(ILock $lock, string $userId, bool $asUser): array {
        $type = self::TYPES[$lock->getType()] ?? 'unknown';
        $own = ($type === 'user' || $type === 'token') && $lock->getOwner() === $userId;
        $created = $lock->getCreatedAt() > 0 ? $lock->getCreatedAt() : null;
        $timeout = $lock->getTimeout();
        $expires = $created !== null && $timeout > 0 ? $created + $timeout : null;
        return [
            'type' => $type,
            'owner_display_name' => $type === 'unknown' ? null : $this->ownerName($type, $lock->getOwner()),
            'own' => $own,
            'blocking' => !($type === 'user' && $own && $asUser),
            'created_at' => $created === null ? null : gmdate('Y-m-d\TH:i:s\Z', $created),
            'expires_at' => $expires === null ? null : gmdate('Y-m-d\TH:i:s\Z', $expires),
            'expiry_status' => $expires !== null ? 'known' : ($timeout <= 0 ? 'none' : 'unknown'),
        ];
    }

    /**
     * @param string $type user, app or token: the type the lock itself declares
     * @param string $owner uid, or app id for an app lock
     * @return string|null the name a person recognizes, null when there is none to show: never a raw uid or app id
     */
    private function ownerName(string $type, string $owner): ?string {
        try {
            if ($type === 'app') {
                $info = $this->appManager->getAppInfo($owner);
                $name = is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : '';
            } else {
                $name = (string)$this->userManager->get($owner)?->getDisplayName();
            }
        } catch (\Throwable) {
            $name = '';
        }
        $name = self::clean($name, self::NAME_MAX);
        return $name === '' || $name === $owner ? null : $name;
    }

    /**
     * Who holds the file, since when and until when.
     *
     * @param array<string, mixed> $described the lock as describe() returned it
     * @return list<string>
     */
    private function sentences(ILock $lock, array $described, string $userId, string $path): array {
        $sentences = [$this->headline($described, $path)];
        if ($described['created_at'] !== null) {
            $sentences[] = LockMessages::since($this->local($lock->getCreatedAt(), $userId));
        }
        if ($described['expiry_status'] === 'known') {
            $sentences[] = LockMessages::endsAt($this->local($lock->getCreatedAt() + $lock->getTimeout(), $userId));
        } elseif ($described['expiry_status'] === 'none') {
            $sentences[] = LockMessages::noEnd();
        }
        return $sentences;
    }

    /** @param array<string, mixed> $described @return string the first sentence: the file and who holds it */
    private function headline(array $described, string $path): string {
        return match (true) {
            $described['type'] === 'unknown' => LockMessages::lockedUnidentified($path),
            $described['type'] === 'app' => $described['owner_display_name'] === null
                ? LockMessages::lockedByUnnamedApp($path)
                : LockMessages::lockedByApp($path, $described['owner_display_name']),
            (bool)$described['own'] => LockMessages::lockedByYou($path),
            $described['owner_display_name'] === null => LockMessages::lockedBySomeoneElse($path),
            default => LockMessages::lockedBy($path, $described['owner_display_name']),
        };
    }

    /** @param array<string, mixed> $described @return string what the person can do */
    private function advice(array $described): string {
        return match (true) {
            $described['type'] === 'app' => LockMessages::adviceApp(),
            $described['type'] === 'token' => LockMessages::adviceToken(),
            $described['type'] === 'unknown' => LockMessages::adviceUnknown(),
            (bool)$described['own'] => LockMessages::adviceOwn(),
            default => LockMessages::advicePerson(),
        };
    }

    /**
     * The lock of a file the user cannot see, inside a folder that would move: only that something blocks the move,
     * never its holder, type or dates, which would say more about the hidden file than the user may know.
     *
     * @return array{blocking:true}
     */
    private static function hiddenLock(): array {
        return ['blocking' => true];
    }

    /**
     * @param Node $node a node of the user's view
     * @param string $userId authenticated user
     * @return string its path below the user's folder, the way the tools name it; the name alone outside that folder
     */
    private static function userPath(Node $node, string $userId): string {
        $prefix = '/' . $userId . '/files';
        $path = (string)$node->getPath();
        return str_starts_with($path, $prefix . '/') ? substr($path, strlen($prefix)) : ($path === $prefix ? '/' : (string)$node->getName());
    }

    /** @return string the instant in the user's timezone, e.g. 2025-10-08 08:05 (America/Sao_Paulo) */
    private function local(int $timestamp, string $userId): string {
        $zone = $this->timezone->forUser($userId);
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d H:i') . ' (' . $zone->getName() . ')';
    }

    /**
     * @param string $value text from a user, an app or a path
     * @param int $max longest result, in characters
     * @return string one line without control characters, cut at $max
     */
    private static function clean(string $value, int $max = self::PATH_MAX): string {
        $value = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
    }

    private function logRefusal(string $type, string $phase): void {
        $this->logger->debug('MCP write refused: the file is locked', ['app' => 'mcp', 'lock_type' => $type, 'phase' => $phase]);
    }
}
