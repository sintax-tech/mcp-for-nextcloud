<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\{Acl, Board, Card, CardMapper, Stack, StackMapper};
use OCA\Deck\Service\{BoardService, CardService, PermissionService};
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyChange; use OCA\Mcp\Modules\Deck\Failures\DeckConflictException; use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

final class DependencyWriteGatewayTest extends TestCase {
	use DeckTestHelpers;
	private array $services;
	private DeckServiceGateway $gateway;
	private array $edges = [];
	private array $fields = [];
	private array $hidden = [];
	private bool $missing = false;
	private bool $edit = true;
	private bool $uncertain = false;

	protected function setUp(): void {
		foreach ([CardMapper::class, StackMapper::class, CardService::class, BoardService::class, PermissionService::class, \OCP\IUserManager::class] as $class) {
			$this->services[$class] = $this->createMock($class);
		}
		$this->services[CardMapper::class]->method('find')->willReturnCallback(function ($id): Card {
			if ($this->uncertain && $id === 3) { throw new \RuntimeException('uncertain database read'); }
			return new Card(($this->fields[$id] ?? []) + ['id' => $id, 'stackId' => 10, 'title' => 'Card ' . $id]);
		});
		$this->services[CardMapper::class]->method('findBoardId')->willReturn(20);
		$this->services[CardMapper::class]->method('findDependenciesForCards')->willReturnCallback(
			fn (array $ids): array => [$ids[0] => $this->edges[$ids[0]] ?? []]);
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[BoardService::class]->method('findAll')->willReturn([new Board(['id' => 20, 'owner' => 'alice', 'title' => 'Board'])]);
		$this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(function ($mapper, $id, $permission): bool {
			if (in_array($id, $this->hidden, true)) {
				if ($this->missing) { throw new \OCP\AppFramework\Db\DoesNotExistException('missing'); }
				throw new NoPermissionException('private');
			}
			if (!$this->edit && $permission === Acl::PERMISSION_EDIT) { throw new NoPermissionException('reader'); }
			return true;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn ($id) => $this->services[$id]);
		$this->gateway = new DeckServiceGateway($container, $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class));
	}

	public function testTraversalLoadsParentsOncePerOperationWithoutCardServiceFind(): void {
		$this->edges = [1 => [2, 3], 2 => [3]];
		$this->services[CardService::class]->expects(self::never())->method('find');
		$this->services[BoardService::class]->expects(self::exactly(2))->method('findAll')->with(-2, false, true);
		$this->services[StackMapper::class]->expects(self::exactly(2))->method('findAll')->with(20);
		for ($i = 0; $i < 2; $i++) {
			$graph = (new \OCA\Mcp\Modules\Deck\Dependencies\DependencyGraph($this->gateway))->read('alice', 1);
			self::assertCount(3, $graph['nodes']);
		}
	}

	public function testSelfTwoAndThreeNodeCyclesNeverReachNativeWrite(): void {
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		foreach ([[1, 1, []], [1, 2, [2 => [1]]], [1, 2, [2 => [3], 3 => [1]]]] as [$a, $b, $edges]) {
			$this->edges = $edges;
			try { $this->gateway->dependencyChangeState('alice', $a, $b, true); self::fail('Cycle accepted'); }
			catch (DeckRefusalException $e) { self::assertStringContainsString('cycles', $e->getMessage()); }
		}
	}

	public function testHiddenAndMissingRelationsProduceByteIdenticalReadableState(): void {
		foreach (['alice', 'editor'] as $uid) {
			$this->edges = [1 => [99], 2 => [99], 99 => [1]];
			$this->hidden = [99];
			foreach ([false, true] as $missing) {
				$this->missing = $missing;
				$reader = new \OCA\Mcp\Modules\Deck\Reading\ReadCardDependenciesHandler($this->gateway, $this->createMock(\Psr\Log\LoggerInterface::class), $this->cardFormatter());
				$public = $reader->handle(['cardId' => 1], $uid);
				$hidden = DependencyChange::snapshot($this->gateway->dependencyChangeState($uid, 1, 2, true));
				$this->edges = [];
				$absent = DependencyChange::snapshot($this->gateway->dependencyChangeState($uid, 1, 2, true));
				self::assertSame(json_encode($absent), json_encode($hidden));
				self::assertSame($public, $reader->handle(['cardId' => 1], $uid));
				$this->edges = [1 => [99], 2 => [99], 99 => [1]];
			}
		}
	}

	public function testTraversalBoundsRefuseBeforeWrite(): void {
		$this->edges = [];
		for ($i = 2; $i < 24; $i++) { $this->edges[$i] = [$i + 1]; }
		$this->expectException(DeckRefusalException::class);
		$this->gateway->dependencyChangeState('alice', 1, 2, true);
	}

	public function testUncertainTraversalAbortsWithoutNativeWrite(): void {
		$this->edges = [2 => [3]];
		$this->uncertain = true;
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		$this->expectException(\RuntimeException::class);
		$this->gateway->dependencyChangeState('alice', 1, 2, true);
	}

	public function testReadOnlyOriginAndHiddenTargetCannotBeEditedOrBlindlyRemoved(): void {
		$this->services[CardService::class]->expects(self::never())->method('removeDependentCard');
		foreach ([false, true] as $assign) {
			$this->edit = false;
			try { $this->gateway->dependencyChangeState('alice', 1, 2, $assign); self::fail('Read-only write'); }
			catch (NoPermissionException) { self::assertTrue(true); }
			$this->edit = true;
			$this->hidden = [2];
			try { $this->gateway->dependencyChangeState('alice', 1, 2, $assign); self::fail('Blind write'); }
			catch (NoPermissionException) { self::assertTrue(true); }
			$this->hidden = [];
		}
	}

	public function testFreshSnapshotConflictAndActiveTargetAreRequired(): void {
		$before = DependencyChange::snapshot($this->gateway->dependencyChangeState('alice', 1, 2, true));
		$this->edges = [2 => [3]];
		try { $this->gateway->changeDependency('alice', 1, 2, true, $before); self::fail('Stale graph accepted'); }
		catch (DeckConflictException) { self::assertTrue(true); }
		$this->fields[2] = ['archived' => true];
		$this->expectException(DeckRefusalException::class);
		$this->gateway->dependencyChangeState('alice', 1, 2, true);
	}

	public function testOnlyRequestedNativeAssociationChangesAndNoopDoesNotWrite(): void {
		$this->edges = [1 => [3]];
		$this->services[CardService::class]->expects(self::once())->method('assignDependentCard')->with(1, 2)
			->willReturnCallback(function (): Card { $this->edges[1][] = 2; return new Card(); });
		$this->services[CardService::class]->expects(self::once())->method('removeDependentCard')->with(1, 2)
			->willReturnCallback(function (): Card { $this->edges[1] = [3]; return new Card(); });
		foreach ([true, true, false, false] as $assign) {
			$state = $this->gateway->dependencyChangeState('alice', 1, 2, $assign);
			$this->gateway->changeDependency('alice', 1, 2, $assign, DependencyChange::snapshot($state));
		}
		self::assertSame([3], $this->edges[1]);
	}
}
