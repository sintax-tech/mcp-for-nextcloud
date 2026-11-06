<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\Contract\Oracle\DiscoveryHarness;
use OCA\Mcp\Tests\Unit\Contract\Oracle\DiscoveryScenario;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use OCA\Mcp\Tests\Unit\InstalledApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oracle of discovery (RE-01): what a client learns about the server before calling anything, frozen byte for byte
 * against `golden/` — tools/list, prompts/list, resources/list and resources/templates/list under every scenario of
 * {@see DiscoveryScenario}, the mcp_guide text of each, initialize, the grant catalog and the routes.
 *
 * A difference fails with a line diff. `MCP_GOLDEN_UPDATE=1` rewrites the files; that is only for a change that was
 * approved as a behaviour change, never to make a restructuring ticket pass.
 */
final class GoldenContractTest extends TestCase {
	/** Directory of the discovery golden files. */
	private const DIR = __DIR__ . '/golden/';
	/** Placeholder of the version of appinfo/info.xml, which only a release changes. */
	private const VERSION_PLACEHOLDER = '<info.xml version>';

	/** @return iterable<string, array{DiscoveryScenario}> every discovery scenario of the ticket */
	public static function scenarios(): iterable {
		foreach (DiscoveryScenario::all() as $scenario) {
			yield $scenario->name => [$scenario];
		}
	}

	/**
	 * tools/list, prompts/list, resources/list and resources/templates/list of one scenario, in one golden.
	 *
	 * @param DiscoveryScenario $scenario the account/installation state
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testDiscoveryOfEveryScenarioIsUnchanged(DiscoveryScenario $scenario): void {
		$harness = new DiscoveryHarness($this, $scenario);
		$this->assertGolden('discovery.' . $scenario->name, [
			'tools/list' => $harness->request('tools/list'),
			'prompts/list' => $harness->request('prompts/list'),
			'resources/list' => $harness->request('resources/list'),
			'resources/templates/list' => $harness->request('resources/templates/list'),
		]);
	}

	/**
	 * The guide the model reads (mcp_guide without arguments) of one scenario.
	 *
	 * @param DiscoveryScenario $scenario the account/installation state
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testGuideOfEveryScenarioIsUnchanged(DiscoveryScenario $scenario): void {
		$this->assertGolden('guide.' . $scenario->name, (new DiscoveryHarness($this, $scenario))->guide());
	}

	/** @return void initialize of the legacy era (capabilities, instructions, server identity) */
	public function testInitializeIsUnchanged(): void {
		$this->assertGolden('initialize', (new DiscoveryHarness($this, DiscoveryScenario::allOn()))->initialize());
	}

	/** @return void the grant catalog, its order and the reads that start denied */
	public function testGrantCatalogIsUnchanged(): void {
		$this->assertGolden('grant-catalog', ['CATALOG' => \OCA\Mcp\Access\Grants\GrantCatalog::CATALOG, 'DENIED_READS' => GrantPolicy::DENIED_READS]);
	}

	/** @return void every route of appinfo/routes.php with the controller method the router derives from its name */
	public function testRoutesAreUnchanged(): void {
		$declared = require __DIR__ . '/../../../appinfo/routes.php';
		$routes = [];
		foreach ($declared as $kind => $list) {
			foreach ($list as $route) {
				[$controller, $action] = explode('#', $route['name'], 2) + [1 => ''];
				$routes[$kind][] = $route + ['resolves' => self::camel(ucfirst($controller)) . 'Controller::' . self::camel($action)];
			}
		}
		$this->assertGolden('routes', $routes);
	}

	/**
	 * The scenarios differ where the ticket says they must; the golden alone would freeze a wrong scenario too.
	 *
	 * @return void
	 */
	public function testScenariosShowTheirOwnDifference(): void {
		$names = static fn (DiscoveryScenario $scenario, TestCase $test): array => array_column(
			(new DiscoveryHarness($test, $scenario))->request('tools/list')['body']->result->tools, 'name');
		$all = $names(DiscoveryScenario::allOn(), $this);
		$this->assertContains('deck_add_card_dependency', $all);
		$this->assertNotContains('deck_add_card_dependency', $names(DiscoveryScenario::deckWithoutDependencies(), $this));
		$this->assertSame(['mcp_guide', 'files_share', 'files_unshare'], array_values(array_intersect(
			$names(DiscoveryScenario::filesLinkOnly(), $this), ['mcp_guide', 'files_share', 'files_unshare', 'files_list'])));
		$unknown = $names(DiscoveryScenario::unknownGrant(), $this);
		$this->assertNotContains('notes_create', $unknown);
		$this->assertNotContains('files_share', $unknown);
		$this->assertContains('notes_edit', $unknown);
		$guest = $names(DiscoveryScenario::guest(), $this);
		$this->assertContains('talk_reply', $guest);
		$this->assertNotContains('notes_list', $guest);
		$this->assertNotContains('notes_list', $names(DiscoveryScenario::optionalAppsOff(), $this));
	}

	/**
	 * @param string $name file name without extension
	 * @param mixed $value observed value
	 * @return void
	 */
	private function assertGolden(string $name, mixed $value): void {
		GoldenFile::assertMatches($this, self::DIR . $name . '.json',
			VolatileFields::normalize($value, [InstalledApp::version() => self::VERSION_PLACEHOLDER]));
	}

	/**
	 * OC\Route\RouteParser's camel-casing of a controller or action name (`_x` becomes `X`).
	 *
	 * @param string $name name as written in routes.php
	 * @return string the camel-cased name
	 */
	private static function camel(string $name): string {
		return (string)preg_replace_callback('/_[a-z]?/', static fn (array $m): string => strtoupper(ltrim($m[0], '_')), $name);
	}
}
