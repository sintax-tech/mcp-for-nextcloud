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

    private function locks(?FakeLockManager $manager = null): LockAwareWrite {
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
        );
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
        $this->assertNull($locks->inspect($this->note, 'alice'));
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
            'The file “' . self::PATH . '” is open in Text, which keeps it locked for editing. '
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
        $this->assertStringContainsString('is open in Nextcloud Office', $e->getMessage());
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
            'The file “' . self::PATH . '” is open in Text, which keeps it locked for editing. '
            . 'Locked since 2025-10-08 08:05 (America/Sao_Paulo). The lock has no end time. '
            . 'While the lock lasts the change is refused, even after confirmation. '
            . 'Close the file in that app (for a note, also in Notes) and try again. Nextcloud does not say who has it open.',
            $notice['warnings'][0]['message'],
        );
    }

    public function testAProviderThatFailsDoesNotBlockTheWriteWhichStillMeetsTheStorage(): void {
        $this->manager->failure = new \RuntimeException('remote lock lookup failed for /alice/files/x');
        $locks = $this->locks();
        $locks->assertWritable($this->note, 'alice', self::PATH);
        $this->assertSame([], $locks->planNotice($this->note, 'alice', self::PATH));
        $this->assertSame([], array_filter($this->logged, static fn (array $entry): bool => in_array($entry[0], ['error', 'critical', 'alert', 'emergency'], true)));
    }

    public function testTheStorageRefusalIsExplainedWithTheLockItCarries(): void {
        $lock = $this->manager->put(new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED));
        $e = $this->refusal(fn () => $this->locks()->run(static function () use ($lock): void {
            throw FakeLockManager::refusal($lock);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringStartsWith('The file “' . self::PATH . '” is open in Text', $e->getMessage());
        $this->assertStringNotContainsString('Nothing was changed.', $e->getMessage());
        $this->assertStringNotContainsString('files_lock/', $e->getMessage());
    }

    public function testALockThatChangedSinceTheRefusalIsNotBlamedOnItsNewOwner(): void {
        $old = new FakeLock($this->id, ILock::TYPE_APP, 'text', self::CREATED, -1, 'files_lock/old');
        $this->manager->put(new FakeLock($this->id, ILock::TYPE_USER, 'pedro', self::CREATED, -1, 'files_lock/new'));
        $e = $this->refusal(fn () => $this->locks()->run(static function () use ($old): void {
            throw FakeLockManager::refusal($old, 300);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringNotContainsString('Pedro', $e->getMessage());
        $this->assertStringContainsString('is open in Text', $e->getMessage());
        $this->assertStringContainsString('The lock ends in about 5 minutes, unless it is renewed.', $e->getMessage());
    }

    public function testARefusalWithoutAnyLockLeftNamesNobody(): void {
        $e = $this->refusal(fn () => $this->locks()->run(static function (): void {
            throw new ManuallyLockedException('/alice/files/x', null, 'files_lock/gone', null, -1);
        }, $this->note, 'alice', self::PATH));
        $this->assertStringContainsString('is locked by someone else.', $e->getMessage());
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
