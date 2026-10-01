<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar\Scheduling;

use DateTimeImmutable;
use OCA\Mcp\Tools\Calendar\Scheduling\AvailabilityCheck;
use OCP\Calendar\IAvailabilityResult;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AvailabilityCheckTest extends TestCase {
    private const BOB = ['uid' => 'bob', 'email' => 'bob@example.invalid', 'displayName' => 'Roberto'];
    private const CARLA = ['uid' => 'carla', 'email' => 'Carla@Example.Invalid', 'displayName' => 'Carla'];

    /** @return void */
    public function testFreeAttendeeIsNeitherBusyNorUnverifiable(): void {
        $manager = $this->manager([$this->verdict('bob@example.invalid', true)]);

        self::assertSame(['busy' => [], 'unverifiable' => [], 'failed' => false], $this->runCheck($manager, [self::BOB]));
    }

    /** @return void */
    public function testBusyAttendeeIsReportedWithoutEmail(): void {
        $manager = $this->manager([$this->verdict('bob@example.invalid', false)]);

        self::assertSame(
            ['busy' => [['uid' => 'bob', 'displayName' => 'Roberto']], 'unverifiable' => [], 'failed' => false],
            $this->runCheck($manager, [self::BOB]),
        );
    }

    /** @return void */
    public function testMailtoPrefixAndCaseAreNormalised(): void {
        $manager = $this->manager([$this->verdict('MAILTO:carla@EXAMPLE.invalid', false)]);

        $out = $this->runCheck($manager, [self::CARLA]);

        self::assertSame([['uid' => 'carla', 'displayName' => 'Carla']], $out['busy']);
        self::assertSame([], $out['unverifiable']);
    }

    /** @return void */
    public function testAttendeeWithoutResultIsUnverifiableAndOrganizerIsIgnored(): void {
        $manager = $this->manager([
            $this->verdict('alice@example.invalid', false),
            $this->verdict('bob@example.invalid', true),
        ]);

        $out = $this->runCheck($manager, [self::BOB, self::CARLA]);

        self::assertSame([], $out['busy']);
        self::assertSame([['uid' => 'carla', 'displayName' => 'Carla']], $out['unverifiable']);
        self::assertFalse($out['failed']);
    }

    /** @return void */
    public function testApiExceptionFailsAndLogsOnlyClassAndMessage(): void {
        $manager = $this->createMock(IManager::class);
        $manager->method('checkAvailability')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::callback(static fn (string $m): bool => str_contains($m, 'RuntimeException') && str_contains($m, 'boom')
                && !str_contains($m, 'example.invalid')),
        );

        $out = $this->runCheck($manager, [self::BOB], 'alice', $logger);

        self::assertSame(['busy' => [], 'unverifiable' => [], 'failed' => true], $out);
    }

    /** @return void */
    public function testEmailsInTheExceptionMessageAreNotLoggedAndMessageIsCapped(): void {
        $manager = $this->createMock(IManager::class);
        $manager->method('checkAvailability')->willThrowException(
            new \RuntimeException('no calendar for bob@example.invalid <carla@example.invalid> ' . str_repeat('x', 500)),
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::callback(static fn (string $m): bool => !str_contains($m, '@') && str_contains($m, '[email]')
                && strlen($m) < 400),
        );

        $this->runCheck($manager, [self::BOB], 'alice', $logger);
    }

    /** @return void */
    public function testMissingOrganizerFailsWithoutCallingTheApi(): void {
        $manager = $this->createMock(IManager::class);
        $manager->expects(self::never())->method('checkAvailability');

        $out = $this->runCheck($manager, [self::BOB], 'ghost');

        self::assertSame(['busy' => [], 'unverifiable' => [], 'failed' => true], $out);
    }

    /** @return void */
    public function testEmptyAttendeeListDoesNotCallTheApi(): void {
        $manager = $this->createMock(IManager::class);
        $manager->expects(self::never())->method('checkAvailability');

        self::assertSame(['busy' => [], 'unverifiable' => [], 'failed' => false], $this->runCheck($manager, []));
    }

    /** @return void */
    public function testPassesNormalisedEmailsAndRangeToTheApi(): void {
        $start = new DateTimeImmutable('2026-10-05 10:00');
        $end = new DateTimeImmutable('2026-10-05 11:00');
        $manager = $this->createMock(IManager::class);
        $manager->expects(self::once())->method('checkAvailability')
            ->with($start, $end, self::isInstanceOf(IUser::class), ['bob@example.invalid', 'carla@example.invalid'])
            ->willReturn([]);

        $this->check($manager)->check('alice', $start, $end, [self::BOB, self::CARLA]);
    }

    /**
     * @param list<IAvailabilityResult> $results
     * @return IManager
     */
    private function manager(array $results): IManager {
        $manager = $this->createMock(IManager::class);
        $manager->method('checkAvailability')->willReturn($results);
        return $manager;
    }

    /** @return IAvailabilityResult */
    private function verdict(string $email, bool $available): IAvailabilityResult {
        $r = $this->createMock(IAvailabilityResult::class);
        $r->method('getAttendeeEmail')->willReturn($email);
        $r->method('isAvailable')->willReturn($available);
        return $r;
    }

    /** @return AvailabilityCheck */
    private function check(IManager $manager, ?LoggerInterface $logger = null): AvailabilityCheck {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(
            fn (string $uid) => $uid === 'alice' ? $this->createMock(IUser::class) : null,
        );
        return new AvailabilityCheck($manager, $users, $logger ?? $this->createMock(LoggerInterface::class));
    }

    /**
     * @param list<array{uid:string, email:string, displayName:string}> $attendees
     * @return array{busy: list<array{uid:string, displayName:string}>, unverifiable: list<array{uid:string, displayName:string}>, failed: bool}
     */
    private function runCheck(IManager $manager, array $attendees, string $organizer = 'alice', ?LoggerInterface $logger = null): array {
        return $this->check($manager, $logger)->check(
            $organizer,
            new DateTimeImmutable('2026-10-05 10:00'),
            new DateTimeImmutable('2026-10-05 11:00'),
            $attendees,
        );
    }
}
