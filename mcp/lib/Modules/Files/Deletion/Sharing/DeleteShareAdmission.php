<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion\Sharing;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\IManager;
use OCP\Share\IShare;

/** Call-local shared impact and canonical ancestry; never emits owners, recipients or share details. */
final class DeleteShareAdmission {
	/** @var list<int> Native types, including invalid shares without triggering their removal. */
	private array $types;
	/** @var Folder|null Actor root of this complete inspection. */
	private ?Folder $actorRoot = null;
	/** @var string|null Owner pinned to this inspection; a foreign nested mount cannot join its subtree. */
	private ?string $owner = null;

	/** @param DeleteLocalOrigin $origins Public canonical metadata resolver. @param IManager $shares Native all-provider share metadata. */
	public function __construct(private DeleteLocalOrigin $origins, private IManager $shares) {
		$this->types = array_values(array_unique(array_filter((new \ReflectionClass(IShare::class))->getConstants(),
			static fn ($value, $key): bool => str_starts_with($key, 'TYPE_') && is_int($value), ARRAY_FILTER_USE_BOTH)));
	}

	/**
	 * Checks the source's shared impact and external canonical ancestors once per inspection.
	 *
	 * @param Folder $root Actor root.
	 * @param string $uid Actor.
	 * @param Node $node Admitted actor source.
	 * @return bool Shared impact, with external ancestors checked once.
	 */
	public function source(Folder $root, string $uid, Node $node): bool {
		$this->actorRoot = $root;
		$origin = $this->origins->resolve($root, $uid, $node);
		$this->owner = $origin['owner'];
		$shared = $origin['received'] || $node->isShared();
		$audience = $this->audience($origin['node'], $this->owner, true);
		$shared = $shared || $audience;
		$current = $origin['node'];
		for ($depth = 0; ; $depth++) {
			if ($depth > 128) { throw new \RuntimeException('Unresolved ancestry'); }
			$impact = $this->node($current, $this->owner);
			$shared = $shared || $impact;
			if ($current->getId() === $origin['root']->getId() && $current->getPath() === $origin['root']->getPath()) { break; }
			$current = $current->getParent();
		}
		return $shared;
	}

	/**
	 * Checks a descendant's canonical owner boundary and shared impact.
	 *
	 * @param string $uid Actor.
	 * @param Node $node Admitted descendant, whose internal parents are separately checked.
	 * @return bool Shared impact of this node.
	 */
	public function descendant(string $uid, Node $node): bool {
		if ($this->actorRoot === null) { throw new \RuntimeException('No inspection'); }
		$origin = $this->origins->resolve($this->actorRoot, $uid, $node);
		if ($origin['owner'] !== $this->owner) { throw new \RuntimeException('Nested owner boundary'); }
		$audience = $this->audience($origin['node'], $this->owner, false);
		$impact = $this->node($origin['node'], $this->owner);
		return $origin['received'] || $node->isShared() || $audience || $impact;
	}

	/**
	 * Detects native shares on a verified canonical Local node.
	 *
	 * @param Node $node Canonical Local owner node.
	 * @param string $owner Its verified owner.
	 * @return bool Any native share, including expired/invalid metadata.
	 */
	private function node(Node $node, string $owner): bool {
		$this->origins->assertLocal($node, $owner);
		$shared = $node->isShared();
		foreach ($this->types as $type) {
			$impact = $this->shares->getSharesBy($owner, $type, $node, true, 1, 0, false) !== [];
			$shared = $shared || $impact;
		}
		return $shared;
	}

	/**
	 * Validates the native audience shape and reports only whether other people have access.
	 *
	 * @param Node $node Canonical Local node.
	 * @param string $owner Owner expected in the supported native result.
	 * @param bool $recursive Include external ancestors only for the source.
	 * @return bool Other people's access, without disclosing their identities.
	 */
	private function audience(Node $node, string $owner, bool $recursive): bool {
		$audience = $this->shares->getAccessList($node, $recursive, false);
		if (!is_array($audience['users'] ?? null) || !in_array($owner, $audience['users'], true)
			|| !is_bool($audience['remote'] ?? null) || !is_bool($audience['public'] ?? null) || !is_array($audience['mail'] ?? null)) {
			throw new \RuntimeException('Unknown audience');
		}
		return array_values(array_unique($audience['users'])) !== [$owner] || $audience['remote'] || $audience['public'] || $audience['mail'] !== [];
	}
}
