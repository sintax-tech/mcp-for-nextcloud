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
		$this->assertSame($this->limit->maxBytes(), $data['effectiveBytes']);
		$this->assertSame($this->limit->phpUnlimited() ? null : FileReadLimit::ceilingBytes($this->limit->phpMaxBytes()), $data['ceilingBytes']);
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
