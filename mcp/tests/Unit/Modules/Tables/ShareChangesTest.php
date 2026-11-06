<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Sharing\ShareChanges;
use OCA\Mcp\Modules\Tables\Presentation\SchemaSnapshot;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Existing shares never become implicit permission updates or public-token receipts. */
final class ShareChangesTest extends TestCase {
	/** @return array<string,mixed> Managed node request. */
	private function prepared(): array { return ['arguments' => ['node_type' => 'table', 'node_id' => 1, 'recipient' => ['type' => 'user', 'id' => 'bob'], 'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]], 'noop' => false]; }
	/** @return array<string,mixed> One private native user share. */
	private function share(): array { return ['id' => 8, 'nodeType' => 'table', 'nodeId' => 1, 'receiverType' => 'user', 'receiver' => 'bob', 'permissionRead' => true, 'permissionCreate' => false, 'permissionUpdate' => false, 'permissionDelete' => false, 'permissionManage' => false, 'token' => 'never-output', 'password' => 'secret']; }
	/** @return void Identical shares are no-op and contain only the public whitelist. */
	public function testIdenticalIsNoopAndTokensNeverEscape(): void {
		$result = (new ShareChanges())->of('share_node', $this->prepared(), [$this->share()]);
		$this->assertTrue($result['noop']);
		$this->assertStringNotContainsString('never-output', json_encode($result));
		$this->assertStringNotContainsString('secret', json_encode(SchemaSnapshot::share($this->share())));
	}
	/** @return void Different permissions cannot be upgraded implicitly. */
	public function testDifferentPermissionsRequireSeparateRevocation(): void {
		$p = $this->prepared(); $p['arguments']['permissions']['manage'] = true;
		$this->expectException(ToolFailure::class); (new ShareChanges())->of('share_node', $p, [$this->share()]);
	}
	/** @return void A share from another node is never an authorized share ID. */
	public function testForeignShareNodeIsNotFound(): void {
		$share = $this->share(); $share['nodeId'] = 2;
		$this->expectExceptionMessage(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound());
		(new ShareChanges())->of('unshare_node', ['arguments' => ['node_type' => 'table', 'node_id' => 1, 'share_id' => 8]], [$share]);
	}
	/** @return void Public/context shares cannot be revoked by guessing an ID. */
	public function testPublicShareCannotBeRevoked(): void {
		$share = $this->share(); $share['receiverType'] = 'link';
		$this->expectExceptionMessage(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound());
		(new ShareChanges())->of('unshare_node', ['arguments' => ['node_type' => 'table', 'node_id' => 1, 'share_id' => 8]], [$share]);
	}
}
