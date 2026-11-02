<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Notes module of one request for the Notes effect contracts, wired as the container autowires it (the real
 * VisibilityGuard over a system-tag double, the shared-write guard, the lock check, the plan states and the file-type
 * policy) over a persistent {@see FakeTree}. The trash bin is enabled for alice; whether a storage takes a deleted
 * note back is the `trash` flag of the node in the tree.
 */
final class NotesWritesWiring {
	/** Id of the system tag the administrator hides from MCP. */
	public const HIDDEN_TAG = '999';

	/** Static builder only. */
	private function __construct() {
	}

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param FakeTree $tree the files of alice, persistent across requests
	 * @param EffectsGate $gate the endpoint, whose configuration holds the hidden tag and the notes path
	 * @param \Closure(): list<int> $hidden node ids that carry the hidden tag at this moment
	 * @param FakeLockManager|null $locks the provider whose locks persist across requests
	 * @return NotesModule the module of one request
	 */
	public static function module(TestCase $test, FakeTree $tree, EffectsGate $gate, \Closure $hidden, ?FakeLockManager $locks = null): NotesModule {
		$tags = self::mock($test, ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => array_combine(
			array_map('strval', $ids),
			array_map(static fn ($id): array => in_array((int)$id, $hidden(), true) ? [self::HIDDEN_TAG] : [], $ids),
		));
		$guard = new VisibilityGuard($gate->config(), $tags);
		$root = self::mock($test, IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(static fn () => $tree->rootFolder());
		$apps = self::mock($test, IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = self::mock($test, IUserManager::class);
		$users->method('get')->willReturn(self::mock($test, IUser::class));
		$access = new NodeAccessInfo(FakeUsers::manager($test, FakeUsers::DEFAULTS), $tree->shareManager());
		return new NotesModule(new NotesRepository($root, $gate->config(), $guard), $apps, $users,
			new SharedWriteGuard($access), $access, $guard, ($locks ?? new FakeLockManager())->service($test),
			new PlanState($gate->config()), FileTypePolicyFixture::off($test));
	}

	/**
	 * @param TestCase $test the running test
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private static function mock(TestCase $test, string $class): MockObject {
		return (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
	}
}
