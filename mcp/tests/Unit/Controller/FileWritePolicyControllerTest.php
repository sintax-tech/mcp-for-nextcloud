<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\FileWritePolicyController;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** Endpoint contract and sanitized failures; actual framework authorization is covered over HTTP. */
final class FileWritePolicyControllerTest extends TestCase {
	/**
	 * @param IAppConfig $config Core configuration mock.
	 * @param array<string, mixed> $body Submitted JSON.
	 * @return FileWritePolicyController Real policy behind the controller.
	 */
	private function controller(IAppConfig $config, array $body = []): FileWritePolicyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $body[$key] ?? $default);
		return new FileWritePolicyController('mcp', $request, new FileWritePolicy($config, $this->createMock(IDBConnection::class)));
	}

	/** No public/non-admin/no-CSRF exemptions may weaken framework middleware. */
	public function testDefaultAdminAndCsrfRequirementsArePreserved(): void {
		self::assertSame([], (new \ReflectionClass(FileWritePolicyController::class))->getAttributes());
		foreach (['show', 'update'] as $method) {
			self::assertSame([], (new \ReflectionMethod(FileWritePolicyController::class, $method))->getAttributes());
		}
	}

	/** An absent setting reads as off without writing any key. */
	public function testFreshLoadNeverCreatesASetting(): void {
		$store = new InMemoryAppConfig();
		self::assertSame(FileWritePolicy::defaults(), $this->controller($store->worker($this))->show()->getData());
		self::assertSame([], $store->stored);
	}

	/** A save responds with canonical data verified by a fresh service read. */
	public function testSaveReturnsCanonicalReadBack(): void {
		$store = new InMemoryAppConfig();
		$body = ['mode' => 'allowlist', 'extensions' => ".TXT,md\n", 'allowExtensionless' => true];
		$response = $this->controller($store->worker($this), $body)->update();
		self::assertSame(200, $response->getStatus());
		self::assertSame(['md', 'txt'], $response->getData()['extensions']);
		self::assertSame(1, $store->cacheClears);
		self::assertSame($response->getData(), $this->controller($store->worker($this))->show()->getData());
	}

	/** Invalid fields cannot change a valid existing configuration. */
	public function testInvalidFieldsReturn400WithoutWriting(): void {
		$valid = ['mode' => 'allowlist', 'extensions' => ['md'], 'allowExtensionless' => false];
		foreach (['mode' => [null, 1, 'denylist'], 'extensions' => [null, 1, 'a,,b', ['path' => 'txt'], ['*']], 'allowExtensionless' => [null, 1, 'true']] as $field => $values) {
			foreach ($values as $value) {
				$store = new InMemoryAppConfig();
				$store->stored = ['mcp' => [FileWritePolicy::KEY => json_encode(FileWritePolicy::defaults())]];
				$before = $store->stored;
				$response = $this->controller($store->worker($this), array_replace($valid, [$field => $value]))->update();
				self::assertSame(400, $response->getStatus());
				self::assertSame(['error' => 'invalid_policy', 'field' => $field], $response->getData());
				self::assertSame($before, $store->stored);
			}
		}
	}

	/** Neither failed reads nor unverified writes return off/saved or backend details. */
	public function testUnreadableAndUnverifiedStateIsExplicit(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturn('secret broken json');
		$controller = $this->controller($config, ['mode' => 'off', 'extensions' => [], 'allowExtensionless' => false]);
		foreach ([$controller->show(), $controller->update()] as $response) {
			self::assertSame(503, $response->getStatus());
			self::assertSame(['error' => 'policy_unavailable'], $response->getData());
		}
	}
}
