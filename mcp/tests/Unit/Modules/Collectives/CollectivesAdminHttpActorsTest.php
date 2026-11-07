<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Collectives\CollectivesAdminHttpActors;
use OCA\Mcp\Tests\Integration\Collectives\RestoreFailures;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The restoration of the C04 HTTP tests (finding B-R4 of the review): every item is tried on its own, and all that
 * failed are raised together at the end. A failing membership does not keep the next one from being removed, and a
 * failing access cell does not keep the other flags, grants and raw preferences from being restored.
 */
final class CollectivesAdminHttpActorsTest extends TestCase {
	/** @var list<string> what restoration did, in order */
	private array $done = [];

	/**
	 * @param \Closure(string, string, string): void $remove removal of one membership
	 * @param GrantPolicy $policy the access writer
	 * @return CollectivesAdminHttpActors actors enabled on collective 7, with both test memberships absent before
	 */
	private function actors(\Closure $remove, GrantPolicy $policy): CollectivesAdminHttpActors {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturn([]);
		$config->method('deleteUserValue')->willReturnCallback(function (string $uid, string $app, string $key): void {
			$this->done[] = "raw $uid $key";
		});
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$actors = new CollectivesAdminHttpActors($policy, $config, $groups, $users, 'owner', 'outsider',
			static fn (): string => 'team', static fn (): array => [], $remove);
		$this->assertSame([], $actors->enable(7));
		$this->done = [];
		return $actors;
	}

	/** @return GrantPolicy a writer that records what restoration sets */
	private function recordingPolicy(?string $failingEligible = null): GrantPolicy {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('setEligible')->willReturnCallback(function (string $uid, bool $allowed) use ($failingEligible): void {
			if ($uid === $failingEligible && $allowed === false) {
				throw new \RuntimeException("eligible of $uid could not be written");
			}
			$this->done[] = "eligible $uid";
		});
		$policy->method('setConnected')->willReturnCallback(function (string $uid): void {
			$this->done[] = "connected $uid";
		});
		$policy->method('setGrant')->willReturnCallback(function (string $uid, string $module, string $operation): void {
			$this->done[] = "grant $uid $operation";
		});
		return $policy;
	}

	/** @return void the first membership fails, the second is still removed, and the failure is raised at the end */
	public function testAFailingMembershipDoesNotSkipTheNext(): void {
		$actors = $this->actors(function (string $uid, string $team, string $key): void {
			$this->done[] = "remove $key";
			if ($key === '1:outsider') {
				throw new \RuntimeException('Teams refused');
			}
		}, $this->recordingPolicy());
		try {
			$actors->restore();
			$this->fail('the failure was hidden');
		} catch (RestoreFailures $failures) {
			$this->assertSame(['membership 1:outsider'], array_column($failures->failures, 'step'));
		}
		$this->assertSame(['remove 1:outsider', 'remove 2:mcp-it-c04'], array_values(array_filter($this->done, static fn (string $d): bool => str_starts_with($d, 'remove'))));
		$this->assertContains('connected outsider', $this->done, 'access is restored after the failed membership');
	}

	/** @return void the first access cell fails, every other flag, grant and raw preference is still restored */
	public function testAFailingFlagDoesNotSkipTheOthers(): void {
		$actors = $this->actors(function (string $uid, string $team, string $key): void {
			$this->done[] = "remove $key";
		}, $this->recordingPolicy('owner'));
		try {
			$actors->restore();
			$this->fail('the failure was hidden');
		} catch (RestoreFailures $failures) {
			$this->assertSame(['owner eligible'], array_column($failures->failures, 'step'));
		}
		foreach (['connected owner', 'grant owner collective.create', 'grant owner members', 'eligible outsider', 'connected outsider',
			'raw owner eligible', 'raw owner connected', 'raw owner grant_collectives_collective.create', 'raw owner grant_collectives_members',
			'raw outsider eligible', 'raw outsider connected'] as $step) {
			$this->assertContains($step, $this->done, "$step is restored after the failed flag");
		}
	}
}
