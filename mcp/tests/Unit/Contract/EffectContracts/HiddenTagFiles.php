<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesReferenceScope;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The Files exclusion of the effect contracts that touch a file outside the Files module: a fake tree, the hidden
 * system tag configured on the gate and the real guards over them, built fresh for each request as the container does
 * (they cache visibility per request). A step hides a node by putting its id in {@see self::$hidden}.
 */
final class HiddenTagFiles {
	/** Id of the hidden system tag. */
	public const HIDDEN_TAG = '999';

	public FakeTree $tree;
	/** @var list<int> file ids carrying the hidden tag */
	public array $hidden = [];

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param EffectsGate $gate the endpoint, whose configuration holds the hidden tag
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate) {
		$this->tree = new FakeTree($test);
		$gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
	}

	/** @return IRootFolder the root whose user folder is the fake tree */
	public function root(): IRootFolder {
		$root = $this->mock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		return $root;
	}

	/** @return VisibilityGuard the plain exclusion guard of one request, as the container autowires it */
	public function visibility(): VisibilityGuard {
		return new VisibilityGuard($this->gate->config(), $this->tags(), $this->filesystem());
	}

	/** @return FilesScope the resource scope of the gate policy (unrestricted: no scope is configured) */
	public function scope(): FilesScope {
		return new FilesScope($this->gate->policy(), $this->visibility(), $this->root());
	}

	/** @return FilesAccessGuard the Files gate of one request: exclusion and resource scope */
	public function access(): FilesAccessGuard {
		return new FilesAccessGuard($this->gate->config(), $this->tags(), $this->filesystem(), null, null, null,
			$this->scope());
	}

	/** @return FilesReferenceScope the Files scope step of the structured-reference filter */
	public function referenceScope(): FilesReferenceScope {
		return new FilesReferenceScope($this->scope());
	}

	/**
	 * @return ISystemTagObjectMapper the tag mapper answering the hidden tag for the ids in {@see self::$hidden}
	 */
	private function tags(): ISystemTagObjectMapper {
		$tags = $this->mock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine(
			array_map('strval', $ids),
			array_map(fn ($id): array => in_array((int)$id, $this->hidden, true) ? [self::HIDDEN_TAG] : [], $ids),
		));
		return $tags;
	}

	/** @return IRootFolder canonical parent resolution across a mount boundary, as core's root supplies it */
	private function filesystem(): IRootFolder {
		$root = $this->tree->filesystemRoot();
		$root->method('get')->willReturnCallback(fn (string $path) => $this->tree->node($path));
		return $root;
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
