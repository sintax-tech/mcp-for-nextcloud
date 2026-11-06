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
use OCA\Mcp\Modules\Polls\Integration\{PollsNativeAdapter, PollsNativeFactory, PollsMutationSnapshot, PollsMutationWriter, Polls86Adapter, Polls9Adapter, PollsSessionGuard, PollsServices, PollsMutationSurface};
use OCP\{IUser, IUserManager, IUserSession};
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Actual native adapter with controllable ACL, scope and row races; no flow port substitution. */
final class PollsMutationGuardRig {
	/** Native row returned by the mapper. */
	public object $poll;
	/** Native session whose cleanup is observable. */
	public object $native;
	/** Endpoint authentication, revocable while a mapper reads. */
	public bool $logged = true;
	/** Resource scope, revocable while a mapper reads. */
	public bool $scope = true;
	/** Optional race at the actual poll mapper boundary. */
	public ?\Closure $onRead = null;
	/** Child rows returned by the actual snapshot's mapper. */
	public array $children = [];
	/** Actual native sink count. */
	public int $sinks = 0;
	/** Actual adapter under test. */
	public PollsNativeAdapter $adapter;
	/** Actual snapshot reader under test. */
	public PollsMutationSnapshot $snapshots;
	/**
	 * @param TestCase $test Owner of interface mocks; fixture is loaded in an isolated process.
	 * @return void
	 */
	public function __construct(TestCase $test) {
		require_once __DIR__ . '/PollsNativeBoundaryFixture.php';
		$mock = static fn (string $c): object => (new ReflectionMethod($test, 'createMock'))->invoke($test, $c);
		$this->poll = new \OCA\Polls\Db\Poll();
		$this->native = new \OCA\Polls\UserSession(new \OCA\Polls\Model\UserBase());
		$user = $mock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$endpoint = $mock(IUserSession::class);
		$endpoint->method('getUser')->willReturn($user);
		$endpoint->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->logged);
		$users = $mock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$apps = $mock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('9.3.0');
		$apps->method('isEnabledForUser')->willReturn(true);
		$policy = $mock(GrantPolicy::class);
		$policy->method('granted')->willReturn(true);
		$policy->method('inScope')->willReturnCallback(fn (): bool => $this->scope);
		$guests = $mock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn(true);
		$admission = new PollsWriteAdmission($policy, $apps, $users, $guests, PollsDiscoveryProbe::build($test));
		$services = $mock(PollsServices::class);
		$services->method('session')->willReturn($this->native);
		$factory = $mock(PollsNativeFactory::class);
		$factory->method('make')->willReturnCallback(function (string $class): object {
			return new class($this) {
				/** @param PollsMutationGuardRig $rig Controllable native state. */
				public function __construct(private PollsMutationGuardRig $rig) {
				}
				/** @param int $id Authorized poll. @return object Native row after the optional concurrent change. */
				public function get(int $id): object {
					if ($this->rig->onRead !== null) {
						($this->rig->onRead)();
					}
					return $this->rig->poll;
				}
				/** @param int $id Poll. @param mixed ...$rest Mapper-specific arguments. @return array Native child rows. */
				public function findByPoll(int $id, mixed ...$rest): array {
					return $this->rig->children;
				}
				/** @param int $id Native target. @return void */
				public function toggleArchive(int $id): void {
					$this->rig->sinks++;
				}
			};
		});
		$this->snapshots = new PollsMutationSnapshot($factory);
		$this->adapter = new PollsNativeAdapter($services, new PollsSessionGuard($endpoint), $admission, $this->snapshots,
			new PollsMutationWriter($factory, $this->snapshots, new Polls86Adapter(), new Polls9Adapter()));
	}
}
