<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
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
 * someone else, an app lock (Text, Office) and a WebDAV token lock refuse it, the last two even for their own user.
 * The check runs before anything is written, so a refusal costs no backup; {@see run()} catches the storage refusal
 * that still arrives when a lock appears in between.
 *
 * Nothing here takes, releases or borrows a lock, and no lock token reaches the answer or the log. A refusal is an
 * expected condition: it is logged at debug level, without the path.
 */
class LockAwareWrite {
    /** Longest holder name shown, in characters. */
    private const NAME_MAX = 80;
    /** Longest path shown, in characters: long enough to tell two deep paths apart. */
    private const PATH_MAX = 400;
    private const TYPES = [ILock::TYPE_USER => 'user', ILock::TYPE_APP => 'app', ILock::TYPE_TOKEN => 'token'];

    public function __construct(
        private ILockManager $lockManager,
        private IUserManager $userManager,
        private IAppManager $appManager,
        private ITimeFactory $time,
        private UserTimezone $timezone,
        private LoggerInterface $logger,
    ) {}

    /**
     * The lock on a file as the client may see it, without its token or the uid of its holder.
     *
     * A provider that cannot answer is not a free file, but it is not a refusal either: the write goes on and the
     * storage wrapper of files_lock still refuses it if the file turns out to be locked, which {@see run()} explains.
     *
     * @param Node $node the file about to change, already resolved through the user's view
     * @param string $userId authenticated user
     * @param bool $asUser whether the write runs as that user; false on a route without user session, where even
     *                     the user's own manual lock refuses the write
     * @return array{type:string, owner_display_name:string|null, own:bool, blocking:bool, created_at:string|null, expires_at:string|null, expiry_status:string}|null
     *         null when there is no lock, or no lock provider
     */
    public function inspect(Node $node, string $userId, bool $asUser = true): ?array {
        $lock = $this->lockOf($node);
        return $lock === null ? null : $this->describe($lock, $userId, $asUser);
    }

    /**
     * Refuses the write before anything changes when the file is locked against it.
     *
     * @param Node $node the file about to change
     * @param string $userId authenticated user
     * @param string $path user-relative path the message names
     * @param bool $asUser see {@see inspect()}
     * @throws LockWriteFailure when a lock stops the write
     */
    public function assertWritable(Node $node, string $userId, string $path, bool $asUser = true): void {
        $lock = $this->lockOf($node);
        if ($lock === null) {
            return;
        }
        $described = $this->describe($lock, $userId, $asUser);
        if (!$described['blocking']) {
            return;
        }
        $this->logRefusal($described['type'], 'check');
        throw new LockWriteFailure(implode(' ', [
            ...$this->sentences($lock, $described, $userId, $path),
            $this->advice($described),
            LockMessages::nothingChanged(),
        ]), $described);
    }

    /**
     * What a plan says about the lock: nothing for a free file, a warning otherwise. The plan is never refused
     * for it, so the person sees the whole change and why the confirmed call would fail.
     *
     * @param Node $node the file the write would change
     * @param string $userId authenticated user
     * @param string $path user-relative path the message names
     * @return array{lock?: array<string, mixed>, warnings?: list<array{type:string, message:string}>} keys to add to the plan
     */
    public function planNotice(Node $node, string $userId, string $path): array {
        $lock = $this->lockOf($node);
        if ($lock === null) {
            return [];
        }
        $described = $this->describe($lock, $userId, true);
        $message = $described['blocking']
            ? implode(' ', [...$this->sentences($lock, $described, $userId, $path), LockMessages::planRefused(), $this->advice($described)])
            : LockMessages::planOwn(self::clean($path));
        return ['lock' => $described, 'warnings' => [['type' => 'file_locked', 'message' => $message]]];
    }

    /**
     * Runs a write and turns a lock refusal of the storage into the explained one.
     *
     * @template T
     * @param callable():T $write the write itself
     * @param Node $node the file it changes
     * @param string $userId authenticated user
     * @param string $path user-relative path the message names
     * @return T what the write returned
     * @throws ToolFailure {@see LockWriteFailure} for a files_lock refusal, the short retry message for a transactional lock
     */
    public function run(callable $write, Node $node, string $userId, string $path): mixed {
        try {
            return $write();
        } catch (LockedException $e) {
            throw $this->failure($e, $node, $userId, $path);
        }
    }

