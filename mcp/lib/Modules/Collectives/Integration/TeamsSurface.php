<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use ReflectionClassConstant;
use ReflectionException;

/**
 * The Teams (Circles 33-35) members managing the members of a collective reaches, and the values of the native
 * constants it relies on: member levels, member types, statuses and team settings. A constant with another value
 * means another meaning, so the shape is refused. Checked by introspection only; no service is built.
 */
final class TeamsSurface {
	/** Native team member. */
	public const MEMBER = 'OCA\\Circles\\Model\\Member';

	/**
	 * Every member called, as class, method, parameter types in order and return type; `?` marks a nullable type.
	 * @var list<array{0:string, 1:string, 2:list<string>, 3:string}>
	 */
	public const MEMBERS = [
		[CollectivesSurface::COLLECTIVES, 'getCollective', ['int', 'string'], CollectivesSurface::COLLECTIVE],
		[CollectivesSurface::COLLECTIVE, 'getCircleId', [], 'string'],
		[CollectivesSurface::COLLECTIVE, 'jsonSerialize', [], 'array'],
		[FoundingSurface::TEAMS, 'getLocalFederatedUser', ['string'], FoundingSurface::FEDERATED_USER],
		[FoundingSurface::TEAMS, 'getFederatedUser', ['string', 'int'], FoundingSurface::FEDERATED_USER],
		[FoundingSurface::TEAMS, 'startSession', ['?' . FoundingSurface::FEDERATED_USER, 'bool'], 'void'],
		[FoundingSurface::TEAMS, 'stopSession', [], 'void'],
		[FoundingSurface::TEAMS, 'getCircle', ['string', '?' . FoundingSurface::TEAM_PROBE], FoundingSurface::TEAM],
		[FoundingSurface::TEAMS, 'addMember', ['string', FoundingSurface::FEDERATED_USER], self::MEMBER],
		[FoundingSurface::TEAMS, 'levelMember', ['string', 'int'], self::MEMBER],
		[FoundingSurface::TEAMS, 'removeMember', ['string'], 'void'],
		[FoundingSurface::TEAM, 'getMembers', ['int'], 'array'],
		[FoundingSurface::TEAM, 'isConfig', ['int', 'int'], 'bool'],
		[self::MEMBER, 'getId', [], 'string'],
		[self::MEMBER, 'getUserId', [], 'string'],
		[self::MEMBER, 'getUserType', [], 'int'],
		[self::MEMBER, 'getLevel', [], 'int'],
		[self::MEMBER, 'getStatus', [], 'string'],
		[self::MEMBER, 'getDisplayName', [], 'string'],
		[self::MEMBER, 'hasBasedOn', [], 'bool'],
		[FoundingSurface::TEAM, 'getSource', [], 'int'],
		[FoundingSurface::TEAM, 'getName', [], 'string'],
	];

	/**
	 * The team a membership stands for, which identifies a group: one of these shapes, `Circle` on Circles 33 and 34,
	 * `?Circle` on 35. It is read only after `hasBasedOn()`.
	 * @var list<array{0:string, 1:string, 2:list<string>, 3:string}>
	 */
	public const BASED_ON = [
		[self::MEMBER, 'getBasedOn', [], FoundingSurface::TEAM],
		[self::MEMBER, 'getBasedOn', [], '?' . FoundingSurface::TEAM],
	];

	/**
	 * Native constants and the values the module relies on.
	 * @var list<array{0:string, 1:string, 2:int|string}>
	 */
	public const CONSTANTS = [
		[self::MEMBER, 'LEVEL_NONE', 0], [self::MEMBER, 'LEVEL_MEMBER', 1], [self::MEMBER, 'LEVEL_MODERATOR', 4],
		[self::MEMBER, 'LEVEL_ADMIN', 8], [self::MEMBER, 'LEVEL_OWNER', 9],
		[self::MEMBER, 'TYPE_USER', 1], [self::MEMBER, 'TYPE_GROUP', 2], [self::MEMBER, 'TYPE_CIRCLE', 16],
		[self::MEMBER, 'STATUS_INVITED', 'Invited'], [self::MEMBER, 'STATUS_REQUEST', 'Requesting'],
		[self::MEMBER, 'STATUS_MEMBER', 'Member'], [self::MEMBER, 'STATUS_BLOCKED', 'Blocked'],
		[FoundingSurface::TEAM, 'CFG_SINGLE', 1], [FoundingSurface::TEAM, 'CFG_PERSONAL', 2], [FoundingSurface::TEAM, 'CFG_INVITE', 32],
	];

	/** Static holder only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param list<array{0:string, 1:string, 2:list<string>, 3:string}> $members the members to prove
	 * @param list<array{0:string, 1:string, 2:int|string}> $constants the constants to prove
	 * @param list<array{0:string, 1:string, 2:list<string>, 3:string}> $basedOn the shapes of which one must match
	 * @return bool whether every member has the proven shape, one of the based-on shapes matches, and every constant
	 *     has its proven value
	 */
	public static function supported(array $members = self::MEMBERS, array $constants = self::CONSTANTS, array $basedOn = self::BASED_ON): bool {
		if (!(new CollectivesSurface($members))->supported()) {
			return false;
		}
		if (array_filter($basedOn, static fn (array $shape): bool => (new CollectivesSurface([$shape]))->supported()) === []) {
			return false;
		}
		foreach ($constants as [$class, $name, $value]) {
			try {
				if (!class_exists($class) || (new ReflectionClassConstant($class, $name))->getValue() !== $value) {
					return false;
				}
			} catch (ReflectionException) {
				return false;
			}
		}
		return true;
	}
}
