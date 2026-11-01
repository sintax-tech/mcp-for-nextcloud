<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools;

use LogicException;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\BuiltTools;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\DuplicateProbeModule;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\FeatureProbe;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\ProbeContainer;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\ProbeModule;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\UnsafeProbeModule;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The CatalogModule of the 1.3.0 contract: the catalog is checked when read (writes are WriteTools, names are
 * unique), a tool is built only when called, and the registry gates it like a legacy tool, by grantAnyOf and by the
 * capabilities it requires.
 */
final class CatalogModuleTest extends TestCase {
	/** @var GrantPolicy grants of alice */
	private GrantPolicy $policy;

	/** Fresh policy with no grant and no tool built. */
	protected function setUp(): void {
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		BuiltTools::$names = [];
	}

	/** A tool whose operation writes and that does not implement WriteTool breaks the catalog. */
	public function testAWriteWithoutWriteToolFails(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('writes (edit) but does not implement');
		UnsafeProbeModule::toolDefinitions();
	}

	/** The same name twice breaks the catalog, also through definitions() of an instance. */
	public function testADuplicateNameFails(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('tool name probe_read is listed twice');
		(new DuplicateProbeModule(new ProbeContainer()))->definitions();
	}

	/** Listing builds nothing; a call or a preview builds only its tool, once. */
	public function testAToolIsBuiltOnlyWhenCalled(): void {
		$container = new ProbeContainer();
		$module = new ProbeModule($container);
		self::assertSame(['probe_read', 'probe_edit', 'probe_share', 'probe_gated'], array_column($module->definitions(), 'name'));
		self::assertSame([], $container->asked);
		self::assertSame([], BuiltTools::$names);

		self::assertSame('probe_read for alice', $module->call('probe_read', [], 'alice')['content'][0]['text']);
		self::assertSame('probe_read for bob', $module->call('probe_read', [], 'bob')['content'][0]['text']);
		self::assertSame(['action' => 'probe_edit', 'user' => 'alice'], $module->preview('probe_edit', [], 'alice'));
		self::assertSame(['probe_read', 'probe_edit'], BuiltTools::$names);
		self::assertCount(2, $container->asked);

		$this->expectException(UnknownToolException::class);
		$module->call('probe_missing', [], 'alice');
	}

	/** The definitions keep the array shape of a legacy module, optional fields only when set. */
	public function testDefinitionsKeepTheLegacyShape(): void {
		$definitions = array_column((new ProbeModule(new ProbeContainer()))->definitions(), null, 'name');
		self::assertSame(['name', 'description', 'inputSchema', 'module', 'operation'], array_keys($definitions['probe_read']));
		self::assertTrue($definitions['probe_edit']['destructiveHint']);
		self::assertSame(['share', 'link'], $definitions['probe_share']['grantAnyOf']);
		self::assertSame(['probe.feature'], $definitions['probe_gated']['requires']);
	}

	/** A tool listing grant alternatives is listed and planned under any of them, and hidden under none. */
	public function testGrantAnyOfIsRespected(): void {
		$this->policy->setGrant('alice', 'files', 'link', true);
		self::assertContains('probe_share', $this->names());
		$plan = $this->registry()->call('probe_share', [], 'alice')['structuredContent'];
		self::assertSame(['requiresConfirmation' => true, 'tool' => 'probe_share'], array_slice($plan, 0, 2));
		self::assertSame('alice', $plan['user']);
		self::assertSame(['probe_share'], BuiltTools::$names);

		$this->policy->setGrant('alice', 'files', 'link', false);
		self::assertNotContains('probe_share', $this->names());
		$this->policy->setGrant('alice', 'files', 'share', true);
		self::assertContains('probe_share', $this->names());
	}

	/** Without a probe for its capability the tool does not exist: hidden and unknown, whatever the grant. */
	public function testRequiresWithoutAProbeHidesTheTool(): void {
		$this->policy->setGrant('alice', 'files', 'read', true);
		self::assertSame(['probe_read'], array_values(array_intersect(['probe_read', 'probe_gated'], $this->names())));
		$this->expectException(UnknownToolException::class);
		$this->registry()->call('probe_gated', [], 'alice');
	}

	/** With a probe that holds the tool is listed and callable; a probe that throws hides it again. */
	public function testAProbeDecidesAndAThrowingProbeCountsAsFalse(): void {
		$this->policy->setGrant('alice', 'files', 'read', true);
		self::assertContains('probe_gated', $this->names(new FeatureProbe(true)));
		self::assertSame('probe_gated for alice', $this->registry(new FeatureProbe(true))->call('probe_gated', [], 'alice')['content'][0]['text']);
		self::assertNotContains('probe_gated', $this->names(new FeatureProbe(false)));
		self::assertNotContains('probe_gated', $this->names(new FeatureProbe(new \RuntimeException('native shape'))));
		self::assertContains('probe_read', $this->names(new FeatureProbe(new \RuntimeException('native shape'))));
	}

	/**
	 * Names alice may list now.
	 *
	 * @param FeatureProbe ...$probes probes of the installation
	 * @return list<string> tool names, guide excluded
	 */
	private function names(FeatureProbe ...$probes): array {
		return array_column($this->registry(...$probes)->definitions('alice'), 'name');
	}

	/**
	 * A registry over the probe module, with every app enabled.
	 *
	 * @param FeatureProbe ...$probes probes of the installation
	 * @return ToolRegistry the registry
	 */
	private function registry(FeatureProbe ...$probes): ToolRegistry {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$logger = $this->createMock(LoggerInterface::class);
		return new ToolRegistry([new ProbeModule(new ProbeContainer())], $this->policy, $apps, $users, $logger, null,
			new CapabilityRegistry($probes, $logger));
	}
}
