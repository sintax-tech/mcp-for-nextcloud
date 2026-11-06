<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaGateway, TablesWriteCapabilities};
use OCA\Mcp\Modules\Tables\Writing\SchemaFlow;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/** Execution uses the same fail-closed, once-per-request capability path as discovery. */
final class SchemaFlowRegistryTest extends TestCase {
	/** @return void A throwing native probe cannot escape as a generic error or run twice in the request. */
	public function testThrowingProbeIsCachedDeniedAndWarnedOnce(): void {
		$config = (new InMemoryConfig())->mock($this);
		$grants = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$grants->setGrant('alice', 'tables', 'schema', true);
		$probe = $this->createMock(TablesWriteCapabilities::class);
		$probe->method('keys')->willReturn(['tables.native_schema_api']);
		$probe->expects($this->once())->method('supports')->willThrowException(new \RuntimeException('native private failure'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$gateway = $this->createMock(SchemaGateway::class);
		$gateway->expects($this->never())->method('prepare');
		$flow = new SchemaFlow($gateway, new PlanState($config), $grants, $this->createMock(TablesSessionGuard::class), $probe, new CapabilityRegistry([$probe], $logger));
		foreach ([1, 2] as $_) {
			try { $flow->preview(SchemaActionFixture::of($this, 'create_table'), ['title' => 'New'], 'alice'); $this->fail('Failed probe allowed execution'); }
			catch (ToolFailure $error) { $this->assertSame(SchemaMessages::incompatible(), $error->getMessage()); }
		}
	}
}
