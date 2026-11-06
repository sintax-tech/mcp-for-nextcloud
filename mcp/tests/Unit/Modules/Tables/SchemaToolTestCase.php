<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Writing\{SchemaFlow, SchemaRequest};
use OCA\Mcp\Modules\Tables\Integration\{SchemaGateway, TablesWriteCapabilities};
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Mcp\Confirmation\{PlanState, PlanChanged};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;

/** Shared behavioral contracts, executed separately for every published schema/share tool. */
abstract class SchemaToolTestCase extends TestCase {
	protected const ACTION = '';
	protected SchemaGateway $gateway;
	protected GrantPolicy $grants;
	protected TablesSessionGuard $session;
	protected SchemaFlow $flow;
	/** @return void Fresh policy and native boundary for each test. */
	protected function setUp(): void {
		$config = (new InMemoryConfig())->mock($this);
		$this->grants = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$this->grants->setGrant('alice', 'tables', $this->sharing() ? 'share' : 'schema', true);
		$this->gateway = $this->createMock(SchemaGateway::class);
		$this->session = $this->createMock(TablesSessionGuard::class);
		$capabilities = $this->createMock(TablesWriteCapabilities::class);
		$capabilities->method('supports')->willReturn(true); $capabilities->method('profile')->willReturn('2.3.2');
		$this->gateway->method('prepare')->willReturnCallback(fn ($action, $args): array => ['arguments' => $args, 'profile' => '2.3.2', 'version' => '2.3.2', 'before' => ['title' => 'old'], 'after' => static::ACTION === 'unshare_node' ? [] : ['title' => 'new'], 'shared' => false, 'noop' => false]);
		$this->flow = new SchemaFlow($this->gateway, new PlanState($config), $this->grants, $this->session, $capabilities);
	}
	/** @return bool Whether this tool uses tables.share. */
	private function sharing(): bool { return in_array(static::ACTION, ['share_node', 'unshare_node'], true); }
	/** @return array<string,mixed> Minimal valid request for this tool. */
	protected function arguments(): array {
		return match (static::ACTION) {
			'create_table' => ['title' => 'new'],
			'update_table' => ['table_id' => 1, 'patch' => ['title' => 'new']],
			'create_column' => ['table_id' => 1, 'title' => 'new', 'type' => 'text', 'subtype' => 'line'],
			'update_column' => ['table_id' => 1, 'column_id' => 2, 'patch' => ['title' => 'new']],
			'create_view' => ['table_id' => 1, 'title' => 'new'],
			'update_view' => ['table_id' => 1, 'view_id' => 2, 'patch' => ['title' => 'new']],
			'share_node' => ['node_type' => 'table', 'node_id' => 1, 'recipient' => ['type' => 'user', 'id' => 'bob'], 'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]],
			'unshare_node' => ['node_type' => 'view', 'node_id' => 1, 'share_id' => 2],
		};
	}
	/** @return void Preview never calls a native mutator. */
	public function testPreviewHasNoEffect(): void {
		$this->gateway->expects($this->never())->method('apply');
		$this->assertTrue($this->flow->preview(static::ACTION, $this->arguments(), 'alice')['requiresConfirmation']);
	}
	/** @return void No write operation inherits read/create grants. */
	public function testGrantDeniedBeforeNativeLookup(): void {
		$this->grants->setGrant('alice', 'tables', $this->sharing() ? 'share' : 'schema', false);
		$this->gateway->expects($this->never())->method('prepare');
		$this->expectException(ToolFailure::class);
		$this->flow->preview(static::ACTION, $this->arguments(), 'alice');
	}
	/** @return void Closed schema rejects extras before native lookup. */
	public function testClosedSchema(): void {
		$this->gateway->expects($this->never())->method('prepare');
		$this->expectException(\InvalidArgumentException::class);
		$this->flow->preview(static::ACTION, $this->arguments() + ['uuid' => 'hidden'], 'alice');
	}
	/** @return void Confirm is strictly boolean; string true cannot cross the gate. */
	public function testNoCoercion(): void {
		$this->gateway->expects($this->never())->method('apply');
		$this->expectException(\InvalidArgumentException::class);
		SchemaRequest::of(static::ACTION, $this->arguments() + ['confirm' => 'true']);
	}
	/** @return void Divergent approval never applies the effect. */
	public function testDivergentState(): void {
		$this->gateway->expects($this->never())->method('apply');
		$this->expectException(PlanChanged::class);
		$this->flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => 'wrong'], 'alice');
	}
	/** @return void A confirmed call executes once and observes after an exception too. */
	public function testSingleAttemptAndReadbackAfterFailure(): void {
		$plan = $this->flow->preview(static::ACTION, $this->arguments(), 'alice');
		$journal = []; $afterPreview = $journal;
		$this->gateway->expects($this->once())->method('apply')->willReturnCallback(static function () use (&$journal): never { $journal[] = 'attempt'; throw new \RuntimeException('secret native error'); });
		$this->gateway->expects($this->once())->method('observe')->willReturnCallback(static function () use (&$journal): array { $journal[] = 'readback'; return static::ACTION === 'unshare_node' ? [] : ['title' => 'new']; });
		$r = $this->flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$text = $r['content'][0]['text']; $this->assertStringNotContainsString('secret native error', $text);
		$this->assertSame('observed', json_decode($text, true)['status']);
		\OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile::assertMatches($this, __DIR__ . '/../../Contract/effects/tables.' . str_replace('_', '-', static::ACTION) . '.json',
			['tool' => 'tables_' . static::ACTION, 'previewJournal' => $afterPreview, 'confirmedJournal' => $journal, 'receipt' => json_decode($text, true)]);
	}
	/** @return void Failed or denied observation never fabricates success. */
	public function testReadbackFailureIsUnverified(): void {
		$plan = $this->flow->preview(static::ACTION, $this->arguments(), 'alice');
		$this->gateway->expects($this->once())->method('apply')->willReturn(3);
		$this->gateway->method('observe')->willThrowException(new ToolFailure('private payload'));
		$r = $this->flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$this->assertSame('unverified', json_decode($r['content'][0]['text'], true)['status']);
		$this->assertStringNotContainsString('private payload', $r['content'][0]['text']);
	}
	/** @return void Third-party native ownership requires the independent shared confirmation. */
	public function testSharedConfirmationCannotBeOmitted(): void {
		$gateway = $this->createMock(SchemaGateway::class);
		$gateway->method('prepare')->willReturnCallback(static fn ($action, $args): array => ['arguments' => $args, 'version' => '2.3.2', 'profile' => '2.3.2', 'shared' => true, 'before' => [], 'after' => [], 'noop' => false]);
		$gateway->expects($this->never())->method('apply');
		$config = (new InMemoryConfig())->mock($this);
		$caps = $this->createMock(TablesWriteCapabilities::class);
		$caps->method('supports')->willReturn(true);
		$caps->method('profile')->willReturn('2.3.2');
		$flow = new SchemaFlow($gateway, new PlanState($config), $this->grants, $this->session, $caps);
		$plan = $flow->preview(static::ACTION, $this->arguments(), 'alice');
		$result = $flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$this->assertTrue(json_decode($result['content'][0]['text'], true)['requiresConfirmation']);
	}
	/** @return void Revoking the grant during the native attempt prevents private readback. */
	public function testReadbackRenewsTheGrant(): void {
		$plan = $this->flow->preview(static::ACTION, $this->arguments(), 'alice');
		$this->gateway->method('apply')->willReturnCallback(function (): ?int {
			$this->grants->setGrant('alice', 'tables', $this->sharing() ? 'share' : 'schema', false);
			return 3;
		});
		$this->gateway->expects($this->never())->method('observe');
		$result = $this->flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$this->assertSame('unverified', json_decode($result['content'][0]['text'], true)['status']);
	}

	/** @return void Public status follows canonical per-field comparison, regardless of native key order. */
	public function testCanonicalReceiptStatus(): void {
		$gateway = $this->createMock(SchemaGateway::class);
		$expected = ['title' => 'New', 'description' => 'D', 'emoji' => 'X', 'numberDefault' => 5];
		$gateway->method('prepare')->willReturnCallback(static fn ($action, $args): array => ['arguments' => $args, 'version' => '2.3.2', 'profile' => '2.3.2', 'shared' => false, 'before' => [], 'after' => $expected, 'noop' => false]);
		$gateway->method('observe')->willReturn(['emoji' => 'X', 'description' => 'D', 'numberDefault' => 5.0, 'title' => 'New']);
		$config = (new InMemoryConfig())->mock($this);
		$caps = $this->createMock(TablesWriteCapabilities::class);
		$caps->method('supports')->willReturn(true);
		$caps->method('profile')->willReturn('2.3.2');
		$flow = new SchemaFlow($gateway, new PlanState($config), $this->grants, $this->session, $caps);
		$plan = $flow->preview(static::ACTION, $this->arguments(), 'alice');
		$result = $flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$receipt = json_decode($result['content'][0]['text'], true);
		$this->assertSame('observed', $receipt['status']);
		$this->assertSame([], $receipt['differentFields']);
	}

}
