<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

/**
 * The native members creating a collective reaches beyond the page surface: the collective service that creates the
 * team, the collective and its folder, the folder manager the receipt reads without creating anything, and the Teams
 * (Circles) manager that lists the teams the actor sees and closes the session the native code may leave open. The
 * shapes are those of Collectives 4.0.0, 4.4.0, 4.5.0 and 4.7.1 and Circles 33 to 35; they are checked with the same
 * introspection as {@see CollectivesSurface}, without building any service.
 */
final class FoundingSurface {
	/** Native folder manager of the collectives' storage. */
	public const FOLDERS = 'OCA\\Collectives\\Mount\\CollectiveFolderManager';
	/** Native Teams manager. */
	public const TEAMS = 'OCA\\Circles\\CirclesManager';
	/** Native team. */
	public const TEAM = 'OCA\\Circles\\Model\\Circle';
	/** Native team member. */
	public const MEMBER = 'OCA\\Circles\\Model\\Member';
	/** Native identity of an account in Teams. */
	public const FEDERATED_USER = 'OCA\\Circles\\Model\\FederatedUser';
	/** Native filter of team listings. */
	public const TEAM_PROBE = 'OCA\\Circles\\Model\\Probes\\CircleProbe';

	/**
	 * Every member read or called, as class, method, parameter types in order and return type; `?` marks a nullable type.
	 * @var list<array{0:string, 1:string, 2:list<string>, 3:string}>
	 */
	public const MEMBERS = [
		[CollectivesSurface::COLLECTIVES, 'createCollective', ['string', 'string', 'string', '?string'], 'array'],
		[CollectivesSurface::COLLECTIVES, 'getCollectives', ['string'], 'array'],
		[CollectivesSurface::COLLECTIVES, 'getCollective', ['int', 'string'], CollectivesSurface::COLLECTIVE],
		[CollectivesSurface::COLLECTIVE, 'getCircleId', [], 'string'],
		[CollectivesSurface::PAGES, 'find', ['int', 'int', 'string'], CollectivesSurface::PAGE_INFO],
		[self::FOLDERS, 'getRootPath', [], 'string'],
		[self::FOLDERS, 'getFolder', ['int'], 'OCP\\Files\\Folder'],
		[self::TEAMS, 'getLocalFederatedUser', ['string'], self::FEDERATED_USER],
		[self::TEAMS, 'startSession', ['?' . self::FEDERATED_USER, 'bool'], 'void'],
		[self::TEAMS, 'stopSession', [], 'void'],
		[self::TEAMS, 'getCircles', ['?' . self::TEAM_PROBE, 'bool'], 'array'],
		[self::TEAMS, 'getCircle', ['string', '?' . self::TEAM_PROBE], self::TEAM],
		[self::TEAM, 'getSingleId', [], 'string'],
		[self::TEAM, 'getName', [], 'string'],
		[self::TEAM, 'getSanitizedName', [], 'string'],
		[self::TEAM, 'getDisplayName', [], 'string'],
		[self::TEAM, 'getOwner', [], self::MEMBER],
		[self::TEAM, 'getMembers', ['int'], 'array'],
		[self::MEMBER, 'getUserId', [], 'string'],
		[self::MEMBER, 'getUserType', [], 'int'],
	];

	/** Static holder only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param list<array{0:string, 1:string, 2:list<string>, 3:string}> $members the members to prove; tests drop one at a time
	 * @return bool whether every member exists with exactly the proven shape
	 */
	public static function supported(array $members = self::MEMBERS): bool {
		return (new CollectivesSurface($members))->supported();
	}
}
