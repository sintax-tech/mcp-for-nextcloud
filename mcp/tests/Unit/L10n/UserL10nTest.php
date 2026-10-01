<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\L10n;

use OCA\Mcp\L10n\UserL10n;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

final class UserL10nTest extends TestCase {
    private function user(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testResolvesTheLanguageOfTheAccountNotOfTheRequest(): void {
        $l10n = new UserL10n(JsonL10n::wire($this->createMock(\OCP\L10N\IFactory::class), ['alice' => 'pt_BR', 'bob' => 'es']));
        $this->assertSame('pt_BR', $l10n->forUser($this->user('alice'))->getLanguageCode());
        $this->assertSame('es', $l10n->forUser($this->user('bob'))->getLanguageCode());
        $this->assertSame('en', $l10n->forUser($this->user('carol'))->getLanguageCode());
    }

    public function testLoadsTheTranslationsOfTheMcpApp(): void {
        $factory = $this->createMock(\OCP\L10N\IFactory::class);
        $factory->method('getUserLanguage')->willReturn('pt_BR');
        $factory->expects($this->once())->method('get')->with('mcp', 'pt_BR')->willReturn(new JsonL10n('pt_BR'));
        $this->assertSame('Desconectar', (new UserL10n($factory))->forUser($this->user('alice'))->t('Disconnect'));
    }
}
