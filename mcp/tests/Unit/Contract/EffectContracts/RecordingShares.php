<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCP\Files\Node;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A share manager whose shares live in a ledger: newShare() hands out a draft whose setters are recorded, createShare()
 * gives it the next sequential id and keeps it, and the lookups answer from what was created. The ids start at a fixed
 * number, so two runs create the same shares with the same ids. The sharing policy getters answer an instance where
 * sharing is enabled and nothing is restricted.
 */
final class RecordingShares {
	/** Id of the first share created. */
	private const FIRST_ID = 15;

	/** @var array<int, array{id:string, type:int, with:string, by:string, node:Node|null, permissions:int}> drafts by key */
	private array $records = [];
	/** @var array<int, IShare> share double of each draft, by key */
	private array $doubles = [];
	/** @var array<int, int> draft key by object id of its double */
	private array $keys = [];
	/** @var list<int> keys of the created shares, in creation order */
	private array $created = [];

	/** @param TestCase $test the running test, owner of the mocks */
	public function __construct(private TestCase $test) {
	}

	/** @return IManager the share manager over the ledger */
	public function manager(): IManager {
		$manager = $this->mock(IManager::class);
		$manager->method('newShare')->willReturnCallback(fn (): IShare => $this->draft());
		$manager->method('createShare')->willReturnCallback(fn (IShare $share): IShare => $this->create($share));
		$manager->method('getSharesBy')->willReturnCallback(fn (string $uid, int $type, ?Node $node = null): array => array_values(array_filter(
			$this->shares($type), fn (IShare $share): bool => $share->getSharedBy() === $uid
				&& ($node === null || $share->getNodeId() === (int)$node->getId()))));
		$manager->method('getSharedWith')->willReturn([]);
		$manager->method('shareApiEnabled')->willReturn(true);
		$manager->method('allowGroupSharing')->willReturn(true);
		return $manager;
	}

	/**
	 * @param int $type share type (IShare::TYPE_*)
	 * @return list<IShare> the created shares of that type, in creation order
	 */
	public function shares(int $type): array {
		$out = [];
		foreach ($this->created as $key) {
			if ($this->records[$key]['type'] === $type) {
				$out[] = $this->doubles[$key];
			}
		}
		return $out;
	}

	/** @return list<array{id:string, type:int, with:string, by:string, node:int, permissions:int}> the created shares */
	public function view(): array {
		return array_map(fn (int $key): array => ['node' => (int)$this->records[$key]['node']?->getId()] + $this->records[$key],
			$this->created);
	}

	/** @return IShare a new draft share, its setters recorded and its getters reading the record */
	private function draft(): IShare {
		$key = count($this->records);
		$this->records[$key] = ['id' => '', 'type' => 0, 'with' => '', 'by' => '', 'node' => null, 'permissions' => 0];
		$share = $this->mock(IShare::class);
		foreach (['setNode' => 'node', 'setShareType' => 'type', 'setSharedWith' => 'with', 'setSharedBy' => 'by',
			'setPermissions' => 'permissions'] as $method => $field) {
			$share->method($method)->willReturnCallback(function (mixed $value) use ($key, $field, $share): IShare {
				$this->records[$key][$field] = $value;
				return $share;
			});
		}
		$share->method('getId')->willReturnCallback(fn (): string => $this->records[$key]['id']);
		$share->method('getFullId')->willReturnCallback(fn (): string => 'ocinternal:' . $this->records[$key]['id']);
		$share->method('getShareType')->willReturnCallback(fn (): int => $this->records[$key]['type']);
		$share->method('getSharedWith')->willReturnCallback(fn (): string => $this->records[$key]['with']);
		$share->method('getSharedBy')->willReturnCallback(fn (): string => $this->records[$key]['by']);
		$share->method('getPermissions')->willReturnCallback(fn (): int => $this->records[$key]['permissions']);
		$share->method('getNodeId')->willReturnCallback(fn (): int => (int)$this->records[$key]['node']?->getId());
		$share->method('getNode')->willReturnCallback(fn (): Node => $this->records[$key]['node']
			?? throw new \OCP\Files\NotFoundException());
		$this->doubles[$key] = $share;
		$this->keys[spl_object_id($share)] = $key;
		return $share;
	}

	/**
	 * @param IShare $share a draft of this ledger
	 * @return IShare the same share, now created with the next id
	 */
	private function create(IShare $share): IShare {
		$key = $this->keys[spl_object_id($share)] ?? throw new \LogicException('not a draft of this ledger');
		$this->records[$key]['id'] = (string)(self::FIRST_ID + count($this->created));
		$this->created[] = $key;
		return $share;
	}

	/**
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private function mock(string $class): MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
