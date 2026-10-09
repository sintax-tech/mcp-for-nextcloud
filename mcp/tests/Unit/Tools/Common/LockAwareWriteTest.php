<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Common;

use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\LockMessages;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Lock\ILock;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\Lock\LockedException;
use OCP\Lock\ManuallyLockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The one place that tells whether a file lock of files_lock (bundled with Nextcloud 35, an app before) stops a write,
 * and says it in words the person can act on: which app or person holds it, since when, until when and what to do.
 * It never takes, releases or borrows a lock: FakeLockManager throws if it is asked to.
 */
final class LockAwareWriteTest extends TestCase {
    /** 2025-10-08 11:05 UTC, 08:05 in São Paulo. */
    private const CREATED = 1759921500;
    private const PATH = '/Notes/CHAMADOS/CHAMADOS OUTUBRO.md';

    private FakeTree $tree;
    private FakeLockManager $manager;
    private Node $note;
    private int $id;
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->id = $this->tree->addFile('/alice/files' . self::PATH, '# Outubro', 'text/markdown');
        $this->note = $this->tree->node('/alice/files' . self::PATH);
        $this->manager = new FakeLockManager();
    }

    private function locks(?FakeLockManager $manager = null, ?\OCA\Mcp\Service\VisibilityGuard $visibility = null): LockAwareWrite {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppInfo')->willReturnCallback(static fn (string $app): ?array => match ($app) {
            'text' => ['id' => 'text', 'name' => 'Text'],
            'richdocuments' => ['id' => 'richdocuments', 'name' => 'Nextcloud Office'],
            default => null,
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::CREATED + 600);
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('America/Sao_Paulo');
        $logger = new class($this->logged) extends AbstractLogger {
            public function __construct(private array &$logged) {}
            public function log($level, \Stringable|string $message, array $context = []): void {
                $this->logged[] = [(string)$level, (string)$message, $context];
            }
        };
        return new LockAwareWrite(
            $manager ?? $this->manager,
            FakeUsers::manager($this, ['alice' => 'Alice', 'pedro' => 'Pedro Almeida']),
            $apps,
            $time,
            new UserTimezone($config),
            $logger,
            $visibility,
        );
    }

    private function utc(): UserTimezone {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('UTC');
        return new UserTimezone($config);
    }

    private function refusal(callable $action): LockWriteFailure {
        try {
            $action();
        } catch (LockWriteFailure $e) {
            return $e;
        }
        $this->fail('the write was not refused');
    }

    public function testWithoutLockProviderNothingIsAskedAndTheWriteGoesOn(): void {
        $this->manager->available = false;
        $locks = $this->locks();
        $locks->assertWritable($this->note, 'alice', self::PATH);
        $this->assertFalse($locks->providerAvailable());
        $this->assertSame([], $locks->planNotice($this->note, 'alice', self::PATH));
        $this->assertSame([], $this->manager->asked);
    }

    public function testAFileWithoutLockIsWritable(): void {
        $locks = $this->locks();
        $locks->assertWritable($this->note, 'alice', self::PATH);
        $this->assertSame([], $locks->planNotice($this->note, 'alice', self::PATH));
        $this->assertSame([$this->id, $this->id], $this->manager->asked);
    }

    public function testTextHoldingTheNoteRefusesWithTheAppAndTheAdvice(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));

        $this->assertInstanceOf(ToolFailure::class, $e);
        $this->assertSame(
            'The file “' . self::PATH . '” is locked by Text, usually because it is open there. '
            . 'Locked since 2025-10-08 08:05 (America/Sao_Paulo). The lock has no end time. '
            . 'Close the file in that app (for a note, also in Notes) and try again. Nextcloud does not say who has it open. '
            . 'Nothing was changed.',
            $e->getMessage(),
        );
        $this->assertSame([
            'type' => 'app',
            'owner_display_name' => 'Text',
            'own' => false,
            'blocking' => true,
            'created_at' => '2025-10-08T11:05:00Z',
            'expires_at' => null,
            'expiry_status' => 'none',
        ], $e->lock);
    }

    public function testAnAppLockBlocksEvenItsOwnUserAndNamesOfficeByItsName(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'richdocuments', self::CREATED, 1800));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked by Nextcloud Office, usually because it is open there.', $e->getMessage());
        $this->assertStringContainsString('The lock ends at 2025-10-08 08:35 (America/Sao_Paulo), unless it is renewed.', $e->getMessage());
        $this->assertSame('2025-10-08T11:35:00Z', $e->lock['expires_at']);
        $this->assertSame('known', $e->lock['expiry_status']);
    }

    public function testAnUnknownAppIsNotPresentedAsAPerson(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'some_app', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked by an app, usually because it is open in an editor.', $e->getMessage());
        $this->assertStringNotContainsString('some_app', $e->getMessage());
        $this->assertNull($e->lock['owner_display_name']);
    }

    public function testAManualLockOfAnotherPersonNamesThemByDisplayName(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'pedro', self::CREATED, 3600));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertStringStartsWith('The file “' . self::PATH . '” is locked by Pedro Almeida. ', $e->getMessage());
        $this->assertStringContainsString('Ask that person to unlock it, or wait until the lock ends, and then try again.', $e->getMessage());
        $this->assertStringNotContainsString('pedro ', $e->getMessage());
        $this->assertSame('user', $e->lock['type']);
    }

    public function testADeletedOwnerIsUnidentifiedAndItsUidNeverShown(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'ghost', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked by someone else.', $e->getMessage());
        $this->assertStringNotContainsString('ghost', $e->getMessage());
    }

    public function testTheOwnManualLockLetsTheWriteThroughAndThePlanSaysSo(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'alice', self::CREATED));
        $locks = $this->locks();
        $locks->assertWritable($this->note, 'alice', self::PATH);
        $notice = $locks->planNotice($this->note, 'alice', self::PATH);
        $this->assertFalse($notice['lock']['blocking']);
        $this->assertTrue($notice['lock']['own']);
        $this->assertSame(
            [['type' => 'file_locked', 'message' => 'The file “' . self::PATH . '” is locked by you. This change is allowed and keeps your lock.']],
            $notice['warnings'],
        );
    }

    public function testTheOwnManualLockBlocksARequestThatDoesNotCarryTheUser(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'alice', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH, false));
        $this->assertStringContainsString('is locked by you.', $e->getMessage());
        $this->assertStringContainsString('You locked this file yourself. Unlock it in Nextcloud Files, or change it with files_edit, which keeps your lock.', $e->getMessage());
    }

    public function testAWebDavTokenLockBlocksEvenItsOwnUser(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_TOKEN, 'alice', self::CREATED, 1800));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked by you.', $e->getMessage());
        $this->assertStringContainsString('It was locked through a WebDAV client, such as the desktop client or an office app.', $e->getMessage());
        $this->assertSame('token', $e->lock['type']);
        $this->assertTrue($e->lock['own']);
        $this->assertTrue($e->lock['blocking']);
    }

    public function testALockOfAnUnknownTypeIsBlocking(): void {
        $this->manager->put(new FakeLock($this->id, 7, 'pedro', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertSame('unknown', $e->lock['type']);
    }

    public function testABlockedPlanWarnsWithoutRefusing(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $notice = $this->locks()->planNotice($this->note, 'alice', self::PATH);
        $this->assertTrue($notice['lock']['blocking']);
        $this->assertSame('file_locked', $notice['warnings'][0]['type']);
        $this->assertSame(
            'The file “' . self::PATH . '” is locked by Text, usually because it is open there. '
            . 'Locked since 2025-10-08 08:05 (America/Sao_Paulo). The lock has no end time. '
            . 'While the lock lasts the change is refused, even after confirmation. '
            . 'Close the file in that app (for a note, also in Notes) and try again. Nextcloud does not say who has it open.',
            $notice['warnings'][0]['message'],
        );
    }

    /** A lock provider that cannot answer is not a free file: the write is refused (fail closed) and the plan says so. */
    public function testAProviderThatFailsRefusesTheWriteAndThePlanWarns(): void {
        $this->manager->failure = new \RuntimeException('remote lock lookup failed for /alice/files/x');
        $locks = $this->locks();
        $e = $this->refusal(fn () => $locks->assertWritable($this->note, 'alice', self::PATH));
        $this->assertSame('Could not check whether the file “' . self::PATH . '” is locked, so nothing was changed. Try again in a moment.', $e->getMessage());
        $this->assertNull($e->lock);
        $notice = $locks->planNotice($this->note, 'alice', self::PATH);
        $this->assertSame(['blocking' => true, 'verified' => false], $notice['lock']);
        $this->assertSame($e->getMessage(), $notice['warnings'][0]['message']);
        $this->assertStringNotContainsString('remote lock lookup', json_encode($this->logged));
        $this->assertSame([], array_filter($this->logged, static fn (array $entry): bool => in_array($entry[0], ['error', 'critical', 'alert', 'emergency'], true)));
    }

    /** A step after an earlier write of the same call never claims that nothing changed. */
    public function testARefusalMidwayNeverSaysNothingChanged(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'pedro', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH, true, false));
        $this->assertStringNotContainsString('Nothing was changed.', $e->getMessage());
        $this->manager->failure = new \RuntimeException('down');
        $e = $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH, true, false));
        $this->assertSame('Could not check whether the file “' . self::PATH . '” is locked, so the rest of the change was not made. Try again in a moment.', $e->getMessage());
    }

    /** files_lock refuses only some locks itself (never a WebDAV token one), so run() checks again right before the write. */
    public function testRunChecksAgainRightBeforeTheWriteAndNeverRunsItUnderALock(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_TOKEN, 'pedro', self::CREATED));
        $ran = false;
        $e = $this->refusal(fn () => $this->locks()->run(function () use (&$ran): void {
            $ran = true;
        }, $this->note, 'alice', self::PATH));
        $this->assertFalse($ran);
        $this->assertStringContainsString('It was locked through a WebDAV client', $e->getMessage());
    }

    public function testCaptureOnlyExplainsTheStorageRefusalWithoutCheckingFirst(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $this->assertSame('read', $this->locks()->capture(static fn (): string => 'read', $this->note, 'alice', self::PATH));
    }

    public function testADisplayNameThatIsTheUidIsNotShown(): void {
        $locks = new LockAwareWrite($this->manager, FakeUsers::manager($this, ['alice' => 'Alice', 'jsilva' => 'jsilva']),
            $this->createMock(IAppManager::class), $this->createMock(ITimeFactory::class), $this->utc(), new \Psr\Log\NullLogger());
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'jsilva', self::CREATED));
        $e = $this->refusal(fn () => $locks->assertWritable($this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked by someone else.', $e->getMessage());
        $this->assertStringNotContainsString('jsilva', $e->getMessage());
        $this->assertNull($e->lock['owner_display_name']);
    }

    /** A folder is only as free as everything in it: a move of the folder would move the locked file too. */
    public function testAFolderWithALockedFileInsideIsRefusedNamingThatFile(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $folder = $this->tree->node('/alice/files/Notes');
        $e = $this->refusal(fn () => $this->locks()->assertWritable($folder, 'alice', '/Notes'));
        $this->assertStringStartsWith('The file “' . self::PATH . '” is locked by Text', $e->getMessage());
        $notice = $this->locks()->planNotice($folder, 'alice', '/Notes');
        $this->assertTrue($notice['lock']['blocking']);
    }

    public function testAFolderTooLargeToCheckIsRefused(): void {
        for ($i = 0; $i < 4; $i++) {
            $this->tree->addFile("/alice/files/Grande/f$i.md", 'x');
        }
        $locks = $this->locks();
        $locks->treeLimit = 3;
        $e = $this->refusal(fn () => $locks->assertWritable($this->tree->node('/alice/files/Grande'), 'alice', '/Grande'));
        $this->assertSame('The folder “/Grande” has more than 3 items, too many to check for locked files, so nothing was changed. Move it in smaller parts.', $e->getMessage());
    }

    public function testALockedFileHiddenFromTheUserIsNotNamed(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'pedro', self::CREATED));
        $guard = $this->createMock(\OCA\Mcp\Service\VisibilityGuard::class);
        $guard->method('isVisible')->willReturnCallback(fn (Node $node): bool => $node->getPath() !== '/alice/files' . self::PATH);
        $locks = $this->locks(visibility: $guard);
        $e = $this->refusal(fn () => $locks->assertWritable($this->tree->node('/alice/files/Notes'), 'alice', '/Notes'));
        $this->assertSame('A file inside the folder “/Notes” is locked. Close it in any editor where it is open, or ask whoever locked it to unlock it, and then try again. Nothing was changed.', $e->getMessage());
        $this->assertStringNotContainsString('OUTUBRO', $e->getMessage());
        $this->assertSame(['blocking' => true], $e->lock, 'no holder, type or date of a file the user cannot see');
        $this->assertSame([
            'lock' => ['blocking' => true],
            'warnings' => [['type' => 'file_locked', 'message' => 'A file inside the folder “/Notes” is locked. '
                . 'While the lock lasts the change is refused, even after confirmation. '
                . 'Close it in any editor where it is open, or ask whoever locked it to unlock it, and then try again.']],
        ], $locks->planNotice($this->tree->node('/alice/files/Notes'), 'alice', '/Notes'));
    }

    public function testThePlanOfALinkWithoutSessionWarnsAboutTheOwnLock(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'alice', self::CREATED));
        $notice = $this->locks()->planNotice($this->note, 'alice', self::PATH, false);
        $this->assertTrue($notice['lock']['blocking']);
        $this->assertStringContainsString('While the lock lasts the change is refused, even after confirmation. You locked this file yourself.', $notice['warnings'][0]['message']);
    }

    public function testTheStorageRefusalIsExplainedWithTheLockItCarries(): void {
        $lock = $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->capture(static function () use ($lock): void {
            throw FakeLockManager::refusal($lock);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringStartsWith('The file “' . self::PATH . '” is locked by Text', $e->getMessage());
        $this->assertStringNotContainsString('Nothing was changed.', $e->getMessage());
        $this->assertStringNotContainsString('files_lock/', $e->getMessage());
    }

    public function testALockThatChangedSinceTheRefusalIsNotBlamedOnItsNewOwner(): void {
        $old = new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED, -1, 'files_lock/old');
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'pedro', self::CREATED, -1, 'files_lock/new'));
        $e = $this->refusal(fn () => $this->locks()->capture(static function () use ($old): void {
            throw FakeLockManager::refusal($old, 300);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringNotContainsString('Pedro', $e->getMessage());
        $this->assertStringNotContainsString('Text', $e->getMessage());
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
        $this->assertSame('unknown', $e->lock['type']);
        $this->assertStringContainsString('The lock ends in about 5 minutes, unless it is renewed.', $e->getMessage());
    }

    public function testARefusalWithoutAnyLockLeftNamesNobody(): void {
        $e = $this->refusal(fn () => $this->locks()->capture(static function (): void {
            throw new ManuallyLockedException('/alice/files/x', null, 'files_lock/gone', null, -1);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
    }

    /**
     * The owner of the exception is a uid or an app id, with nothing telling which: an account named `text` must not
     * turn a lock of the Text app into a person, nor the reverse.
     */
    public function testAnExceptionOwnerIsNeverResolvedByGuessingWhetherItIsAnAccount(): void {
        $locks = new LockAwareWrite($this->manager, FakeUsers::manager($this, ['alice' => 'Alice', 'text' => 'Texto Silva']),
            $this->createMock(IAppManager::class), $this->createMock(ITimeFactory::class), $this->utc(), new \Psr\Log\NullLogger());
        $e = $this->refusal(fn () => $locks->capture(static function (): void {
            throw new ManuallyLockedException('/alice/files/x', null, 'files_lock/x', 'text', 120);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringNotContainsString('Texto Silva', $e->getMessage());
        $this->assertStringContainsString('is locked, and Nextcloud does not say by whom.', $e->getMessage());
        $this->assertStringContainsString('The lock ends in about 2 minutes, unless it is renewed.', $e->getMessage());
        $this->assertNull($e->lock['owner_display_name']);
    }

    public function testATransactionalLockKeepsTheShortRetryMessage(): void {
        try {
            $this->locks()->run(static function (): void {
                throw new LockedException('/alice/files/x');
            }, $this->note, 'alice', self::PATH);
            $this->fail('no refusal');
        } catch (ToolFailure $e) {
            $this->assertNotInstanceOf(LockWriteFailure::class, $e);
            $this->assertSame(CommonMessages::locked(), $e->getMessage());
        }
    }

    public function testARefusalIsLoggedAtDebugWithoutThePathOrTheToken(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $this->refusal(fn () => $this->locks()->assertWritable($this->note, 'alice', self::PATH));
        $this->assertCount(1, $this->logged);
        [$level, $message, $context] = $this->logged[0];
        $this->assertSame('debug', $level);
        $this->assertSame(['app' => 'mcp', 'lock_type' => 'app', 'phase' => 'check'], $context);
        $this->assertStringNotContainsString('CHAMADOS', $message . json_encode($context));
    }

    public function testTheResultOfTheWriteIsReturnedUntouched(): void {
        $this->assertSame('written', $this->locks()->run(static fn (): string => 'written', $this->note, 'alice', self::PATH));
    }

    public function testNodeAccessWithoutContextStillExplainsAManualLock(): void {
        try {
            NodeAccess::run(static function (): void {
                throw new ManuallyLockedException('/alice/files/x', null, 'files_lock/x', 'text', -1);
            });
            $this->fail('no refusal');
        } catch (LockWriteFailure $e) {
            $this->assertSame(LockMessages::lockedWithoutDetails(), $e->getMessage());
        }
        try {
            NodeAccess::run(static function (): void {
                throw new LockedException('/alice/files/x');
            });
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::locked(), $e->getMessage());
        }
    }

    public function testNothingEverTakesReleasesOrBorrowsALock(): void {
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $locks = $this->locks();
        $locks->planNotice($this->note, 'alice', self::PATH);
        try {
            $locks->assertWritable($this->note, 'alice', self::PATH);
        } catch (LockWriteFailure) {
        }
        $this->assertSame([], $this->manager->forbidden);
    }
}
