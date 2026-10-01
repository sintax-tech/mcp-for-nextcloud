<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCA\Mcp\Tools\Files\Sharing\ShareFormatter;
use OCA\Mcp\Tools\Files\Sharing\ShareRecipientResolver;
use OCP\Constants;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** One item of files_list_shares: what it says about a share, and the password it never carries. */
final class ShareFormatterTest extends TestCase {
    private const PASSWORD = 'Sup3r-S3cret!pass';
    private FakeShares $shares;
    private ShareFormatter $formatter;
    /** @var list<array{string, array<string, mixed>}> routes asked from the URL generator */
    private array $routes = [];

    protected function setUp(): void {
        $this->shares = new FakeShares($this);
        $bruno = $this->createMock(IUser::class);
        $bruno->method('getDisplayName')->willReturn('Bruno Lima');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'bruno' ? $bruno : null);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(function (string $route, array $args = []): string {
            $this->routes[] = [$route, $args];
            return 'https://cloud.test/s/' . $args['token'];
        });
        $this->formatter = new ShareFormatter(new ShareRecipientResolver($users, $this->createMock(IGroupManager::class)), $urls);
    }

    public function testAUserShareListsEveryField(): void {
        $share = $this->shares->add(['with' => 'bruno', 'permissions' => 19, 'expires' => new \DateTime('2026-12-31 00:00:00')]);
        self::assertSame([
            'shareId' => 'ocinternal:1',
            'type' => 'user',
            'with' => ['id' => 'bruno', 'displayName' => 'Bruno Lima'],
            'permission' => 'edit',
            'reshare' => true,
            'expires' => '2026-12-31',
            'hasPassword' => false,
            'removable' => true,
            'path' => '/Relatorio.pdf',
        ], $this->formatter->item($share, '/Relatorio.pdf', true));
        self::assertSame([], $this->routes, 'só link tem URL');
    }

    public function testAFolderShareClassifiesWithFolderBits(): void {
        $share = $this->shares->add(['nodeType' => 'folder', 'permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE]);
        self::assertSame('custom', $this->formatter->item($share, '/Projetos', true)['permission']);
        $share = $this->shares->add(['nodeType' => 'folder', 'permissions' => 15]);
        self::assertSame('edit', $this->formatter->item($share, '/Projetos', true)['permission']);
    }

    public function testALinkHasItsUrlAndOnlySaysThatAPasswordExists(): void {
        $share = $this->shares->add(['type' => IShare::TYPE_LINK, 'with' => null, 'permissions' => 17, 'password' => self::PASSWORD, 'token' => 'AbCdEf123']);
        $item = $this->formatter->item($share, '/Relatorio.pdf', true);

        self::assertSame('link', $item['type']);
        self::assertSame('view', $item['permission']);
        self::assertTrue($item['hasPassword']);
        self::assertSame('https://cloud.test/s/AbCdEf123', $item['url']);
        self::assertSame([['files_sharing.sharecontroller.showShare', ['token' => 'AbCdEf123']]], $this->routes);
        self::assertSame('', $item['with']['id']);
        self::assertNull($item['expires']);
    }

    /** Invariant 2: no password, hashed or not, in any field of the item. */
    public function testThePasswordNeverLeavesInAnyShape(): void {
        foreach ([IShare::TYPE_LINK, IShare::TYPE_USER] as $type) {
            $share = $this->shares->add(['type' => $type, 'password' => self::PASSWORD, 'token' => 't']);
            $json = json_encode($this->formatter->item($share, '/x', true), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::PASSWORD, $json);
            self::assertStringNotContainsString('password"', $json, 'nenhum campo password');
        }
    }

    public function testATalkAttachmentIsListedReadOnlyWithTheRoomName(): void {
        $share = $this->shares->add(['type' => IShare::TYPE_ROOM, 'with' => 'r00mt0k', 'withName' => 'Diretoria']);
        $item = $this->formatter->item($share, '/Relatorio.pdf', false);
        self::assertSame('room', $item['type']);
        self::assertSame(['id' => 'r00mt0k', 'displayName' => 'Diretoria'], $item['with']);
        self::assertFalse($item['removable']);
        self::assertArrayNotHasKey('url', $item);
    }

    public function testAnEmptyPasswordIsNoPassword(): void {
        $share = $this->shares->add(['type' => IShare::TYPE_LINK, 'password' => '', 'token' => 't']);
        self::assertFalse($this->formatter->item($share, '/x', true)['hasPassword']);
    }
}
