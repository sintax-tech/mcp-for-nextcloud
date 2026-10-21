<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Controller\McpController;
use OCA\Mcp\Controller\SettingsController;
use OCA\Mcp\Modules\Files\Checkout\CheckoutTokenStore;
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;
use OCA\Mcp\Access\Grants\GrantPolicy;
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
    private InMemoryConfig $data;
    private \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore $store;
    private CheckoutTokenStore $checkoutTokens;
    private BatchStore $batches;
    private bool $aliceEnabled = true;
    private bool $signedIn = true;

    protected function setUp(): void {
        $this->data = new InMemoryConfig();
        $this->store = new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore();
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($this->data->mock($this), $this->store);
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturnCallback(fn (): bool => $this->aliceEnabled);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn () => $this->signedIn ? $alice : null);
        $this->checkoutTokens = $this->createMock(CheckoutTokenStore::class);
        $this->batches = $this->createMock(BatchStore::class);
        $this->controller = new SettingsController('mcp', $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class), $session, $this->policy, $this->checkoutTokens,
            $this->batches);
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

    /** A batch waiting to be undone must not outlive the connection that moved the files. */
    public function testDisconnectAlsoDropsTheBatchesWaitingToBeUndone(): void {
        $this->batches->expects($this->once())->method('deleteForUser')->with('alice');
        $this->controller->personal('0');
    }

    public function testConnectingDoesNotTouchTheBatches(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->batches->expects($this->never())->method('deleteForUser');
        $this->controller->personal('1');
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
        $this->store->insertToken(['user_id' => 'alice', 'client_id' => 'c', 'refresh_expires' => PHP_INT_MAX, 'auth_gen' => '1'], 0);
        $this->controller->personal('0');
        // A cut at the last issued generation, in the transaction of connected = '0', then the physical cleanup.
        $this->assertEquals(['issue_seq' => '1', 'revoked_below' => '1'], $this->data->generations['alice']);
        $this->assertSame([], $this->store->tokens);
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

    /** The two conditions of an activation are independent: each alone is enough to refuse it. */
    public function testActivationIsRefusedWhenOnlyTheServiceIsOff(): void {
        $this->policy->setGlobalEnabled(false);
        $this->policy->setEligible('alice', true);

        $this->assertSame(400, $this->controller->personal('1')->getStatus());
        $this->assertFalse($this->policy->connected('alice'));
    }

    public function testActivationIsRefusedWhenOnlyTheEligibilityIsMissing(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', false);

        $this->assertSame(400, $this->controller->personal('1')->getStatus());
        $this->assertFalse($this->policy->connected('alice'));
    }

    /** @return array<string, array{string}> */
    public static function invalidValuesProvider(): array {
        return ['two' => ['2'], 'word' => ['true'], 'yes' => ['yes'], 'empty' => [''], 'padded' => [' 1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValuesProvider')]
    public function testAnyValueButZeroOrOneIsRefusedAndChangesNothing(string $value): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        $this->assertSame(400, $this->controller->personal($value)->getStatus());
        $this->assertArrayNotHasKey('alice', $this->data->generations, 'nothing was revoked');
        $this->assertTrue($this->policy->connected('alice'), 'the connection stayed as it was');
    }

    public function testADisabledAccountOrNoSessionCannotChangeTheConnection(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        $this->aliceEnabled = false;
        $this->assertSame(400, $this->controller->personal('0')->getStatus());
        $this->aliceEnabled = true;
        $this->signedIn = false;
        $this->assertSame(400, $this->controller->personal('0')->getStatus());
        $this->assertTrue($this->policy->connected('alice'));
    }

    public function testASuccessfulChangeRedirectsBackToThePersonalSection(): void {
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);

        $this->assertInstanceOf(\OCP\AppFramework\Http\RedirectResponse::class, $this->controller->personal('1'));
    }

    private function attributes(string $class, string $method): array {
        return array_map(fn ($a) => $a->getName(), (new \ReflectionMethod($class, $method))->getAttributes());
    }

    /** A policy storage failure is a 503 that revokes and assumes nothing, never a 500. */
    public function testAPolicyStorageFailureIsA503AndTouchesNothingElse(): void {
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('setConnected')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable());
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('isEnabled')->willReturn(true);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $this->checkoutTokens->expects($this->never())->method('deleteForUser');
        $controller = new SettingsController('mcp', $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class), $session, $policy, $this->checkoutTokens, $this->batches);

        $this->assertSame(503, $controller->personal('0')->getStatus());
    }
}
