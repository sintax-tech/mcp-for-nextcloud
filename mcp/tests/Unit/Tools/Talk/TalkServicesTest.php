<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCP\App\IAppManager;
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
