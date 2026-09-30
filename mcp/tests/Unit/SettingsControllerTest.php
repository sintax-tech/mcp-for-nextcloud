<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Controller\McpController;
use OCA\Mcp\Controller\SettingsController;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase {
    private GrantPolicy $policy;
    private SettingsController $controller;
    private TokenService $tokens;
    private CheckoutTokenStore $checkoutTokens;

    protected function setUp(): void {
        $this->policy = new GrantPolicy((new InMemoryConfig())->mock($this));
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturn(true);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($alice);
        $this->tokens = $this->createMock(TokenService::class);
        $this->checkoutTokens = $this->createMock(CheckoutTokenStore::class);
        $this->controller = new SettingsController('mcp', $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class), $session, $this->policy, $this->tokens, $this->checkoutTokens);
    }

    public function testOnlyThePersonalFormRemainsAndItNeedsNoAdmin(): void {
        $this->assertFalse(method_exists(SettingsController::class, 'global'));
        $this->assertFalse(method_exists(SettingsController::class, 'user'));
        $this->assertSame([NoAdminRequired::class], $this->attributes(SettingsController::class, 'personal'));
    }

    public function testMcpEndpointAuthenticatesInsideTheController(): void {
        $this->assertEqualsCanonicalizing([PublicPage::class, NoAdminRequired::class, NoCSRFRequired::class],
            $this->attributes(McpController::class, 'post'));
    }

    /** Disconnecting has to kill the pending checkout links too, or a link outlives the switch. */
    public function testDisconnectAlsoDropsThePendingCheckoutLinks(): void {
        $this->checkoutTokens->expects($this->once())->method('deleteForUser')->with('alice');
        $this->controller->personal('0');
    }

    public function testConnectingDoesNotTouchTheCheckoutLinks(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->checkoutTokens->expects($this->never())->method('deleteForUser');
        $this->controller->personal('1');
    }

    public function testDisconnectRevokesOAuthTokens(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->controller->personal('1');
        $this->tokens->expects($this->once())->method('revokeUser')->with('alice');
        $this->controller->personal('0');
    }

    public function testPersonalActivationNeedsServiceAndEligibility(): void {
        $this->assertSame(400, $this->controller->personal('1')->getStatus());
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
