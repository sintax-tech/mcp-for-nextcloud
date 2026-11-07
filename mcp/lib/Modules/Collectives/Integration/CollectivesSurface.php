<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The native Collectives members this module calls, and the shape they have in 4.0.0, 4.5.0 and 4.7.1. A matching
 * shape is permission to try under the same guards, not proof of the semantics of an unpinned release. Classes are
 * introspected without being loaded into a service: no constructor runs.
 */
final class CollectivesSurface {
	/** Native collective service. */
	public const COLLECTIVES = 'OCA\\Collectives\\Service\\CollectiveService';
	/** Native page service. */
	public const PAGES = 'OCA\\Collectives\\Service\\PageService';
	/** Native content search service. */
	public const SEARCH = 'OCA\\Collectives\\Service\\SearchService';
	/** Native collective entity. */
	public const COLLECTIVE = 'OCA\\Collectives\\Db\\Collective';
	/** Native page model. */
	public const PAGE_INFO = 'OCA\\Collectives\\Model\\PageInfo';
	/** Content search of 4.0.0: collective, term and the most results to return. */
	public const SEARCH_WITH_LIMIT = 3;
	/** Content search of 4.4.0 and later: collective and query. */
	public const SEARCH_WITHOUT_LIMIT = 2;

	/**
	 * Every member read, as class, method, parameter types in order and return type. `?` marks a nullable type.
	 * @var list<array{0:string, 1:string, 2:list<string>, 3:string}>
	 */
	public const MEMBERS = [
		[self::COLLECTIVES, 'getCollectives', ['string'], 'array'],
		[self::COLLECTIVES, 'getCollective', ['int', 'string'], self::COLLECTIVE],
		[self::PAGES, 'getFolder', ['int', 'int', 'string'], 'OCP\\Files\\Folder'],
		[self::PAGES, 'find', ['int', 'int', 'string'], self::PAGE_INFO],
		[self::PAGES, 'getPageFile', ['int', 'int', 'string'], 'OCP\\Files\\File'],
		[self::PAGES, 'verifyEditPermissions', ['int', 'string'], 'void'],
		[self::PAGES, 'create', ['int', 'int', 'string', '?int', 'string', '?string'], self::PAGE_INFO],
		[self::PAGES, 'move', ['int', 'int', '?int', '?string', 'int', 'string'], self::PAGE_INFO],
		[self::COLLECTIVE, 'canEdit', [], 'bool'],
		[self::COLLECTIVE, 'getName', [], 'string'],
		[self::COLLECTIVE, 'jsonSerialize', [], 'array'],
		[self::PAGE_INFO, 'jsonSerialize', [], 'array'],
	];

	/**
	 * @param list<array{0:string, 1:string, 2:list<string>, 3:string}> $members the members to prove; tests drop one at a time
	 * @param string $search the content search class; tests point it elsewhere
	 */
	public function __construct(private array $members = self::MEMBERS, private string $search = self::SEARCH) {
	}

	/** @return bool whether every member exists with exactly the proven shape; content search is checked on its own */
	public function supported(): bool {
		foreach ($this->members as [$class, $method, $parameters, $return]) {
			if (!$this->matches($class, $method, $parameters, $return)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Which of the two proven content searches the installed version has.
	 * @return int|null SEARCH_WITH_LIMIT, SEARCH_WITHOUT_LIMIT, or null for any other shape
	 */
	public function searchArity(): ?int {
		if ($this->matches($this->search, 'searchCollective', [self::COLLECTIVE, 'string'], 'array')) {
			return self::SEARCH_WITHOUT_LIMIT;
		}
		if ($this->matches($this->search, 'searchCollective', [self::COLLECTIVE, 'string', 'int'], 'array', 2)) {
			return self::SEARCH_WITH_LIMIT;
		}
		return null;
	}

	/**
	 * @param string $class native class
	 * @param string $method public method
	 * @param list<string> $parameters parameter types in order, `?` prefixed when nullable
	 * @param string $return return type
	 * @param int|null $required required parameters, null when all of them are
	 * @return bool whether the method has exactly this shape
	 */
	private function matches(string $class, string $method, array $parameters, string $return, ?int $required = null): bool {
		if (!class_exists($class) || !method_exists($class, $method)) {
			return false;
		}
		try {
			$reflection = new ReflectionMethod($class, $method);
		} catch (ReflectionException) {
			return false;
		}
		$actual = $reflection->getParameters();
		if (!$reflection->isPublic() || $reflection->isStatic() || count($actual) !== count($parameters)
			|| ($required !== null && $reflection->getNumberOfRequiredParameters() !== $required)
			|| !self::typeIs($reflection->getReturnType(), $return)) {
			return false;
		}
		foreach ($actual as $index => $parameter) {
			if (!self::typeIs($parameter->getType(), $parameters[$index])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param \ReflectionType|null $type declared type
	 * @param string $expected expected name, `?` prefixed when nullable
	 * @return bool whether the declared type is exactly the expected one
	 */
	private static function typeIs(?\ReflectionType $type, string $expected): bool {
		$nullable = str_starts_with($expected, '?');
		return $type instanceof ReflectionNamedType && $type->allowsNull() === $nullable
			&& ltrim($type->getName(), '\\') === ltrim($expected, '?');
	}
}
