<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Card;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DependencyGraph;
use OCA\Mcp\Tools\Deck\DependencyNodeSource;
use OCA\Mcp\Tools\Deck\Handler\ReadCardDependenciesHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

final class DependencyGraphTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * Build only the graph's narrow mock, or the full facade contract when constructing a handler.
	 *
	 * @template T of DependencyNodeSource
	 * @param array<int, list<int>> $adjacency Readable native graph used by the fixture.
	 * @param class-string<T> $interface Contract required by the test's constructor.
	 * @return T&\PHPUnit\Framework\MockObject\MockObject Mock with the same node responses and expectations.
	 */
	private function gateway(array $adjacency, string $interface = DependencyNodeSource::class): DependencyNodeSource {
		$gateway = $this->createMock($interface);
		$gateway->method('dependencyNode')->willReturnCallback(static function (string $uid, int $id) use ($adjacency): array {
			if (!array_key_exists($id, $adjacency)) {
				throw new \RuntimeException('read failed');
			}
			return ['card' => new Card(['id' => $id, 'title' => 'Card ' . $id, 'dependentCards' => [999]]),
				'boardId' => 20, 'targets' => array_map(static fn (int $target): array =>
					['card' => new Card(['id' => $target]), 'boardId' => 20], $adjacency[$id])];
		});
		return $gateway;
	}

	public function testCircularTraversalVisitsEachReadableNodeOnceAndOrdersEdges(): void {
		$gateway = $this->gateway([7 => [9, 8, 8], 8 => [7], 9 => [8]]);
		$gateway->expects(self::exactly(3))->method('dependencyNode');
		$graph = (new DependencyGraph($gateway))->read('alice', 7);
		self::assertSame([7, 8, 9], array_map(static fn (array $n): int => $n['card']->getId(), $graph['nodes']));
		self::assertSame([['originId' => 7, 'targetId' => 8], ['originId' => 7, 'targetId' => 9],
			['originId' => 8, 'targetId' => 7], ['originId' => 9, 'targetId' => 8]], $graph['edges']);
		self::assertTrue($graph['complete']);
	}

	public function testNativeDirectionDoesNotInventReverseEdges(): void {
		$graph = new DependencyGraph($this->gateway([7 => [8], 8 => []]));
		self::assertSame([['originId' => 7, 'targetId' => 8]], $graph->read('alice', 7)['edges']);
		self::assertSame([], $graph->read('alice', 8)['edges']);
	}

	public static function bounds(): iterable {
		yield 'nodes' => [1, 20, 20];
		yield 'edges' => [20, 1, 20];
		yield 'depth' => [20, 20, 0];
	}

	#[DataProvider('bounds')]
	public function testBoundsRefuseRatherThanPublishIncompleteGraph(int $nodes, int $edges, int $depth): void {
		$graph = new DependencyGraph($this->gateway([7 => [8], 8 => [9], 9 => []]), $nodes, $edges, $depth);
		$this->expectException(DeckRefusalException::class);
		$graph->read('alice', 7);
	}

	public function testDepthUsesShortestDistanceAndDoesNotRejectBackEdges(): void {
		$graph = new DependencyGraph($this->gateway([7 => [8], 8 => [7]]), 2, 2, 1);
		self::assertCount(2, $graph->read('alice', 7)['edges']);
	}

	public function testFailedExpansionDoesNotReturnPartialData(): void {
		$handler = new ReadCardDependenciesHandler($this->gateway([7 => [8]], DeckGatewayInterface::class),
			$this->createMock(LoggerInterface::class), $this->cardFormatter());
		$result = $handler->handle(['cardId' => 7], 'alice');
		self::assertTrue($result['isError']);
		self::assertStringNotContainsString('Card 7', json_encode($result));
	}

	public function testMinimalPublicGraphHasNoNativeCountersRelationsOrHiddenTotal(): void {
		$handler = new ReadCardDependenciesHandler($this->gateway([7 => [8], 8 => []], DeckGatewayInterface::class),
			$this->createMock(LoggerInterface::class), $this->cardFormatter());
		$data = $this->payload($handler->handle(['cardId' => 7], 'alice'));
		self::assertSame(['id', 'boardId', 'title', 'url'], array_keys($data['nodes'][0]));
		self::assertSame('origin.dependentCards contains target', $data['direction']);
		self::assertStringNotContainsString('attachmentCount', json_encode($data));
		self::assertArrayNotHasKey('hidden', $data);
		self::assertArrayNotHasKey('total', $data);
		self::assertStringContainsString('cannot be detected', $data['message']);
	}
}
