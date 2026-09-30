<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

class TalkServicesTest extends TestCase {
    private IAppManager&MockObject $appManager;
    private ContainerInterface&MockObject $container;
    private TalkServices $services;

    protected function setUp(): void {
        parent::setUp();
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->services = new TalkServices($this->appManager, $this->container);
    }

    public function testIsEnabledForAsksAboutSpreedForTheUser(): void {
        $this->appManager->expects($this->once())
            ->method('isEnabledForUser')
            ->with(TalkServices::APP_ID, 'alice')
            ->willReturn(true);

        $this->assertTrue($this->services->isEnabledFor('alice'));
    }

    public function testManagerRefusesToResolveWhenSpreedIsDisabled(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->container->expects($this->never())->method('get');

        $this->expectException(TalkUnavailableException::class);
        $this->services->manager('alice');
    }

    public function testManagerThrowsWhenTheTalkClassIsNotLoaded(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->expects($this->never())->method('get');

        // OCA\Talk\Manager does not exist in this installation because the spreed app is absent.
        $this->expectException(TalkUnavailableException::class);
        $this->services->manager('alice');
    }

    public function testChatManagerThrowsWhenTheTalkClassIsNotLoaded(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->expects($this->never())->method('get');

        $this->expectException(TalkUnavailableException::class);
        $this->services->chatManager('alice');
    }

    public function testParticipantServiceThrowsWhenTheTalkClassIsNotLoaded(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->expects($this->never())->method('get');

        $this->expectException(TalkUnavailableException::class);
        $this->services->participantService('alice');
    }

    public function testResolveReturnsTheContainerServiceWhenTheClassExists(): void {
        $probe = new stdClass();
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->method('get')->willReturn($probe);

        $services = new TalkServicesWithProbe($this->appManager, $this->container);

        $this->assertSame($probe, $services->probeResolve(\stdClass::class, 'alice'));
    }

    public function testResolveThrowsWhenTheContainerReturnsNoObject(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->container->method('get')->willReturn('not an object');

        $services = new TalkServicesWithProbe($this->appManager, $this->container);

        $this->expectException(TalkUnavailableException::class);
        $services->probeResolve(\stdClass::class, 'alice');
    }

    public function testConversationConstantsRefuseWhenSpreedIsDisabled(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $this->expectException(TalkUnavailableException::class);
        $this->services->conversationConstants('alice');
    }

    public function testConversationConstantsRefuseWhenTheTalkClassesAreAbsent(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        // With no spreed app installed none of the OCA\Talk classes exist, so the constants cannot be read.
        $this->expectException(TalkUnavailableException::class);
        $this->services->conversationConstants('alice');
    }

    /** Runs apart so the OCA\Talk aliases created here cannot leak into the test that needs them absent. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConversationConstantsAreReadFromTheTalkClassesInsteadOfBeingCopied(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->aliasTalkConstantFixtures();

        $constants = $this->services->conversationConstants('alice');

        // The fixtures carry values that are not the ones upstream uses: a copy of hardcoded numbers would fail here.
        $this->assertSame([
            'chatPermission' => 512,
            'lobbyIgnorePermission' => 3,
            'readOnly' => 7,
            'changelogType' => 9,
            'lobbyNone' => 11,
        ], $constants);
    }

    /** Makes the OCA\Talk constant holders resolvable in a test run where the spreed app is not installed. */
    private function aliasTalkConstantFixtures(): void {
        foreach ([
            'OCA\\Talk\\Model\\Attendee' => TalkAttendeeFixture::class,
            'OCA\\Talk\\Room' => TalkRoomFixture::class,
            'OCA\\Talk\\Webinary' => TalkWebinaryFixture::class,
        ] as $talkClass => $fixture) {
            if (!class_exists($talkClass)) {
                class_alias($fixture, $talkClass);
            }
        }
    }
}

/** Exposes the protected resolution seam so the success path can be covered without the spreed app installed. */
class TalkServicesWithProbe extends TalkServices {
    /**
     * @param string $class Fully qualified class name
     * @param string $userId Authenticated user
     * @return object The resolved service
     * @throws TalkUnavailableException When the class is absent or the container returns no object
     */
    public function probeResolve(string $class, string $userId): object {
        return $this->resolve($class, $userId);
    }
}

/** Stand-in for the permission bits of OCA\Talk\Model\Attendee, with values no upstream release uses. */
final class TalkAttendeeFixture {
    public const PERMISSIONS_CHAT = 512;
    public const PERMISSIONS_LOBBY_IGNORE = 3;
}

/** Stand-in for the room markers of OCA\Talk\Room, with values no upstream release uses. */
final class TalkRoomFixture {
    public const READ_ONLY = 7;
    public const TYPE_CHANGELOG = 9;
}

/** Stand-in for the lobby states of OCA\Talk\Webinary, with values no upstream release uses. */
final class TalkWebinaryFixture {
    public const LOBBY_NONE = 11;
}
