<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use InvalidArgumentException;
use OCA\Mcp\Controller\McpController;
use OCA\Mcp\Controller\SettingsController;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase {
    private GrantPolicy $policy;
    private SettingsController $controller;

    protected function setUp(): void {
        $this->policy = new GrantPolicy((new InMemoryConfig())->mock($this));
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $alice : null);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($alice);
        $this->controller = new SettingsController('mcp', $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class), $users, $session, $this->policy);
    }

    public function testAdminEndpointsAreAdminOnlyAndCsrfProtected(): void {
        foreach (['global', 'user'] as $method) {
            $this->assertSame([], $this->attributes(SettingsController::class, $method), $method);
        }
        $this->assertSame([NoAdminRequired::class], $this->attributes(SettingsController::class, 'personal'));
    }

    public function testMcpEndpointAuthenticatesInsideTheController(): void {
        $this->assertEqualsCanonicalizing([PublicPage::class, NoAdminRequired::class, NoCSRFRequired::class],
            $this->attributes(McpController::class, 'post'));
    }

    public function testAdminChangesEligibilityAndGrants(): void {
        $this->controller->global('1');
        $this->controller->user('alice', '', '', '1', 'eligible');
        $this->controller->user('alice', 'calendar', 'transfer', '1');
        $this->assertTrue($this->policy->globalEnabled());
        $this->assertTrue($this->policy->eligible('alice'));
        $this->assertTrue($this->policy->granted('alice', 'calendar', 'transfer'));
    }

    public function testAdminInputIsValidated(): void {
        foreach ([['ghost', 'files', 'read', '1', 'grant'], ['alice', 'files', 'delete', '1', 'grant'], ['alice', '', '', 'yes', 'eligible'], ['alice', '', '', '1', 'other']] as $args) {
            try {
                $this->controller->user(...$args);
                $this->fail('accepted ' . implode(',', $args));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPersonalActivationNeedsServiceAndEligibility(): void {
        try {
            $this->controller->personal('1');
            $this->fail('connected without eligibility');
        } catch (InvalidArgumentException) {
        }
        $this->assertFalse($this->policy->connected('alice'));
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->controller->personal('1');
        $this->assertTrue($this->policy->canConnect('alice'));
        $this->controller->personal('0');
        $this->assertFalse($this->policy->canConnect('alice'));
    }

    private function attributes(string $class, string $method): array {
        return array_map(fn ($a) => $a->getName(), (new \ReflectionMethod($class, $method))->getAttributes());
    }
}
