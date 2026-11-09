<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\FileReadLimitController;
use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** The admin edits the file read limit in MiB from the page, without occ; memory_limit still caps it. */
final class FileReadLimitControllerTest extends TestCase {
	private InMemoryConfig $config;
	private FileReadLimit $limit;

	protected function setUp(): void {
		$this->config = new InMemoryConfig();
		$this->limit = new FileReadLimit($this->config->mock($this));
	}

	private function controller(array $body = []): FileReadLimitController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $body) ? $body[$key] : $default);
		return new FileReadLimitController('mcp', $request, $this->limit, $this->config->mock($this));
	}

	public function testEveryEndpointIsAdminOnlyAndCsrfProtected(): void {
		foreach (['show', 'update'] as $method) {
			$this->assertSame([], (new \ReflectionMethod(FileReadLimitController::class, $method))->getAttributes(), $method);
		}
	}

	public function testShowReportsTheDefaultAndTheMemoryCeiling(): void {
		$data = $this->controller()->show()->getData();
		$this->assertSame(20 * 1024 * 1024, $data['configuredBytes']);
		$this->assertSame(FileReadLimit::DEFAULT_MAX_BYTES, $data['defaultBytes']);
		$this->assertSame($this->limit->phpMaxBytes(), $data['phpBytes']);
		$this->assertSame($this->limit->phpUnlimited(), $data['phpUnlimited']);
		$this->assertSame($this->limit->phpRejected(), $data['phpRejected']);
		$this->assertSame($this->limit->maxBytes(), $data['effectiveBytes']);
		$this->assertSame($this->limit->phpUnlimited() ? null : FileReadLimit::ceilingBytes($this->limit->phpMaxBytes()), $data['ceilingBytes']);
	}

	/**
	 * PHP will not start, or stay, on an unreadable memory_limit, so this process cannot flip the live directive.
	 * The response still carries the same answer FileReadLimit gives for "nope" and "0": rejected, and a setting
	 * above 20 MiB falls back to the default rather than to a fake lower ceiling.
	 */
	public function testShowNamesARejectedMemoryLimit(): void {
		$data = $this->controller()->show()->getData();
		$this->assertFalse($data['phpRejected']);
		$this->assertSame(FileReadLimit::memoryRejected((string)ini_get('memory_limit')), $data['phpRejected']);
		foreach (['nope', '0'] as $value) {
			$this->assertTrue(FileReadLimit::memoryRejected($value), $value);
			$this->assertSame(FileReadLimit::DEFAULT_MAX_BYTES, FileReadLimit::effective(200 * 1024 * 1024, $value), $value);
		}
		$this->assertFalse(FileReadLimit::memoryRejected('-1'));
		$updated = $this->controller(['mib' => 40])->update()->getData();
		$this->assertArrayHasKey('phpRejected', $updated);
		$this->assertSame($this->limit->phpRejected(), $updated['phpRejected']);
	}

	public function testUpdateStoresWholeMibAsBytes(): void {
		$data = $this->controller(['mib' => 5])->update()->getData();
		$this->assertSame((string)(5 * 1024 * 1024), $this->config->app['mcp'][FileReadLimit::MAX_BYTES_KEY]);
		$this->assertSame(5 * 1024 * 1024, $data['configuredBytes']);
		$this->assertSame($this->limit->maxBytes(), $data['effectiveBytes']);
		$this->assertSame(5 * 1024 * 1024, $this->limit->configuredMaxBytes());
	}

	public function testUpdateRejectsAnythingButAPositiveWholeNumberWithoutWriting(): void {
		foreach ([[], ['mib' => 0], ['mib' => -5], ['mib' => 1.5], ['mib' => '100'], ['mib' => true], ['mib' => null],
			['mib' => FileReadLimitController::MAX_MIB + 1]] as $body) {
			$response = $this->controller($body)->update();
			$this->assertSame([400, ['error' => 'Invalid request']], [$response->getStatus(), $response->getData()], json_encode($body));
		}
		$this->assertArrayNotHasKey(FileReadLimit::MAX_BYTES_KEY, $this->config->app['mcp'] ?? []);
		$this->assertSame(200, $this->controller(['mib' => FileReadLimitController::MAX_MIB])->update()->getStatus());
		$this->assertSame((string)(FileReadLimitController::MAX_MIB * 1024 * 1024), $this->config->app['mcp'][FileReadLimit::MAX_BYTES_KEY]);
	}
}
