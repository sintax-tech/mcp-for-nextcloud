<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\{Acl, AssignmentMapper, Board, Card, CardMapper, Label, LabelMapper, Stack, StackMapper};
use OCA\Deck\Service\{BoardService, CardService, PermissionService, StackService};
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCA\Mcp\Modules\Deck\Failures\DeckSessionException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/** Exercises the actual gateway boundary through each current producer, for reader and editor alike. */
final class DeckReferenceBoundaryTest extends TestCase {
	private array $services;
	private DeckServiceGateway $gateway;
	private Card $source;

	protected function setUp(): void {
		foreach ([BoardService::class, CardService::class, PermissionService::class, StackService::class,
			CardMapper::class, StackMapper::class, AssignmentMapper::class, LabelMapper::class] as $class) {
			$this->services[$class] = $this->createMock($class);
		}
		$this->source = new Card(['id' => 7, 'stackId' => 10, 'owner' => 'reader', 'title' => 'Source',
			'createdAt' => 100, 'dependentCards' => [101, 102, 103, 104, 105]]);
		$this->services[CardMapper::class]->method('find')->willReturnCallback(function ($id): Card {
			if ($id === 7) { return $this->source; }
			if ($id === 104) { throw new \RuntimeException('private resolution failure'); }
			return new Card(['id' => $id, 'stackId' => $id === 105 ? 99 : 30, 'deletedAt' => $id === 102 ? 1 : 0]);
		});
		$this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(function ($mapper, $id): bool {
			if ($id === 103) { throw new NoPermissionException('revoked target'); }
			return true;
		});
		$this->services[PermissionService::class]->method('getPermissions')->willReturn([Acl::PERMISSION_MANAGE => true]);
		$this->services[BoardService::class]->method('findAll')->willReturn([new Board(['id' => 20])]);
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 30, 'boardId' => 20])]);
		$this->services[StackMapper::class]->method('findBoardId')->willReturn(20);
		$this->services[CardMapper::class]->method('findBoardId')->willReturn(20);
		$this->services[CardMapper::class]->method('findDependenciesForCards')->willReturnCallback(fn ($ids) => [7 => $this->source->getDependentCards()]);
		$this->services[CardMapper::class]->method('findAll')->willReturn([$this->source]);
		$this->services[CardMapper::class]->method('findAllForStacks')->willReturn([10 => [$this->source]]);
		$this->services[AssignmentMapper::class]->method('findIn')->willReturn([]);
		$this->services[StackService::class]->method('findAll')->willReturn([new Stack(['cards' => [$this->source]])]);
		foreach (['find', 'create', 'update', 'delete'] as $method) {
			$this->services[CardService::class]->method($method)->willReturn($this->source);
		}
		$this->services[CardService::class]->method('reorder')->willReturn([$this->source]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn ($id) => $this->services[$id]);
		$time = $this->createMock(ITimeFactory::class); $time->method('getTime')->willReturn(100);
		$this->gateway = new DeckServiceGateway($container, $time);
	}

	public static function producers(): iterable {
		foreach (['reader', 'editor'] as $uid) {
			foreach (['read', 'list', 'stacks', 'followup', 'create', 'update', 'move', 'delete', 'state', 'find-created'] as $producer) {
				yield "$uid $producer" => [$uid, $producer];
			}
		}
	}

	#[DataProvider('producers')]
	public function testEveryProducerKeepsOnlyFreshReadableTargets(string $uid, string $producer): void {
		$g = $this->gateway;
		$card = match ($producer) {
			'read' => $g->findCard($uid, 7),
			'list' => $g->listCards($uid, 10, 50, 0)['items'][0],
			'stacks' => $g->listStacks($uid, 20)[0]->getCards()[0],
			'followup' => $g->followupCards($uid, 'all', null, null, null, 50)['items'][0]['card'],
			'create' => $g->createCard($uid, 10, 'Source', '', null),
			'update' => $g->updateCard($uid, $this->source, 'Source', '', null),
			'move' => $g->moveCard($uid, 7, 10, 0),
			'delete' => $g->deleteCard($uid, 7),
			'state' => $g->cardState($uid, 7),
			'find-created' => $g->findCreatedCard('reader', 10, 'Source'),
		};
		self::assertSame([101], $card->getDependentCards());
		self::assertSame([101, 102, 103, 104, 105], $this->source->getDependentCards(), 'Do not poison native entities for a later fresh read');
	}

	public function testParentListingsAreSharedOnlyInsideOnePublicCall(): void {
		$native = new Stack(['cards' => [$this->source, clone $this->source]]);
		$stacks = $this->createMock(StackService::class);
		$stacks->method('findAll')->willReturn([$native]);
		$this->services[StackService::class] = $stacks;
		$this->services[BoardService::class]->expects(self::exactly(2))->method('findAll')->with(-2, false, true);
		$this->services[PermissionService::class]->expects(self::exactly(20))->method('checkPermission');
		$this->services[CardMapper::class]->expects(self::exactly(16))->method('find');
		$active = true;
		$mapper = $this->createMock(StackMapper::class);
		$mapper->expects(self::exactly(2))->method('findAll')->with(20)->willReturnCallback(
			function () use (&$active): array { return $active ? [new Stack(['id' => 30])] : []; },
		);
		$this->services[StackMapper::class] = $mapper;
		foreach ($this->gateway->listStacks('reader', 20)[0]->getCards() as $card) {
			self::assertSame([101], $card->getDependentCards());
		}
		$active = false; // same gateway instance, a new public operation must see parent trash
		foreach ($this->gateway->listStacks('reader', 20)[0]->getCards() as $card) {
			self::assertSame([], $card->getDependentCards());
		}
	}

	public function testAclRevocationIsRecheckedOnTheNextRead(): void {
		// Same source object, permission changes between calls; no sanitized-entity or ACL result cache.
		$revoked = false;
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('checkPermission')->willReturnCallback(function ($mapper, $id) use (&$revoked): bool {
			if ($id !== 7 && $revoked) { throw new NoPermissionException('revoked'); }
			return true;
		});
		$this->services[PermissionService::class] = $permissions;
		self::assertContains(101, $this->gateway->findCard('reader', 7)->getDependentCards());
		$revoked = true;
		self::assertSame([], $this->gateway->findCard('reader', 7)->getDependentCards());
	}

	public function testEmbeddedCardFilteringDoesNotMutateTheNativeStack(): void {
		$native = new Stack(['cards' => [$this->source]]);
		$stacks = $this->createMock(StackService::class);
		$stacks->method('findAll')->willReturn([$native]);
		$this->services[StackService::class] = $stacks;
		$safe = $this->gateway->listStacks('reader', 20)[0];
		self::assertNotSame($native, $safe);
		self::assertSame($this->source, $native->getCards()[0]);
		self::assertSame([101], $safe->getCards()[0]->getDependentCards());
	}

	public function testParentBoardTrashIsCheckedBeyondNativePermissionCaches(): void {
		$boards = $this->createMock(BoardService::class);
		$boards->method('findAll')->willReturn([new Board(['id' => 20, 'deletedAt' => 1])]);
		$this->services[BoardService::class] = $boards;
		self::assertSame([], $this->gateway->findCard('editor', 7)->getDependentCards());
	}

	public function testLabelBoardMissingFromFreshListingNeverReachesTheMapper(): void {
		// A cached native permission says yes, but the fresh board listing says no (revoked or trashed).
		$this->services[LabelMapper::class]->expects(self::never())->method('findAll');
		$this->expectException(NoPermissionException::class);
		$this->gateway->listLabels('reader', 999, 50, 0);
	}

	public function testLabelsRequireAclBeforeTheMapperAndArePaginated(): void {
		$checked = false;
		$this->services[BoardService::class]->expects(self::once())->method('findAll')->with(-2, false, true);
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('checkPermission')->with(null, 20, Acl::PERMISSION_READ)->willReturnCallback(function () use (&$checked): bool { $checked = true; return true; });
		$this->services[PermissionService::class] = $permissions;
		$label = new Label(['id' => 2, 'boardId' => 20, 'title' => 'Ready', 'color' => '008800']);
		$this->services[LabelMapper::class]->expects(self::once())->method('findAll')->with(20, 1, 3)
			->willReturnCallback(function () use (&$checked, $label): array { self::assertTrue($checked); return [$label]; });
		self::assertSame([$label], $this->gateway->listLabels('reader', 20, 1, 3));
	}

	public function testDeniedLabelBoardNeverQueriesTheMapper(): void {
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('checkPermission')->willThrowException(new NoPermissionException('denied'));
		$this->services[PermissionService::class] = $permissions;
		$this->services[LabelMapper::class]->expects(self::never())->method('findAll');
		$this->expectException(NoPermissionException::class);
		$this->gateway->listLabels('editor', 20, 50, 0);
	}

	public function testSessionMismatchRejectsBeforeResolvingDeck(): void {
		$session = $this->createMock(ISession::class); $session->method('get')->with('user_id')->willReturn('other');
		$container = $this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
		$gateway = new DeckServiceGateway($container, $this->createMock(ITimeFactory::class), null, $session);
		$this->expectException(DeckSessionException::class);
		$gateway->listLabels('reader', 20, 50, 0);
	}
	public function testDependencyAdjacencyUsesTheCommonReadBoundaryForReaderAndEditor(): void {
		$this->source->setDependentCards([103, 101, 102]);
		$stacks = $this->createMock(StackMapper::class);
		$stacks->method('findAll')->willReturn([new Stack(['id' => 10]), new Stack(['id' => 30])]);
		$this->services[StackMapper::class] = $stacks;
		foreach (['reader', 'editor'] as $uid) {
			$node = $this->gateway->dependencyNode($uid, 7);
			self::assertSame([101], array_map(static fn (array $target): int => $target['card']->getId(), $node['targets']));
			self::assertSame([101], $node['card']->getDependentCards());
			self::assertSame([103, 101, 102], $this->source->getDependentCards());
		}
	}

	public function testUncertainDependencyTargetReadDoesNotClaimACompleteAdjacency(): void {
		$this->source->setDependentCards([104]);
		$stacks = $this->createMock(StackMapper::class);
		$stacks->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[StackMapper::class] = $stacks;
		$this->expectException(\RuntimeException::class);
		$this->gateway->dependencyNode('reader', 7);
	}

	public function testDeletedGraphSourceIsRefusedBeforeNativeServiceEnrichment(): void {
		$this->source = new Card(['id' => 7, 'stackId' => 10, 'deletedAt' => 1]);
		$this->services[CardService::class]->expects(self::never())->method('find');
		$this->expectException(NoPermissionException::class);
		$this->gateway->dependencyNode('reader', 7);
	}

	public static function hiddenFailures(): iterable {
		yield 'denied' => [false];
		yield 'missing' => [true];
	}

	#[DataProvider('hiddenFailures')]
	public function testHiddenNativeIdsDoNotConsumeGraphNodeEdgeOrDepthLimits(bool $missing): void {
		$this->source->setDependentCards(range(200, 400));
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('checkPermission')->willReturnCallback(static function ($mapper, $id) use ($missing): bool {
			if ($id !== 7) {
				if ($missing) { throw new \OCP\AppFramework\Db\DoesNotExistException('missing'); }
				throw new NoPermissionException('hidden');
			}
			return true;
		});
		$this->services[PermissionService::class] = $permissions;
		$stacks = $this->createMock(StackMapper::class);
		$stacks->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[StackMapper::class] = $stacks;
		$graph = (new \OCA\Mcp\Modules\Deck\Dependencies\DependencyGraph($this->gateway, 1, 0, 0))->read('reader', 7);
		self::assertTrue($graph['complete']);
		self::assertCount(1, $graph['nodes']);
		self::assertSame([], $graph['edges']);
	}

}
