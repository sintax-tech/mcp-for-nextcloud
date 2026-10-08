<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\Controller\CheckoutLimitController;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** The admin edits the checkout upload limit in MiB from the page, without occ; PHP's ceiling still applies. */
final class CheckoutLimitControllerTest extends TestCase {
    private InMemoryConfig $config;
    private CheckoutService $checkout;

    protected function setUp(): void {
        $this->config = new InMemoryConfig();
        $config = $this->config->mock($this);
        $this->checkout = new CheckoutService($this->createMock(IURLGenerator::class), $config, $this->createMock(ITimeFactory::class),
            new TokenHasher($config), $this->createMock(CheckoutTokenStore::class), $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
    }

    private function controller(array $body = []): CheckoutLimitController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $body) ? $body[$key] : $default);
        return new CheckoutLimitController('mcp', $request, $this->checkout, $this->config->mock($this));
    }

    /** The effective limit is the configured one capped by post_max_size, exactly as CheckoutService applies it. */
    private function expectedEffective(int $configured): int {
        $php = $this->checkout->phpMaxBytes();
        return $php > 0 ? min($configured, $php) : $configured;
    }

    public function testEveryEndpointIsAdminOnlyAndCsrfProtected(): void {
        foreach (['show', 'update'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(CheckoutLimitController::class, $method))->getAttributes(), $method);
        }
    }

    public function testShowReportsTheDefaultAndThePhpCeiling(): void {
        $data = $this->controller()->show()->getData();
        $this->assertSame(50 * 1024 * 1024, $data['configuredBytes']);
        $this->assertSame(CheckoutService::DEFAULT_MAX_BYTES, $data['defaultBytes']);
        $this->assertSame($this->checkout->phpMaxBytes(), $data['phpBytes']);
        $this->assertSame($this->expectedEffective(50 * 1024 * 1024), $data['effectiveBytes']);
    }

    public function testUpdateStoresWholeMibAsBytes(): void {
        $data = $this->controller(['mib' => 200])->update()->getData();
        $this->assertSame((string)(200 * 1024 * 1024), $this->config->app['mcp'][CheckoutService::MAX_BYTES_KEY]);
        $this->assertSame(200 * 1024 * 1024, $data['configuredBytes']);
        $this->assertSame($this->expectedEffective(200 * 1024 * 1024), $data['effectiveBytes']);
        $this->assertSame($this->expectedEffective(200 * 1024 * 1024), $this->checkout->maxBytes(), 'files_checkout uses the new limit at once');
    }

    public function testUpdateRejectsAnythingButAPositiveWholeNumberWithoutWriting(): void {
        foreach ([[], ['mib' => 0], ['mib' => -5], ['mib' => 1.5], ['mib' => '100'], ['mib' => true], ['mib' => null],
            ['mib' => CheckoutLimitController::MAX_MIB + 1]] as $body) {
            $response = $this->controller($body)->update();
            $this->assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()], json_encode($body));
        }
        $this->assertArrayNotHasKey(CheckoutService::MAX_BYTES_KEY, $this->config->app['mcp'] ?? []);
        $this->assertSame(200, $this->controller(['mib' => CheckoutLimitController::MAX_MIB])->update()->getStatus());
    }
}
