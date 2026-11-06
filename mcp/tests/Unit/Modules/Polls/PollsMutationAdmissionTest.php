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

/** The five new grants do not require or imply any protected/general read grant. */
final class PollsMutationAdmissionTest extends TestCase {
	/**
	 * @return iterable<string,array{string}> Literal grants, including the dotted operation.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function grants(): iterable {
		foreach (['configure', 'options', 'options.remove', 'share', 'invite'] as $op) {
			yield $op => [$op];
		}
	}

	/**
	 * @param string $op Exact independently granted operation.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('grants')]
	public function testOneWriteGrantIsEnoughWithoutReadResultsOrLinks(string $op): void {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->expects(self::once())->method('granted')->with('alice', 'polls', $op)->willReturn(true);
		$policy->method('inScope')->willReturn(true);
		[$apps, $users, $guests] = $this->sources();
		$admission = new PollsWriteAdmission($policy, $apps, $users, $guests, PollsDiscoveryProbe::build($this));
		self::assertSame('9.3.0', $admission->check('alice', $op, 1));
	}

	/**
	 * @return void Revoked grant does not resolve compatibility or native services.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testDeniedGrantStopsBeforeNativeIntrospection(): void {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturn(false);
		$policy->method('inScope')->willReturn(true);
		[$apps, $users, $guests] = $this->sources();
		$this->expectException(ToolFailure::class);
		(new PollsWriteAdmission($policy, $apps, $users, $guests, PollsDiscoveryProbe::build($this)))->check('alice', 'share', 1);
	}

	/**
	 * @return array{IAppManager,IUserManager,GuestRestrictions} Real interface mocks at the admission boundary.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	private function sources(): array {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('9.3.0');
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn(true);
		return [$apps, $users, $guests];
	}
}
