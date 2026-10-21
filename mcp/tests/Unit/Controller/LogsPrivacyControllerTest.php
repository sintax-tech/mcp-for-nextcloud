<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\LogsPrivacyController;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

final class LogsPrivacyControllerTest extends TestCase {
    private InMemoryConfig $config;
    private InMemoryAppConfig $appConfig;

    protected function setUp(): void {
        $this->config = new InMemoryConfig();
        $this->appConfig = new InMemoryAppConfig();
    }

    private function controller(array $body = []): LogsPrivacyController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $body[$key] ?? $default);
        $guard = new VisibilityGuard($this->config->mock($this), $this->createMock(ISystemTagObjectMapper::class));
        return new LogsPrivacyController('mcp', $request, new LogFileNames($this->appConfig->worker($this), $guard));
    }

    public function testAdminOnlyLikeTheOtherSettings(): void {
        foreach (['show', 'update'] as $method) {
            $this->assertSame([], (new \ReflectionMethod(LogsPrivacyController::class, $method))->getAttributes(), $method);
        }
    }

    public function testOnByDefaultAndActiveOnlyWithHiddenTags(): void {
        $this->assertSame(['hideFileNames' => true, 'active' => false], $this->controller()->show()->getData());

        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $this->assertSame(['hideFileNames' => true, 'active' => true], $this->controller()->show()->getData());
    }

    public function testSavesTheSwitch(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';

        $off = $this->controller(['hideFileNames' => false])->update();
        $this->assertSame(Http::STATUS_OK, $off->getStatus());
        $this->assertSame(['hideFileNames' => false, 'active' => false], $off->getData());
        $this->assertSame(['hideFileNames' => false, 'active' => false], $this->controller()->show()->getData());

        $this->assertSame(['hideFileNames' => true, 'active' => true], $this->controller(['hideFileNames' => true])->update()->getData());
    }

    public function testRefusesAnythingButABoolean(): void {
        foreach ([[], ['hideFileNames' => '0'], ['hideFileNames' => 1]] as $body) {
            $response = $this->controller($body)->update();
            $this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()]);
        }
        $this->assertSame([], $this->appConfig->stored);
    }
}
