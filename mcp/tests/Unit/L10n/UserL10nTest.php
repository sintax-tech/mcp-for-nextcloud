<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\L10n;

use OCA\Mcp\L10n\UserL10n;
use OCP\IUser;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class UserL10nTest extends TestCase {
    private function user(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testResolvesTheLanguageOfTheAccountNotOfTheRequest(): void {
        $l10n = new UserL10n(JsonL10n::wire($this->createMock(IFactory::class), ['alice' => 'pt_BR', 'bob' => 'es']));
        $this->assertSame('pt_BR', $l10n->forUser($this->user('alice'))->getLanguageCode());
        $this->assertSame('es', $l10n->forUser($this->user('bob'))->getLanguageCode());
        $this->assertSame('en', $l10n->forUser($this->user('carol'))->getLanguageCode());
    }

    public function testLoadsTheTranslationsOfTheMcpApp(): void {
        $factory = $this->createMock(IFactory::class);
        $factory->method('languageExists')->with('mcp', 'pt_BR')->willReturn(true);
        $iterator = new class implements \OCP\L10N\ILanguageIterator {
            private int $idx = 0;
            private array $items = ['pt_BR', 'en'];
            public function current(): string { return $this->items[$this->idx]; }
            public function key(): int { return $this->idx; }
            public function next(): void { $this->idx++; }
            public function rewind(): void { $this->idx = 0; }
            public function valid(): bool { return isset($this->items[$this->idx]); }
        };
        $factory->method('getLanguageIterator')->willReturn($iterator);
        $factory->expects($this->once())->method('get')->with('mcp', 'pt_BR')->willReturn(new JsonL10n('pt_BR'));
        $this->assertSame('Desconectar', (new UserL10n($factory))->forUser($this->user('alice'))->t('Disconnect'));
    }

    public function testUnsupportedAccountLanguageFallsBackToServerDefault(): void {
        $factory = JsonL10n::wire(
            $this->createMock(IFactory::class),
            ['alice' => 'de'], // German is not shipped in mcp
            'pt_BR',            // Server default is Brazilian Portuguese
        );
        $l10n = new UserL10n($factory);
        // Alice account has "de", which mcp lacks -> falls back to server default "pt_BR"
        $this->assertSame('pt_BR', $l10n->forUser($this->user('alice'))->getLanguageCode());
    }

    public function testUnsupportedAccountAndServerDefaultFallBackToEnglish(): void {
        $factory = JsonL10n::wire(
            $this->createMock(IFactory::class),
            ['alice' => 'de'], // German not shipped
            'it',              // Italian not shipped
        );
        $l10n = new UserL10n($factory);
        $this->assertSame('en', $l10n->forUser($this->user('alice'))->getLanguageCode());
    }

    public function testAbsentAccountPreferenceFallsBackToServerDefault(): void {
        $factory = JsonL10n::wire(
            $this->createMock(IFactory::class),
            [],      // No user preferences
            'es',    // Server default is Spanish
        );
        $l10n = new UserL10n($factory);
        $this->assertSame('es', $l10n->forUser($this->user('carol'))->getLanguageCode());
    }

    public function testForcedLanguageOverridesAccountPreference(): void {
        $factory = JsonL10n::wire(
            $this->createMock(IFactory::class),
            ['alice' => 'pt_BR'],
            'pt_BR',
            'es', // forcedLanguage
        );
        $l10n = new UserL10n($factory);
        $this->assertSame('es', $l10n->forUser($this->user('alice'))->getLanguageCode());
    }
}
