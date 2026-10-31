<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Circles\Model\Member;
use OCA\Deck\Service\CirclesService;
use OCA\Mcp\Tools\Deck\DeckRecipientDirectory;
use OCA\Mcp\Tools\Deck\DeckServiceContext;
use OCA\Mcp\Tools\Deck\RecipientExpansion;
use OCA\Mcp\Tools\Deck\RecipientResolutionException;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';
require_once __DIR__ . '/Stubs/recipient_circle.php';

/**
 * Strict native directory completeness and circle container/person distinctions.
 */
final class DeckRecipientDirectoryTest extends TestCase {
	/**
	 * Current inherited native member-shaped rows.
	 *
	 * @var array
	 */
	private array $members = [];
	/**
	 * Resolved native users by stable UID.
	 *
	 * @var array
	 */
	private array $users = [];
	/**
	 * Resolved native groups by native group identity.
	 *
	 * @var array
	 */
	private array $groups = [];
	/**
	 * Root circle probe identities observed in this operation.
	 *
	 * @var array
	 */
	private array $circleLookups = [];
	/**
	 * Whether the optional Circles app is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled = true;
	/**
	 * Whether the native root circle probe succeeds.
	 *
	 * @var bool
	 */
	private bool $circleExists = true;
	/**
	 * Whether the native root circle origin is local.
	 *
	 * @var bool
	 */
	private bool $circleLocal = true;
	/**
	 * Real native directory adapter under test.
	 *
	 * @var DeckRecipientDirectory
	 */
	private DeckRecipientDirectory $directory;

	/**
	 * Supply the real adapter with controlled native boundaries, including lazy Deck service lookup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn ($uid): ?IUser => $this->users[$uid] ?? null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn ($gid): ?IGroup => $this->groups[$gid] ?? null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('circles')->willReturnCallback(fn (): bool => $this->enabled);
		$circle = new class($this->members(...), fn (): bool => $this->circleLocal) {
			/**
			 * Bind fresh inherited membership supplied by the test.
			 *
			 * @param \Closure $members Current raw native member entries.
			 * @param \Closure $local Native circle origin.
			 * @return void
			 */
			public function __construct(private \Closure $members, private \Closure $local) {}

			/**
			 * Return native circle origin without contacting a remote instance.
			 *
			 * @return bool Whether the circle is local.
			 */
			public function isLocal(): bool { return ($this->local)(); }

