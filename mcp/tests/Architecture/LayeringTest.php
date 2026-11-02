<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Dependencies\{BoundaryLedger, BoundaryRules};
use OCA\Mcp\Tests\Architecture\Inventory\TicketState;
use RuntimeException;

/** Contract matrix including the narrowly authorized Access/Storage edges. */
final class LayeringTest extends ArchitectureCase {
	public function testLiveLayeringExternalPrefixesAndMapCoverage(): void {
		foreach (['layering', 'unresolved-class', 'external-prefix'] as $rule) { $this->assertBoundary($rule); }
		self::assertGreaterThan(700, count(self::tree()->classes));
		foreach ([\OCA\Mcp\AppInfo\Application::class, \OCA\Mcp\Platform\FileAccess\NodeAccess::class, \OCA\Mcp\OAuth\Storage\OAuthStore::class, \OCA\Mcp\Mcp\Resources\ResourceRegistry::class, ...\OCA\Mcp\AppInfo\Application::MODULES] as $class) { self::assertArrayHasKey($class, self::tree()->classes); }
	}
	public function testForbiddenEdgesFailByAliasLiteralAndClassConstant(): void {
		foreach ([
			['lib/Access/X.php', 'namespace OCA~Mcp~Access; use OCA~Mcp~OAuth~Tokens~TokenService as Tokens; class X { public Tokens $service; }'],
			['lib/Access/X.php', 'namespace OCA~Mcp~Access; $x = "OCA~~Mcp~~OAuth~~Tokens~~TokenService";'],
			['lib/Platform/X.php', 'namespace OCA~Mcp~Platform; $x = \\OCA~Mcp~Access~Grants~GrantPolicy::class;'],
			['lib/Mcp/X.php', 'namespace OCA~Mcp~Mcp; use OCA~Mcp~Tools~Notes~NotesModule;'],
			['lib/Modules/Notes/X.php', 'namespace OCA~Mcp~Modules~Notes; use OCA~Mcp~OAuth~TokenService;'],
		] as [$path, $code]) { self::assertNotSame([], self::fixture($path, $code), $code); }
	}
	public function testAllowedEdgesAndPreservedRootsPass(): void {
		foreach ([
			['lib/Access/Policy/PolicyEventProcessor.php', 'use OCA~Mcp~OAuth~Storage~OAuthStore;'],
			['lib/Access/Policy/Credentials/Revoke.php', 'use OCA~Mcp~OAuth~Storage~OAuthStore;'],
			['lib/OAuth/Storage/X.php', 'use OCA~Mcp~Platform~Numbers~DecimalCounter;'],
			['lib/Platform/X.php', 'use OCA~Mcp~Mcp~Tools~Results~ToolFailure; use OCA~Mcp~Mcp~Tools~Validation~ArgumentValidationException;'],
			['lib/Db/X.php', 'use OCA~Mcp~Access~Grants~GrantPolicy;'],
			['lib/Controller/X.php', 'use OCA~Mcp~Tools~Files~FilesModule;'],
			['lib/Access/X.php', 'use Smalot\\PdfParser\\Parser; use Symfony\\Component\\Console\\Command\\Command;'],
		] as [$path, $code]) { self::assertSame([], self::fixture($path, $code), $code); }
		self::assertNotSame([], self::fixture('lib/Access/X.php', 'use OCA~Mcp~OAuth~Storage~TokenHasher;'));
		self::assertNotSame([], self::fixture('lib/Platform/X.php', 'use NewVendor\\Framework\\Service;'));
	}
	public function testLedgerIsExactConsumedAndMonotonic(): void {
		$edge = self::fixture('lib/Mcp/X.php', 'use OCA~Mcp~Tools~Notes~NotesModule;')[0];
		$entry = $edge + ['reason' => 'Replace the legacy edge.', 'expires' => 'RE-13'];
		self::assertSame([], BoundaryLedger::problems([$edge], [$entry], self::tickets()));
		$extra = self::fixture('lib/Mcp/X.php', 'use OCA~Mcp~Tools~Files~FilesModule;')[0];
		self::assertNotSame([], BoundaryLedger::problems([$edge, $extra], [$entry], self::tickets()));
		self::assertNotSame([], BoundaryLedger::problems([], [$entry], self::tickets()));
		foreach ([['expires' => 'RE-05b'], ['expires' => 'RE-999'], ['source' => 'lib/*'], ['target' => 'OCA~Mcp~Tools\\*']] as $change) {
			try { BoundaryLedger::problems([$edge], [array_replace($entry, $change)], self::tickets()); self::fail('Invalid exception accepted'); }
			catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
		}
		try { BoundaryLedger::problems([$edge], [$entry, $entry], self::tickets()); self::fail('Duplicate accepted'); }
		catch (RuntimeException $error) { self::assertStringContainsString('Duplicate', $error->getMessage()); }
		$this->expectException(RuntimeException::class);
		new TicketState(self::tickets()->known, array_values(array_diff(self::tickets()->completed, ['RE-05b'])));
	}
}
