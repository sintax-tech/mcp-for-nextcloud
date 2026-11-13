<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\ToolPresentation;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCA\Mcp\Modules\Routines\RoutinesModule;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A tool that combines several apps (`apps`) exists for a user only while every one of them is enabled for that user
 * and allowed by Guests, in discovery and on a direct call, with the same per-user rule as a single `app`.
 */
final class ToolAppsAccessTest extends TestCase {
	/** @var array<string, bool> enablement of each app for the user */
	private array $enabled = ['spreed' => true, 'calendar' => true];
	private GrantPolicy $policy;

	/** Grants routines.meeting to alice; every other decision comes from {@see self::$enabled}. @return void */
	protected function setUp(): void {
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$this->policy->setGrant('alice', 'routines', 'meeting', true);
	}

	/** @return IAppManager every app at a supported version, enabled for the user as {@see self::$enabled} says */
	private function apps(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('99.0.0');
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $this->enabled[$app] ?? false);
		return $apps;
	}

	/** @return IUserManager alice, an existing account */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		return $users;
	}

	/**
	 * @param GuestRestrictions|null $guests Guests, null without the app
	 * @return ToolAccess the registry's own decision over these doubles
	 */
	private function access(?GuestRestrictions $guests = null): ToolAccess {
		return new ToolAccess($this->policy, $this->apps(), $this->users(), $guests, new CapabilityRegistry([], new NullLogger()));
	}

	/** @return ToolRegistry the real registry serving the routines, whose meeting needs Talk and Calendar */
	private function registry(): ToolRegistry {
		return new ToolRegistry([new RoutinesModule($this->createMock(ContainerInterface::class))], $this->policy, $this->apps(), $this->users(), new NullLogger());
	}

	/** @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, apps:list<string>} a definition that needs Talk and Calendar */
	private static function definition(): array {
		return (new ToolDefinition('routines_create_meeting', 'routines', 'meeting', 'x', ['type' => 'object'], apps: ['spreed', 'calendar']))->toArray();
	}

	/** @return array<string, array{string}> each app of the definition */
	public static function eachApp(): array {
		return ['Talk' => ['spreed'], 'Calendar' => ['calendar']];
	}

	/** Every app of the list enabled for the user lets the tool exist. @return void */
	public function testEveryAppEnabledAllowsTheTool(): void {
		self::assertTrue($this->access()->allowed(self::definition(), 'alice'));
	}

	/**
	 * One app off for the user is enough to hide the tool.
	 *
	 * @param string $app the app switched off
	 * @return void
	 */
	#[DataProvider('eachApp')]
	public function testOneAppOffForTheUserHidesTheTool(string $app): void {
		$this->enabled[$app] = false;

		self::assertFalse($this->access()->allowed(self::definition(), 'alice'));
	}

	/**
	 * Guests must allow every app of the list, not only the first.
	 *
	 * @param string $app the app Guests does not allow
	 * @return void
	 */
	#[DataProvider('eachApp')]
	public function testGuestsMustAllowEveryApp(string $app): void {
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(static fn (string $user, string $asked): bool => $asked !== $app);

		self::assertFalse($this->access($guests)->allowed(self::definition(), 'alice'));
	}

	/** The single app and the list are one set: each app once, the single one first; nothing changes without either. @return void */
	public function testRequiredAppsJoinTheSingleAppAndTheList(): void {
		self::assertSame(['mail', 'deck'], ToolDefinition::requiredApps(['app' => 'mail', 'apps' => ['mail', 'deck']]));
		self::assertSame(['deck'], ToolDefinition::requiredApps(['app' => 'deck']));
		self::assertSame([], ToolDefinition::requiredApps([]));
		self::assertArrayNotHasKey('apps', (new ToolDefinition('a_b', 'files', 'read', 'x', [], 'files'))->toArray(), 'a tool with one app keeps its shape');
	}

	/** The guide names every app, and keeps its sentence for a tool with one. @return void */
	public function testTheGuideNamesEveryApp(): void {
		self::assertSame(' Needs the `deck` app enabled.', ToolPresentation::appsNeeded(['deck']));
		self::assertSame(' Needs the `spreed` and `calendar` apps enabled.', ToolPresentation::appsNeeded(['spreed', 'calendar']));
		self::assertSame('', ToolPresentation::appsNeeded([]));
	}

	/** routines_create_meeting is listed while Talk and Calendar are both enabled for the user. @return void */
	public function testTheMeetingIsListedWithTalkAndCalendar(): void {
		self::assertContains('routines_create_meeting', array_column($this->registry()->list('alice'), 'name'));
	}

	/**
	 * With Calendar or Talk off for the user, routines_create_meeting leaves tools/list and a direct call is an unknown tool.
	 *
	 * @param string $app the app switched off
	 * @return void
	 */
	#[DataProvider('eachApp')]
	public function testTheMeetingDisappearsWhenOneOfItsAppsIsOff(string $app): void {
		$this->enabled[$app] = false;
		$registry = $this->registry();

		self::assertNotContains('routines_create_meeting', array_column($registry->list('alice'), 'name'));
		$this->expectException(UnknownToolException::class);
		$registry->call('routines_create_meeting', ['title' => 'x', 'confirm' => true], 'alice');
	}
}
