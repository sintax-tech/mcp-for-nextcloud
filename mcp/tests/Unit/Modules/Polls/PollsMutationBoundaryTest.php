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
use OCA\Mcp\Modules\Polls\Integration\{PollsNativeAdapter,
	PollsNativeFactory,
	PollsMutationSnapshot,
	PollsMutationWriter,
	Polls86Adapter,
	Polls9Adapter,
	PollsSessionGuard,
	PollsServices,
	PollsMutationSurface,
	CreateSurface,
	PollsWriteProjection};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\{IAppConfig, IUser, IUserManager, IUserSession};
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\{DataProvider, RunTestsInSeparateProcesses, PreserveGlobalState};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Actual session/admission/snapshot boundary plus the original pinned AppSettings getter, in isolated processes. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PollsMutationBoundaryTest extends TestCase {
	/**
	 * @return iterable<string,array{string,string,bool,bool}> Native default/group settings per pin.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function policies(): iterable {
		foreach (['8.6.3', '9.0.7', '9.1.4', '9.3.0'] as $v) {
			yield $v . ' absent' => [$v, 'absent', false, true];
			yield $v . ' false no group' => [$v, 'none', false, false];
			yield $v . ' false member' => [$v, 'groups', true, true];
			yield $v . ' false outsider' => [$v, 'groups', false, false];
		}
	}

	/**
	 * @param string $version Source pin.
	 * @param string $setting Native setting case.
	 * @param bool $member Actor group membership.
	 * @param bool $expected Effective native boolean.
	 * @return void
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	#[DataProvider('policies')]
	public function testActualNativePublicPolicyUsesCheckedActor(string $version, string $setting, bool $member, bool $expected): void {
		[$adapter, $reads] = $this->boundary($version, $setting, $member);
		$adapter->open('alice', 'share');
		$s = $adapter->snapshot('alice', ['tool' => 'polls_add_share', 'poll_id' => 1, 'type' => 'public']);
		self::assertSame($expected, $s['publicAllowed']);
		self::assertSame('alice', $s['actor']);
		self::assertSame('', $s['shares'][0]['userId']);
		self::assertStringNotContainsString('SECRET_PUBLIC_CREDENTIAL', json_encode($s));
		self::assertSame(1, $reads->settings);
		$adapter->close();
	}

	/**
	 * @return void A session refusal happens before native settings resolution.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testLoggedOutActorCannotConsultNativePolicy(): void {
		[$adapter, $reads] = $this->boundary('9.3.0', 'groups', true, false);
		$this->expectException(ToolFailure::class);
		try {
			$adapter->open('alice', 'share');
		} finally {
			self::assertSame(0, $reads->settings);
		}
	}

	/**
	 * @return void Poll absence and unreadability share the exact common message; editor denial does not leak without read.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testParentAclNonDisclosure(): void {
		require __DIR__ . '/PollsNativeBoundaryFixture.php';
		foreach ([null, new \OCA\Polls\Db\Poll(false, false), new \OCA\Polls\Db\Poll(true, false)] as $i => $poll) {
			$factory = $this->createMock(PollsNativeFactory::class);
			$factory->method('make')->willReturn(
				new class($poll) {
					/**
					 * @param object|null $poll Native ACL fixture.
					 * @return void
					 */
					public function __construct(private ?object $poll) {
					}
					/**
					 * @param int $id Poll ID.
					 * @return object Native row or native missing error.
					 * @throws \Throwable If validation or a called collaborator fails.
					 */
					public function get(int $id): object {
						if ($this->poll === null) {
							throw new \OCP\AppFramework\Db\DoesNotExistException('secret existence');
						}
						return $this->poll;
					}
				}
			);
			try {
				(new PollsMutationSnapshot($factory))->poll(new \stdClass(), 1);
				self::fail('ACL accepted');
			} catch (ToolFailure $e) {
				self::assertSame($i === 2 ? CommonMessages::forbidden() : CommonMessages::notFound(), $e->getMessage());
			}
		}
	}

	/**
	 * @return void The public userId is never projected even though it equals the native token.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testPublicCredentialAliasIsRedacted(): void {
		require __DIR__ . '/PollsNativeBoundaryFixture.php';
		$row = PollsWriteProjection::share(new \OCA\Polls\Db\Share());
		self::assertSame('', $row['userId']);
		self::assertArrayNotHasKey('token', $row);
		self::assertSame(1800000000, $row['invitationSent']);
	}

	/**
	 * @return void Configuration cannot indirectly invoke the native irreversible anonymity lock.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function testConfigurationRefusesImplicitAnonymityLock(): void {
		require __DIR__ . '/PollsNativeBoundaryFixture.php';
		$factory = $this->createMock(PollsNativeFactory::class);
		$factory->expects(self::once())->method('make')->with(PollsMutationSurface::POLL_MAPPER, self::anything())->willReturn(
			new class {
				/**
				 * @param int $id Native target.
				 * @return object Readable, editable anonymous poll whose native policy would lock it.
				 * @throws \Throwable If validation or a called collaborator fails.
				 */
				public function get(int $id): object {
					return new \OCA\Polls\Db\Poll(true, true, 1, false);
				}
			}
		);
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage('would lock anonymity');
		(new PollsMutationSnapshot($factory))->read(
			new \stdClass(),
			'alice',
			['tool' => 'polls_update_poll', 'poll_id' => 1, 'changes' => ['title' => 'New']],
			'9.3.0'
		);
	}

	/**
	 * @param string $version Pin.
	 * @param string $setting Config case.
	 * @param bool $member Actor membership.
	 * @param bool $logged Endpoint authentication.
	 * @return array{PollsNativeAdapter,object} Real boundary and resolution counter.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	private function boundary(string $version, string $setting, bool $member, bool $logged = true): array {
		require __DIR__ . '/PollsNativeBoundaryFixture.php';
		$records = json_decode(
			file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/polls/polls-mutation.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$r = $records[$version];
		self::assertSame($r['files']['Model/Settings/AppSettings.php'], hash('sha256', $r['settingsSource']));
		$file = tempnam(sys_get_temp_dir(), 'mcp-polls-policy-');
		try {
			file_put_contents($file, $r['settingsSource']);
			require $file;
		} finally {
			unlink($file);
		}
		$native = new \OCA\Polls\UserSession(new \OCA\Polls\Model\UserBase('alice', $member));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueType')->willReturnCallback(
			static fn(string $app,
				string $key): int => $key === 'publicSharesGroups'
				? IAppConfig::VALUE_ARRAY
				: ($setting === 'absent'
				? IAppConfig::VALUE_STRING
				: IAppConfig::VALUE_BOOL)
		);
		$config->method('getValueBool')->willReturn(false);
		$config->method('getValueArray')->willReturn($setting === 'groups' ? ['allowed'] : []);
		$settings = (new ReflectionClass(\OCA\Polls\Model\Settings\AppSettings::class))->newInstanceWithoutConstructor();
		foreach (['appConfig' => $config, 'userSession' => $native, 'logger' => $this->createMock(LoggerInterface::class)] as $key => $value) {
			(new \ReflectionProperty($settings, $key))->setValue($settings, $value);
		}
		$counter = (object) ['settings' => 0];
		$factory = $this->createMock(PollsNativeFactory::class);
		$factory->method('make')->willReturnCallback(
			static function (string $class) use ($settings, $counter): object {
				if ($class === CreateSurface::SETTINGS) {
					$counter->settings++;
					return $settings;
				}
				return new class {
					/**
					 * @param int $id Poll ID.
					 * @return object Native read fixture.
					 * @throws \Throwable If validation or a called collaborator fails.
					 */
					public function get(int $id): object {
						return new \OCA\Polls\Db\Poll();
					}
					/**
					 * @param int $id Poll ID.
					 * @param array $groups Group IDs.
					 * @param bool $deleted Include deleted.
					 * @return array Native public share.
					 * @throws \Throwable If validation or a called collaborator fails.
					 */
					public function findByPoll(int $id, array $groups, bool $deleted): array {
						return [new \OCA\Polls\Db\Share()];
					}
				};
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$endpoint = $this->createMock(IUserSession::class);
		$endpoint->method('isLoggedIn')->willReturn($logged);
		$endpoint->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturnCallback(static fn(string $uid, string $module, string $op): bool => $op === 'share');
		$policy->method('inScope')->willReturn(true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		$apps->method('isEnabledForUser')->willReturn(true);
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn(true);
		$services = $this->createMock(PollsServices::class);
		$services->method('session')->willReturn($native);
		$snapshot = new PollsMutationSnapshot($factory);
		$admission = new PollsWriteAdmission(
			$policy,
			$apps,
			$users,
			$guests,
			PollsDiscoveryProbe::build($this, $version, $version === '8.6.3' ? 32 : ['9.0.7' => 33, '9.1.4' => 34, '9.3.0' => 35][$version])
		);
		return [
			new PollsNativeAdapter(
				$services,
				new PollsSessionGuard($endpoint),
				$admission,
				$snapshot,
				new PollsMutationWriter($factory, $snapshot, new Polls86Adapter(), new Polls9Adapter())
			),
			$counter,
		];
	}
}
