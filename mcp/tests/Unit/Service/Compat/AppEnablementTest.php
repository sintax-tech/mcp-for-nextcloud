<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service\Compat;

use OCA\Mcp\Service\Compat\AppEnablement;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/** "Enabled for anyone" asks the Nextcloud 32+ method when it exists and the Nextcloud 31 one otherwise. */
final class AppEnablementTest extends TestCase {
    public function testNextcloud32AndLaterAskIsEnabledForAnyone(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->expects($this->once())->method('isEnabledForAnyone')->with('deck')->willReturn(true);
        $apps->expects($this->never())->method('isInstalled');

        $this->assertTrue(AppEnablement::forAnyone($apps, 'deck'));
    }

    /** Nextcloud 31's IAppManager has no isEnabledForAnyone(); its isInstalled() is the same check under the old name. */
    public function testNextcloud31FallsBackToIsInstalled(): void {
        $apps = new class {
            /** @var list<string> */
            public array $asked = [];

            public function isInstalled($appId): bool {
                $this->asked[] = $appId;
                return $appId === 'spreed';
            }
        };

        $this->assertTrue(AppEnablement::forAnyone($apps, 'spreed'));
        $this->assertFalse(AppEnablement::forAnyone($apps, 'deck'));
        $this->assertSame(['spreed', 'deck'], $apps->asked);
    }
}