			/**
			 * Return the current native inherited entries, including intermediate containers.
			 *
			 * @param bool $detailed Whether native container identities must be joined.
			 * @return array<object> Raw native member-shaped objects.
			 */
			public function getInheritedMembers(bool $detailed = false): array {
				Assert::assertTrue($detailed, 'Container identity must come from the native detailed relation');
				return ($this->members)();
			}
		};
		$circles = new class($circle, fn (): bool => $this->enabled, function (string $id): bool {
			$this->circleLookups[] = $id;
			return $this->circleExists && in_array($id, ['circle', 'child-single'], true);
		}) {
			/**
			 * Bind controlled native Circles availability and lookup.
			 *
			 * @param object $circle Native circle-shaped object.
			 * @param \Closure $enabled Current native app availability.
			 * @param \Closure $exists Current native circle existence.
			 * @return void
			 */
			public function __construct(private object $circle, private \Closure $enabled, private \Closure $exists) {}

			/**
			 * Reflect current native Circles availability.
			 *
			 * @return bool Whether the optional app is usable.
			 */
			public function isCirclesEnabled(): bool { return ($this->enabled)(); }

			/**
			 * Resolve an existing circle or its absence.
			 *
			 * @param string $id Requested native circle identity.
			 * @return object|null Native circle, or null when unresolved.
			 */
			public function getCircle(string $id): ?object { return ($this->exists)($id) ? $this->circle : null; }
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(CirclesService::class)->willReturn($circles);
		$this->directory = new DeckRecipientDirectory(new DeckServiceContext($container), $users, $groups, $apps);
	}

	/**
	 * Return the current raw members for the native circle boundary.
	 *
	 * @return array<object> Controlled native member-shaped entries.
	 */
	private function members(): array { return $this->members; }

	/**
	 * Model the native Member API without inventing application recipient logic.
	 *
	 * @param string $uid Native person/container identity.
	 * @param int $type Native Member type.
	 * @param int $level Native membership level.
	 * @param bool $local Native member origin classification.
	 * @param string|null $singleId Stable native container identity, independent of its display name.
	 * @param object|null $basedOn Detailed native container relation or its absence.
	 * @return object Native member-shaped object.
	 */
	private function member(string $uid, int $type = Member::TYPE_USER, int $level = Member::LEVEL_MEMBER, bool $local = true, ?string $singleId = null, ?object $basedOn = null): object {
		return new class($uid, $type, $level, $local, $singleId ?? $uid, $basedOn) {
			/**
			 * Store exactly the fields read from the native Member.
			 *
			 * @param string $uid Native identity.
			 * @param int $type Native type.
			 * @param int $level Native level.
			 * @param bool $local Native member origin.
			 * @param string $singleId Native stable container identity.
			 * @param object|null $basedOn Detailed native container relation.
			 * @return void
			 */
			public function __construct(private string $uid, private int $type, private int $level, private bool $local, private string $singleId, private ?object $basedOn) {}
			/**
			 * Return native identity.
			 *
			 * @return string Stable identity.
			 */
			public function getUserId(): string { return $this->uid; }
			/**
			 * Return stable container identity, which is never inferred from the display name.
			 *
			 * @return string Native single ID.
			 */
			public function getSingleId(): string { return $this->singleId; }
			/**
			 * Return the native detailed container relation independently of the public circle probe.
			 *
			 * @return object|null Joined native container or its absence.
			 */
			public function getBasedOn(): ?object { return $this->basedOn; }
			/**
			 * Return native type.
			 *
			 * @return int Native type code.
			 */
			public function getUserType(): int { return $this->type; }
			/**
			 * Return native membership level.
			 *
			 * @return int Membership level.
			 */
			public function getLevel(): int { return $this->level; }
			/**
			 * Return native origin classification.
			 *
			 * @return bool Whether the identity is local.
			 */
			public function isLocal(): bool { return $this->local; }
		};
	}

	/**
	 * Traverse the real adapter with a fresh operation budget.
	 *
	 * @return array<IUser> Complete effective local readers, or an exception.
	 */
	private function readers(): array {
		return iterator_to_array($this->directory->circle('circle', new RecipientExpansion(static fn (): int => 0)));
	}

	/**
	 * Count only effective users while validating structural group/circle identities.
	 *
	 * @return void
	 */
	public function testIntermediateContainersAndInvitationsAreNotPeople(): void {
		$user = $this->createStub(IUser::class);
		$this->users['reader'] = $user;
		$this->groups['group'] = $this->createStub(IGroup::class);
		$this->members = [$this->member('invited', level: Member::LEVEL_NONE),
			$this->member('group', Member::TYPE_GROUP), $this->member('Visible child name', Member::TYPE_CIRCLE, singleId: 'child-single', basedOn: $this->container('child-single')), $this->member('reader')];
		self::assertSame([$user], $this->readers());
		self::assertSame(['circle'], $this->circleLookups, 'Hidden system containers must not use the filtered public probe');
	}

	/**
	 * A non-local effective user must refuse even when a local account has the same UID.
	 *
	 * @return void
	 */
	public function testRemoteEffectiveMemberIsRefusedWhereDeckWouldIgnoreIt(): void {
		$this->users['collision'] = $this->createStub(IUser::class);
		$this->members = [$this->member('collision', local: false)];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * Never omit an unresolved effective user, including a missing guest backend account.
	 *
	 * @return void
	 */
	public function testUnresolvedGuestRecipientClosesExpansion(): void {
		$this->members = [$this->member('unresolved-guest')];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * Do not confuse an unsupported person type with a group/circle container.
	 *
	 * @return void
	 */
	public function testUnsupportedEffectiveCircleIdentityClosesExpansion(): void {
		$this->members = [$this->member('mail', Member::TYPE_MAIL)];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * A circle ACL cannot disappear from the count when its app is disabled.
	 *
	 * @return void
	 */
	public function testCirclesAbsentRefusesWithoutResolvingItsOptionalService(): void {
		$this->enabled = false;
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * A missing circle must not be treated as an empty valid circle.
	 *
	 * @return void
	 */
	public function testMissingCircleClosesExpansion(): void {
		$this->circleExists = false;
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * An unresolved intermediate group is also an incomplete expansion.
	 *
	 * @return void
	 */
	public function testMissingIntermediateGroupClosesExpansion(): void {
		$this->members = [$this->member('missing-group', Member::TYPE_GROUP)];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * Refuse a non-local root circle instead of treating its unexpanded local cache as complete.
	 *
	 * @return void
	 */
	public function testRemoteCircleCannotBecomeAnEmptyLocalExpansion(): void {
		$this->circleLocal = false;
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * A missing native direct user never becomes an empty recipient set.
	 *
	 * @return void
	 */
	public function testMissingDirectUserCloses(): void {
		$this->expectException(RecipientResolutionException::class);
		$this->directory->user('missing');
	}

	/**
	 * A missing native group never becomes an empty recipient set.
	 *
	 * @return void
	 */
	public function testMissingGroupCloses(): void {
		$this->expectException(RecipientResolutionException::class);
		iterator_to_array($this->directory->group('missing', new RecipientExpansion(static fn (): int => 0)));
	}

	/**
	 * Model the current native container joined by the detailed inherited-members query.
	 *
	 * @param string $id Stable native single ID.
	 * @return object Native circle-shaped identity.
	 */
	private function container(string $id): object {
		return new class($id) {
			/**
			 * Store the joined identity separately from the member's display name.
			 *
			 * @param string $id Stable single ID.
			 * @return void
			 */
			public function __construct(private string $id) {}

			/**
			 * Return stable native identity.
			 *
			 * @return string Single ID.
			 */
			public function getSingleId(): string { return $this->id; }

			/**
			 * Model the unloaded owner on the native detailed relation.
			 *
			 * @return bool Never available without loading its owner.
			 */
			public function isLocal(): bool { throw new \RuntimeException('circle has no owner'); }
		};
	}

	/**
	 * An unresolved native container cannot silently disappear from the expansion.
	 *
	 * @return void
	 */
	public function testMissingDetailedContainerCloses(): void {
		$this->members = [$this->member('name', Member::TYPE_CIRCLE, singleId: 'child-single')];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * A joined identity must match the structural member, even if its display name looks plausible.
	 *
	 * @return void
	 */
	public function testMismatchedDetailedContainerCloses(): void {
		$this->members = [$this->member('name', Member::TYPE_CIRCLE, singleId: 'child-single',
			basedOn: $this->container('another'))];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}

	/**
	 * A non-local structural member refuses even when its joined identity exists.
	 *
	 * @return void
	 */
	public function testNonLocalStructuralMemberCloses(): void {
		$this->members = [$this->member('name', Member::TYPE_CIRCLE, local: false, singleId: 'child-single',
			basedOn: $this->container('child-single'))];
		$this->expectException(RecipientResolutionException::class);
		$this->readers();
	}
	/**
	 * Refuse a known oversized group before allocating its native member list.
	 *
	 * @return void
	 */
	public function testOversizedGroupCountNeverLoadsUsers(): void {
		$group = $this->createMock(IGroup::class);
		$group->expects(self::once())->method('count')->with('')->willReturn(RecipientExpansion::MAX_RECIPIENTS + 1);
		$group->expects(self::never())->method('getUsers');
		$this->groups['huge'] = $group;
		$this->expectException(RecipientResolutionException::class);
		iterator_to_array($this->directory->group('huge', new RecipientExpansion(static fn (): int => 0)));
	}

	/**
	 * A false, zero, low or boundary estimate cannot replace post-load accounting.
	 *
	 * @param int|bool $count Native backend estimate.
	 * @return void
	 */
	#[DataProvider('groupCounts')]
	public function testUnreliableOrBoundaryGroupCountStillLoadsAndAccounts(int|bool $count): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('reader');
		$user->method('getDisplayName')->willReturn('Reader');
		$this->users['reader'] = $user;
		$group = $this->createMock(IGroup::class);
		$group->expects(self::once())->method('count')->with('')->willReturn($count);
		$group->expects(self::once())->method('getUsers')->willReturn([$user, $user]);
		$this->groups['estimated'] = $group;
		$budget = new RecipientExpansion(static fn (): int => 0);
		foreach ($this->directory->group('estimated', $budget) as $member) {
			$budget->add($member);
		}
		self::assertSame([['uid' => 'reader', 'name' => 'Reader']], $budget->members());
	}

	/**
	 * Keep native unsupported estimates and the exact upper boundary explicit.
	 *
	 * @return iterable<string,array{int|bool}> Count estimates that must not refuse by themselves.
	 */
	public static function groupCounts(): iterable {
		yield 'unsupported' => [false];
		yield 'zero' => [0];
		yield 'underestimated' => [1];
		yield 'at limit' => [RecipientExpansion::MAX_RECIPIENTS];
	}

}
