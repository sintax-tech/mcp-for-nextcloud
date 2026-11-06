<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Modules\Polls\Admission\PollsWriteAdmission;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\{IUser, IUserManager};
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Direct admission must retain every guard independently of registry discovery. */
final class PollsMutationAdmissionGuardsTest extends TestCase {
	/**
	 * @return iterable<string,array{string}> Each independent guard.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function guards(): iterable {
		foreach (['scope', 'guest', 'app-user', 'unknown-op'] as $guard) {
			yield $guard => [$guard];
		}
	}

	/**
	 * @param string $guard Only this guard denies the otherwise valid actor.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('guards')]
	public function testDirectAdmissionCannotSkipOneGuard(string $guard): void {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturn(true);
		$policy->method('inScope')->willReturn($guard !== 'scope');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($guard !== 'app-user');
		$apps->method('getAppVersion')->willReturn('9.3.0');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn($guard !== 'guest');
		$this->expectException(ToolFailure::class);
		(new PollsWriteAdmission($policy,
			$apps,
			$users,
			$guests,
			PollsDiscoveryProbe::build($this)))->check('alice',
			$guard === 'unknown-op'
			? 'unreviewed'
			: 'share',
			1);
	}
}
