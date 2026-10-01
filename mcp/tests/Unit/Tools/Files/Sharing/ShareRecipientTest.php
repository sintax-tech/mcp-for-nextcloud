<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\Sharing\ShareRecipient;
use OCA\Mcp\Tools\Files\Sharing\ShareRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** Parsing `user:<uid>`, `group:<gid>` and `link`, and the names a share list shows for each recipient. */
final class ShareRecipientTest extends TestCase {
    private ShareRecipientResolver $resolver;

    protected function setUp(): void {
        $bruno = $this->createMock(IUser::class);
        $bruno->method('getUID')->willReturn('bruno');
        $bruno->method('getDisplayName')->willReturn('Bruno Lima');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'bruno' ? $bruno : null);
        $finance = $this->createMock(IGroup::class);
        $finance->method('getGID')->willReturn('finance');
        $finance->method('getDisplayName')->willReturn('Financeiro');
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturnCallback(fn (string $gid) => $gid === 'finance' ? $finance : null);
        $this->resolver = new ShareRecipientResolver($users, $groups);
    }

    public function testUserResolvesToTheAccountAndItsDisplayName(): void {
        $recipient = $this->resolver->resolve('user:bruno');
        self::assertSame(ShareRecipient::USER, $recipient->kind);
        self::assertSame('bruno', $recipient->id);
        self::assertSame('Bruno Lima', $recipient->displayName);
        self::assertSame(IShare::TYPE_USER, $recipient->shareType());
        self::assertSame('user:bruno', $recipient->spec());
    }

    public function testGroupResolvesToTheGroupAndItsDisplayName(): void {
        $recipient = $this->resolver->resolve(' group:finance ');
        self::assertSame(ShareRecipient::GROUP, $recipient->kind);
        self::assertSame('finance', $recipient->id);
        self::assertSame('Financeiro', $recipient->displayName);
        self::assertSame(IShare::TYPE_GROUP, $recipient->shareType());
        self::assertSame('group:finance', $recipient->spec());
    }

    public function testLinkNeedsNoAccount(): void {
        $recipient = $this->resolver->resolve('link');
        self::assertSame(ShareRecipient::LINK, $recipient->kind);
        self::assertSame('', $recipient->id);
        self::assertSame(IShare::TYPE_LINK, $recipient->shareType());
        self::assertSame('link', $recipient->spec());
        self::assertNotSame('', $recipient->displayName);
    }

    /** @return array<string, array{string, string}> */
    public static function invalid(): array {
        return [
            'no prefix' => ['bruno', 'expected user:<uid>, group:<gid> or link'],
            'empty id' => ['user:', 'expected user:<uid>, group:<gid> or link'],
            'e-mail is out of the 0.10' => ['email:x@y.z', 'expected user:<uid>, group:<gid> or link'],
            'unknown account' => ['user:<script>ghost', 'unknown account'],
            'unknown group' => ['group:ghost-team', 'unknown group'],
        ];
    }

    /** @dataProvider invalid */
    public function testInvalidRecipientsFailWithFieldAndRuleOnly(string $with, string $rule): void {
        try {
            $this->resolver->resolve($with, 'with');
            self::fail('no exception');
        } catch (ArgumentValidationException $e) {
            self::assertSame(['field' => 'with', 'rule' => $rule], $e->details());
            foreach (['ghost', 'script', 'x@y.z', 'bruno'] as $echo) {
                self::assertStringNotContainsString($echo, $e->getMessage() . $e->clientMessage());
            }
        }
    }

    public function testTheFieldFollowsTheCaller(): void {
        try {
            $this->resolver->resolve('nobody', 'recipients.0');
            self::fail('no exception');
        } catch (ArgumentValidationException $e) {
            self::assertSame('recipients.0', $e->details()['field']);
        }
    }

    public function testOfShareNamesEveryType(): void {
        self::assertSame(['type' => 'user', 'id' => 'bruno', 'displayName' => 'Bruno Lima'],
            $this->resolver->ofShare($this->share(IShare::TYPE_USER, 'bruno'))->toArray());
        self::assertSame(['type' => 'group', 'id' => 'finance', 'displayName' => 'Financeiro'],
            $this->resolver->ofShare($this->share(IShare::TYPE_GROUP, 'finance'))->toArray());
        self::assertSame('link', $this->resolver->ofShare($this->share(IShare::TYPE_LINK, null))->toArray()['type']);
        self::assertSame(['type' => 'room', 'id' => 'abc123', 'displayName' => 'Diretoria'],
            $this->resolver->ofShare($this->share(IShare::TYPE_ROOM, 'abc123', 'Diretoria'))->toArray());
    }

    /** An account or a group removed after the share still has a name to show: its id. */
    public function testOfShareFallsBackToTheIdWhenTheRecipientIsGone(): void {
        self::assertSame('ghost', $this->resolver->ofShare($this->share(IShare::TYPE_USER, 'ghost'))->displayName);
        self::assertSame('old-team', $this->resolver->ofShare($this->share(IShare::TYPE_GROUP, 'old-team'))->displayName);
        self::assertSame('abc123', $this->resolver->ofShare($this->share(IShare::TYPE_ROOM, 'abc123', ''))->displayName);
    }

    public function testMatchesComparesTypeAndRecipient(): void {
        $bruno = $this->resolver->resolve('user:bruno');
        self::assertTrue($bruno->matches($this->share(IShare::TYPE_USER, 'bruno')));
        self::assertFalse($bruno->matches($this->share(IShare::TYPE_USER, 'carla')));
        self::assertFalse($bruno->matches($this->share(IShare::TYPE_GROUP, 'bruno')));
        self::assertTrue($this->resolver->resolve('link')->matches($this->share(IShare::TYPE_LINK, null)));
    }

    private function share(int $type, ?string $with, ?string $display = null): IShare {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn($type);
        $share->method('getSharedWith')->willReturn($with);
        $share->method('getSharedWithDisplayName')->willReturn($display);
        return $share;
    }
}