    /**
     * The failure a lock exception stands for. A ManuallyLockedException comes from files_lock: the lock of the
     * file is read again to name its holder, but only when it is still the lock that refused the write. Any other
     * LockedException is the core's short transactional lock, which only asks to try again.
     *
     * @param LockedException $e what the storage threw
     * @param Node $node the file the write was changing
     * @param string $userId authenticated user
     * @param string $path user-relative path the message names
     * @return ToolFailure the failure to throw
     */
    public function failure(LockedException $e, Node $node, string $userId, string $path): ToolFailure {
        if (!$e instanceof ManuallyLockedException) {
            return new ToolFailure(CommonMessages::locked());
        }
        $lock = $this->lockOf($node);
        $token = $e->getExistingLock();
        if ($lock !== null && ($token === null || $token === '' || $lock->getToken() === $token)) {
            $described = ['blocking' => true] + $this->describe($lock, $userId, true);
            $sentences = $this->sentences($lock, $described, $userId, $path);
        } else {
            [$described, $sentences] = $this->fromException($e, $userId, $path);
        }
        $this->logRefusal($described['type'], 'write');
        return new LockWriteFailure(implode(' ', [...$sentences, $this->advice($described)]), $described);
    }

    /**
     * @param Node $node file to look at
     * @return ILock|null the lock that matters most, a blocking-type one before a manual one, null when none
     */
    private function lockOf(Node $node): ?ILock {
        try {
            if (!$this->lockManager->isLockProviderAvailable()) {
                return null;
            }
            $locks = $this->lockManager->getLocks((int)$node->getId());
        } catch (\Throwable $e) {
            $this->logger->debug('MCP could not read the file lock', ['app' => 'mcp', 'exception_class' => $e::class]);
            return null;
        }
        $found = null;
        foreach ($locks as $lock) {
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
        $own = $type !== 'app' && $lock->getOwner() === $userId;
        $created = $lock->getCreatedAt() > 0 ? $lock->getCreatedAt() : null;
        $timeout = $lock->getTimeout();
        $expires = $created !== null && $timeout > 0 ? $created + $timeout : null;
        return [
            'type' => $type,
            'owner_display_name' => $this->ownerName($type, $lock->getOwner()),
            'own' => $own,
            'blocking' => !($type === 'user' && $own && $asUser),
            'created_at' => $created === null ? null : gmdate('Y-m-d\TH:i:s\Z', $created),
            'expires_at' => $expires === null ? null : gmdate('Y-m-d\TH:i:s\Z', $expires),
            'expiry_status' => $expires !== null ? 'known' : ($timeout <= 0 ? 'none' : 'unknown'),
        ];
    }

    /**
     * @param string $type user, app, token or unknown
     * @param string $owner uid, or app id for an app lock
     * @return string|null the name a person recognizes, null when there is none to show: never a raw uid or app id
     */
    private function ownerName(string $type, string $owner): ?string {
        if ($type === 'app') {
            $info = $this->appManager->getAppInfo($owner);
            $name = is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : '';
        } else {
            $name = (string)$this->userManager->get($owner)?->getDisplayName();
        }
        $name = self::clean($name, self::NAME_MAX);
        return $name === '' ? null : $name;
    }

    /**
     * Who holds the file, since when and until when.
     *
     * @param array<string, mixed> $described the lock as describe() returned it
     * @return list<string>
     */
    private function sentences(ILock $lock, array $described, string $userId, string $path): array {
        $sentences = [$this->headline($described, self::clean($path))];
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
        if ($described['type'] === 'app') {
            return $described['owner_display_name'] === null
                ? LockMessages::lockedByApp($path)
                : LockMessages::openInApp($path, $described['owner_display_name']);
        }
        if ($described['own']) {
            return LockMessages::lockedByYou($path);
        }
        return $described['owner_display_name'] === null
            ? LockMessages::lockedBySomeoneElse($path)
            : LockMessages::lockedBy($path, $described['owner_display_name']);
    }

    /** @param array<string, mixed> $described @return string what the person can do */
    private function advice(array $described): string {
        return match (true) {
            $described['type'] === 'app' => LockMessages::adviceApp(),
            $described['type'] === 'token' => LockMessages::adviceToken(),
            (bool)$described['own'] => LockMessages::adviceOwn(),
            default => LockMessages::advicePerson(),
        };
    }

    /**
     * The explanation when the lock that refused the write is gone or was replaced: only what the exception says.
     * Its owner is a uid or an app id, with no type; its timeout is the time left, not a duration.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function fromException(ManuallyLockedException $e, string $userId, string $path): array {
        $owner = (string)$e->getOwner();
        $person = $owner === '' ? null : $this->ownerName('user', $owner);
        $app = $owner === '' || $person !== null ? null : $this->ownerName('app', $owner);
        $type = $app !== null ? 'app' : ($person !== null ? 'user' : 'unknown');
        $described = [
            'type' => $type,
            'owner_display_name' => $app ?? $person,
            'own' => $person !== null && $owner === $userId,
            'blocking' => true,
            'created_at' => null,
            'expires_at' => null,
            'expiry_status' => 'unknown',
        ];
        $sentences = [$this->headline($described, self::clean($path))];
        if ($e->getTimeout() > 0) {
            $sentences[] = LockMessages::endsIn((int)ceil($e->getTimeout() / 60));
        }
        return [$described, $sentences];
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
