<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Service\GuestRestrictions;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\{IUser, IUserManager};
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Exact protected-read registry visibility, independently from direct-handler operation checks. */
final class PollsContentRegistryTest extends TestCase {
	/** @return iterable<string,array{bool,bool,bool,string,list<string>}> grants, guest permission, app version, expected names */
	public static function scenarios(): iterable {
		yield 'default deny protected reads' => [false, false, true, '9.3.0', []];
		yield 'results independently' => [true, false, true, '9.3.0', ['polls_list_votes', 'polls_read_results']];
		yield 'links independently' => [false, true, true, '9.3.0', ['polls_list_links']];
		yield 'both protected reads' => [true, true, true, '9.3.0', ['polls_list_votes', 'polls_read_results', 'polls_list_links']];
		yield 'guest restriction blocks protected reads' => [true, true, false, '9.3.0', []];
		yield 'old app blocks protected reads' => [true, true, true, '8.6.2', []];
	}

	/**
	 * Read-only annotations do not turn into an implicit read grant, while Guests/version still gate every tool.
	 * @param bool $results exact results grant
	 * @param bool $links exact links grant
	 * @param bool $guest Guests permits native app
	 * @param string $version installed app
	 * @param list<string> $expected visible protected tools
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testExactGrantsAndSharedGates(bool $results, bool $links, bool $guest, string $version, array $expected): void {
		$rig = new PollsTestRig($this);
		$rig->policy->setGrant('alice', 'polls', 'read', false);
		$rig->policy->setGrant('alice', 'polls', 'results', $results);
		$rig->policy->setGrant('alice', 'polls', 'links', $links);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('getAppVersion')->willReturn($version);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn($guest);
		$registry = new ToolRegistry([$rig->module], $rig->policy, $apps, $users, $this->createMock(LoggerInterface::class), $guests);
		$tools = $registry->list('alice');
		$this->assertSame(['mcp_guide', ...$expected], array_column($tools, 'name'));
		foreach ($tools as $tool) {
			$this->assertTrue($tool['annotations']['readOnlyHint']);
			$this->assertFalse($tool['annotations']['destructiveHint']);
			$this->assertArrayNotHasKey('confirm', $tool['inputSchema']['properties']);
		}
	}
}
