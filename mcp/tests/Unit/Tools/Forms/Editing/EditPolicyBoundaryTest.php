<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\{PolicyOperationContext,PolicyResolver,PolicyWriter};
use OCA\Mcp\Service\Scope\ScopeGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Forms\Editing\EditPolicyBoundary;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Separates per-stage admission from the still-open endpoint snapshot and closes only owned contexts. */
final class EditPolicyBoundaryTest extends TestCase {
	/** Observes externally changed resolution with the outer snapshot deliberately held stale and alive.
	 * @return void
	 */
	public function testRenewedReadDoesNotJoinOrCloseTheOuterOperation(): void {
		$allowed = '1'; $contexts = []; $observeFlags = [];
		$resolver = $this->createMock(PolicyResolver::class);
		$resolver->method('begin')->willReturnCallback(function (string $uid, array $added = [], array $removed = [], bool $observe = true) use (&$allowed, &$contexts, &$observeFlags): PolicyOperationContext {
			$observeFlags[] = $observe;
			return $contexts[] = $this->context($allowed);
		});
		$config = (new InMemoryConfig())->mock($this);
		$writer = $this->createMock(PolicyWriter::class);
		$policy = new GrantPolicy($config, $this->createMock(\OCP\Config\IUserConfig::class), $writer, $resolver);
		$boundary = new EditPolicyBoundary($resolver);
		$policy->within('alice', function () use ($policy, $boundary, &$allowed, &$contexts): void {
			self::assertTrue($policy->granted('alice', 'forms', 'edit'));
			$boundary->assertAllowed('alice');
			// A changed resolver read, not a call through this GrantPolicy's invalidating writer.
			$allowed = '0';
			try { $boundary->assertAllowed('alice'); self::fail('Renewed denial ignored'); }
			catch (ToolFailure) { self::assertTrue($policy->granted('alice', 'forms', 'edit')); }
			self::assertTrue($contexts[0]->serviceEnabled(), 'Outer operation must remain open');
			$allowed = 'malformed';
			try { $boundary->assertAllowed('alice'); self::fail('Unknown grant admitted'); }
			catch (ToolFailure) { self::assertTrue($contexts[0]->serviceEnabled()); }
		});
		self::assertSame([true, false, false, false], $observeFlags);
		foreach ($contexts as $context) {
			try { $context->serviceEnabled(); self::fail('Context remained open'); }
			catch (\OCA\Mcp\Service\Policy\PolicyUnavailable) { self::assertTrue(true); }
		}
	}
	/** Uses real effective gates, including deny/unknown semantics and a deliberately unrestricted Forms scope.
	 * @param string $grant Synthetic raw value at this resolver boundary.
	 * @return PolicyOperationContext Real policy context over explicit fixture values.
	 */
	private function context(string $grant): PolicyOperationContext {
		$raw = [GrantPolicy::ELIGIBLE_KEY => ['present' => true, 'value' => '1'], GrantPolicy::CONNECTED_KEY => ['present' => true, 'value' => '1'],
			'grant_forms_edit' => ['present' => true, 'value' => $grant]];
		$scope = ScopeGuard::read($this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class), null);
		return new PolicyOperationContext('fixture-revision', $this->createMock(PolicyWriter::class), $raw, [], true, true, ['forms' => true], false, false, $scope);
	}
}
