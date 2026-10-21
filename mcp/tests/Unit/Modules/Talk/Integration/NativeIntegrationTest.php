<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk\Integration;

use OCA\Mcp\Modules\Talk\Integration\TalkAvailability;
use OCA\Mcp\Modules\Talk\Integration\TalkConstants;
use OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

class NativeIntegrationTest extends TestCase
{
    private IAppManager&MockObject $appManager;
    private ContainerInterface&MockObject $container;
    private TalkAvailability $services;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $this->services = new TalkAvailability($this->appManager, $users);
    }

    public function testIsEnabledForAsksAboutSpreedWithTheIUserNotTheUid(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $this->appManager
            ->expects($this->once())
            ->method('isEnabledForUser')
            ->with(TalkAvailability::APP_ID, $this->identicalTo($user))
            ->willReturn(true);

        $this->assertTrue((new TalkAvailability($this->appManager, $users))->isEnabledFor('alice'));
    }

    public function testUnknownUserIsNeverEnabled(): void
    {
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn(null);
        $this->appManager->expects($this->never())->method('isEnabledForUser');

        $this->assertFalse((new TalkAvailability($this->appManager, $users))->isEnabledFor('ghost'));
    }

    /** A Talk older than 21.1.4 is treated as absent: no Talk service is ever resolved for it. */
    public function testATalkOlderThanTheSupportedMinimumIsNotEnabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->appManager->method('getAppVersion')->with(TalkAvailability::APP_ID)->willReturn('21.1.3');
        $this->container->expects($this->never())->method('get');

        $this->assertFalse($this->services->isEnabledFor('alice'));
        $this->expectException(TalkUnavailableException::class);
        (new TalkServiceFactory(
            'OCA\\Talk\\Chat\\ChatManager',
            fn() => $this->container->get('OCA\\Talk\\Chat\\ChatManager'),
            $this->services,
        ))->forUser('alice');
    }

    public function testManagerRefusesToResolveWhenSpreedIsDisabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->container->expects($this->never())->method('get');

        $this->expectException(TalkUnavailableException::class);
        (new TalkServiceFactory(
            'OCA\\Talk\\Manager',
            fn() => $this->container->get('OCA\\Talk\\Manager'),
            $this->services,
        ))->forUser('alice');
    }

    public function testManagerThrowsWhenTheTalkClassIsNotLoaded(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->expects($this->never())->method('get');

        // OCA\Talk\Manager does not exist in this installation because the spreed app is absent.
        $this->expectException(TalkUnavailableException::class);
        (new TalkServiceFactory(
            'OCA\\Talk\\Manager',
            fn() => $this->container->get('OCA\\Talk\\Manager'),
            $this->services,
        ))->forUser('alice');
    }

    public function testChatManagerThrowsWhenTheTalkClassIsNotLoaded(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->expects($this->never())->method('get');

        $this->expectException(TalkUnavailableException::class);
        (new TalkServiceFactory(
            'OCA\\Talk\\Chat\\ChatManager',
            fn() => $this->container->get('OCA\\Talk\\Chat\\ChatManager'),
            $this->services,
        ))->forUser('alice');
    }

    public function testParticipantServiceThrowsWhenTheTalkClassIsNotLoaded(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->expects($this->never())->method('get');

        $this->expectException(TalkUnavailableException::class);
        (new TalkServiceFactory(
            'OCA\\Talk\\Service\\ParticipantService',
            fn() => $this->container->get('OCA\\Talk\\Service\\ParticipantService'),
            $this->services,
        ))->forUser('alice');
    }

    public function testResolveReturnsTheContainerServiceWhenTheClassExists(): void
    {
        $probe = new stdClass();
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->method('get')->willReturn($probe);

        $services = new TalkServiceFactory(
            \stdClass::class,
            fn() => $this->container->get(\stdClass::class),
            $this->services,
        );

        $this->assertSame($probe, $services->forUser('alice'));
    }

    public function testResolveThrowsWhenTheContainerReturnsNoObject(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->method('get')->willReturn('not an object');

        $services = new TalkServiceFactory(
            \stdClass::class,
            fn() => $this->container->get(\stdClass::class),
            $this->services,
        );

        $this->expectException(TalkUnavailableException::class);
        $services->forUser('alice');
    }

    public function testConversationConstantsRefuseWhenSpreedIsDisabled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $this->expectException(TalkUnavailableException::class);
        (new TalkConstants($this->services))->forUser('alice');
    }

    public function testConversationConstantsRefuseWhenTheTalkClassesAreAbsent(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        // With no spreed app installed none of the OCA\Talk classes exist, so the constants cannot be read.
        $this->expectException(TalkUnavailableException::class);
        (new TalkConstants($this->services))->forUser('alice');
    }

    /** Runs apart so the OCA\Talk aliases created here cannot leak into the test that needs them absent. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConversationConstantsAreReadFromTheTalkClassesInsteadOfBeingCopied(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->aliasTalkConstantFixtures();

        $constants = (new TalkConstants($this->services))->forUser('alice');

        // The fixtures carry values that are not the ones upstream uses: a copy of hardcoded numbers would fail here.
        $this->assertSame(
            [
                'chatPermission' => 512,
                'lobbyIgnorePermission' => 3,
                'readOnly' => 7,
                'changelogType' => 9,
                'lobbyNone' => 11,
                'actorUsers' => 'personas',
                'groupType' => 42,
            ],
            $constants,
        );
    }

    /** Makes the OCA\Talk constant holders resolvable in a test run where the spreed app is not installed. */
    private function aliasTalkConstantFixtures(): void
    {
        foreach (
            [
                'OCA\\Talk\\Model\\Attendee' => TalkAttendeeFixture::class,
                'OCA\\Talk\\Room' => TalkRoomFixture::class,
                'OCA\\Talk\\Webinary' => TalkWebinaryFixture::class,
            ]
            as $talkClass => $fixture
        ) {
            if (!class_exists($talkClass)) {
                class_alias($fixture, $talkClass);
            }
        }
    }
}

/** Stand-in for the permission bits of OCA\Talk\Model\Attendee, with values no upstream release uses. */
final class TalkAttendeeFixture
{
    public const PERMISSIONS_CHAT = 512;
    public const PERMISSIONS_LOBBY_IGNORE = 3;
    public const ACTOR_USERS = 'personas';
}

/** Stand-in for the room markers of OCA\Talk\Room, with values no upstream release uses. */
final class TalkRoomFixture
{
    public const READ_ONLY = 7;
    public const TYPE_CHANGELOG = 9;
    public const TYPE_GROUP = 42;
}

/** Stand-in for the lobby states of OCA\Talk\Webinary, with values no upstream release uses. */
final class TalkWebinaryFixture
{
    public const LOBBY_NONE = 11;
}
