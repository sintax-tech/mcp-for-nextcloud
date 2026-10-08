<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service\Compat;

use OCA\Mcp\Service\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * "Enabled for anyone" asks the Nextcloud 32+ method when it exists and the Nextcloud 31 one otherwise, and an
 * optional app older than the oldest release MCP was checked against counts as off: its tools disappear.
 */
final class AppEnablementTest extends TestCase {
    /**
     * @param array<string, string> $versions installed version per app id
     * @param list<string> $enabled apps enabled for the user and for anyone
     */
    private function apps(array $versions, array $enabled): IAppManager {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $versions[$app] ?? '0');
        $apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => in_array($app, $enabled, true));
        $apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => in_array($app, $enabled, true));
        return $apps;
    }

    /** Talk 21.1.4 is the first Nextcloud 31 release whose ChatManager::addSystemMessage() takes the participant. */
    public function testTheMinimumVersionsAreTheOldestReleasesTheContractCovers(): void {
        $this->assertSame(['deck' => '1.15.0', 'spreed' => '21.1.4'], AppEnablement::MINIMUM_VERSIONS);
    }

    public function testAnOptionalAppOlderThanTheMinimumIsOffForTheUserAndForAnyone(): void {
        $user = $this->createMock(IUser::class);
        $old = $this->apps(['spreed' => '21.1.3', 'deck' => '1.15.0'], ['spreed', 'deck']);

        $this->assertFalse(AppEnablement::forUser($old, 'spreed', $user));
        $this->assertFalse(AppEnablement::forAnyone($old, 'spreed'));
        $this->assertTrue(AppEnablement::forUser($old, 'deck', $user));
        $this->assertTrue(AppEnablement::forAnyone($old, 'deck'));

        $current = $this->apps(['spreed' => '21.1.4'], ['spreed']);
        $this->assertTrue(AppEnablement::forUser($current, 'spreed', $user));
        $this->assertTrue(AppEnablement::forUser($this->apps(['spreed' => '23.0.11'], ['spreed']), 'spreed', $user));
    }

    public function testADisabledAppStaysOffWhateverItsVersion(): void {
        $user = $this->createMock(IUser::class);
        $apps = $this->apps(['spreed' => '23.0.0'], []);

        $this->assertFalse(AppEnablement::forUser($apps, 'spreed', $user));
        $this->assertFalse(AppEnablement::forAnyone($apps, 'spreed'));
    }

    /** Only a version known to be too old hides the module; an app without a minimum is never asked for its version. */
    public function testAppsWithoutAMinimumOrWithoutAKnownVersionAreNotHidden(): void {
        $user = $this->createMock(IUser::class);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $apps->expects($this->once())->method('getAppVersion')->with('spreed')->willReturn('');

        $this->assertTrue(AppEnablement::forUser($apps, 'notes', $user));
        $this->assertTrue(AppEnablement::forUser($apps, 'spreed', $user));
    }

    /** The admin notice lists the enabled apps that are too old, with what is installed and what is needed. */
    public function testUnsupportedListsOnlyEnabledAppsBelowTheirMinimum(): void {
        $apps = $this->apps(['spreed' => '21.0.4', 'deck' => '1.16.8'], ['spreed', 'deck']);
        $this->assertSame([['app' => 'spreed', 'installed' => '21.0.4', 'required' => '21.1.4']], AppEnablement::unsupported($apps));

        $this->assertSame([], AppEnablement::unsupported($this->apps(['spreed' => '21.0.4'], [])));
        $this->assertSame([], AppEnablement::unsupported($this->apps(['spreed' => '22.0.0', 'deck' => '1.16.0'], ['spreed', 'deck'])));
    }

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

            public function getAppVersion(string $appId, bool $useCache = true): string {
                return '21.1.11';
            }
        };

        $this->assertTrue(AppEnablement::forAnyone($apps, 'spreed'));
        $this->assertFalse(AppEnablement::forAnyone($apps, 'deck'));
        $this->assertSame(['spreed', 'deck'], $apps->asked);
    }
}
