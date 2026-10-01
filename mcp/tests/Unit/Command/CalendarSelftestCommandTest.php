<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Command;

use OCA\Mcp\Command\CalendarSelftest;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\Service\Calendar\CalendarSelftestService;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Calendar\CalendarWriteGate;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CalendarSelftestCommandTest extends TestCase {
    protected function tearDown(): void {
        Translator::reset();
    }

    public function testRevokeOutputsEnglishRevocationMessage(): void {
        $gate = new CalendarWriteGate($this->createMock(\OCP\IAppConfig::class), $this->createMock(\OCP\App\IAppManager::class), $this->createMock(\OCP\IConfig::class));
        
        $service = (new \ReflectionClass(CalendarSelftestService::class))->newInstanceWithoutConstructor();

        $command = new CalendarSelftest($service, $gate);
        $tester = new CommandTester($command);
        $tester->execute(['--revoke' => true]);

        $this->assertStringContainsString('Verification revoked; Calendar writes hidden.', $tester->getDisplay());
    }

    public function testExecutionUsesTheLanguageOfTheTargetUser(): void {
        $gate = new CalendarWriteGate($this->createMock(\OCP\IAppConfig::class), $this->createMock(\OCP\App\IAppManager::class), $this->createMock(\OCP\IConfig::class));
        $service = (new \ReflectionClass(CalendarSelftestService::class))->newInstanceWithoutConstructor();
        
        $user = $this->createMock(IUser::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $l10n = $this->createMock(UserL10n::class);
        $l10n->method('forUser')->with($user)->willReturn(new JsonL10n('pt_BR'));

        $command = new CalendarSelftest($service, $gate, $l10n, $userManager);
        $tester = new CommandTester($command);
        $tester->execute(['uid' => 'alice']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('A verificação falhou', $display);
    }
}
