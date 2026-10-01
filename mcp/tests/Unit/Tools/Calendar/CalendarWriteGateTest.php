<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarWriteGate;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class CalendarWriteGateTest extends TestCase {
    /** @var array<string, string> stored appconfig values */
    private array $stored = [];
    private string $appVersion = '0.6.10';
    private string $nextcloudVersion = '33.0.2.2';

    /** @return void */
    protected function setUp(): void {
        $this->stored = [];
    }

    /** @return void */
    public function testWithoutAVerificationOnlyReadingIsAvailable(): void {
        $gate = $this->gate();

        self::assertSame(['read'], $gate->operations());
        self::assertFalse($gate->invitationsVerified());
        self::assertNull($gate->record());
    }

    /** @return void */
    public function testAValidRecordOpensExactlyTheOperationsItProved(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create', 'edit', 'move', 'delete'], 'alice', '2026-09-30T12:00:00Z');

        self::assertSame(['read', 'create', 'edit', 'move', 'delete'], $gate->operations());
        self::assertFalse($gate->invitationsVerified(), 'the invitations proof is a separate operation');
        self::assertSame('2026-09-30T12:00:00Z', $gate->record()['at']);
    }

    /** @return void */
    public function testTheInvitationsProofIsOnlyHonouredWhenTheSelftestRecordedIt(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create', 'edit'], 'alice', '2026-09-30T12:00:00Z', true);

        self::assertTrue($gate->invitationsVerified());
        self::assertSame(['read', 'create', 'edit'], $gate->operations());
        self::assertTrue($gate->record()['invitations']);
    }

    /** @return void */
    public function testAnAppUpgradeReHidesTheWrites(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create', 'edit'], 'alice', '2026-09-30T12:00:00Z');
        $this->appVersion = '0.7.0';

        self::assertSame(['read'], $gate->operations());
        self::assertFalse($gate->invitationsVerified());
    }

    /** @return void */
    public function testAPatchReleaseOfNextcloudKeepsTheVerification(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create', 'edit'], 'alice', '2026-09-30T12:00:00Z');
        $this->nextcloudVersion = '33.0.4.1';

        self::assertSame(['read', 'create', 'edit'], $gate->operations());
    }

    /** @return void */
    public function testANewMinorOfNextcloudReHidesTheWrites(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create', 'edit'], 'alice', '2026-09-30T12:00:00Z');
        $this->nextcloudVersion = '34.0.1';

        self::assertSame(['read'], $gate->operations());
    }

    /** @return void */
    public function testANewMajorOfNextcloudReHidesTheWrites(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create'], 'alice', '2026-09-30T12:00:00Z');
        $this->nextcloudVersion = '40.1.0';

        self::assertSame(['read'], $gate->operations());
    }

    /** @return void */
    public function testRevokingDropsTheVerification(): void {
        $gate = $this->gate();
        $gate->recordVerification(['create', 'edit'], 'alice', '2026-09-30T12:00:00Z');
        $gate->revoke();

        self::assertSame(['read'], $gate->operations());
    }

    /** @return array<string, array{0: string}> */
    public static function unusableRecords(): array {
        return [
            'unknown operation' => ['{"app":"0.6.10","nextcloud":"33.0","operations":["create","oops"]}'],
            'not a list' => ['{"app":"0.6.10","nextcloud":"33.0","operations":{"x":"create"}}'],
            'wrong invitations type' => ['{"app":"0.6.10","nextcloud":"33.0","operations":["create"],"invitations":"true"}'],
            'not json' => ['nope'],
            'json but not an object' => ['[1,2,3]'],
            'missing operations' => ['{"app":"0.6.10","nextcloud":"33.0"}'],
            'operations not a list' => ['{"app":"0.6.10","nextcloud":"33.0","operations":"create"}'],
            'wrong app' => ['{"app":"0.6.9","nextcloud":"33.0","operations":["create"]}'],
            'wrong nextcloud' => ['{"app":"0.6.10","nextcloud":"32.0","operations":["create"]}'],
        ];
    }

    /** @dataProvider unusableRecords */
    public function testAnUnusableRecordNeverOpensAWrite(string $stored): void {
        $this->stored['mcp']['calendar_writes_verified'] = $stored;

        self::assertSame(['read'], $this->gate()->operations());
        self::assertFalse($this->gate()->invitationsVerified());
    }

    /** @return CalendarWriteGate */
    private function gate(): CalendarWriteGate {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->stored[$app][$key] ?? $default,
        );
        $appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
            $this->stored[$app][$key] = $value;
            return true;
        });
        $appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
            unset($this->stored[$app][$key]);
        });
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturnCallback(fn (): string => $this->appVersion);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = '') => $key === 'version' ? $this->nextcloudVersion : $default);

        return new CalendarWriteGate($appConfig, $appManager, $config);
    }
}