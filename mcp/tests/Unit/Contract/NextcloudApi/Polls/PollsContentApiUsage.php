<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

/** Exact protected-read argument names/constants; no effect methods are an allowed usage. */
final class PollsContentApiUsage {
	/** Methods actually invoked by PO-2. Magic Entity getters are captured by source surfaces. */
	public const POSITIONAL = [
		'OCA\\Polls\\Db\\Poll::getAnonymous' => [],
		'OCA\\Polls\\Db\\Poll::getExpire' => [],
		'OCA\\Polls\\Db\\Poll::getHideBookedUp' => [],
		'OCA\\Polls\\Db\\Poll::getOptionLimit' => [],
		'OCA\\Polls\\Db\\Poll::getVoteLimit' => [],
		'OCA\\Polls\\Db\\Poll::getDeleted' => [],
		'OCA\\Polls\\Db\\Poll::getShowResults' => [],
		'OCA\\Polls\\Db\\Poll::getPollGroups' => [],
		'OCA\\Polls\\Db\\Share::getId' => [],
		'OCA\\Polls\\Db\\Share::getPollId' => [],
		'OCA\\Polls\\Db\\Share::getGroupId' => [],
		'OCA\\Polls\\Db\\Share::getDeleted' => [],
		'OCA\\Polls\\Db\\Share::getLocked' => [],
		'OCA\\Polls\\Service\\VoteService::list' => ['pollId'],
		'OCA\\Polls\\Service\\ShareService::list' => ['pollOrPollGroupId', 'purpose'],
		'OCA\\Polls\\Db\\Vote::jsonSerialize' => [],
		'OCA\\Polls\\Db\\Vote::getCurrentUserIsEntityUser' => [],
		'OCA\\Polls\\Db\\Share::getURL' => [],
		'OCA\\Polls\\Db\\Share::getType' => [],
	];
	/** Only public type constants; never raw hashes or private properties. */
	public const CONSTANTS = [
		'OCA\\Polls\\Model\\User\\Anon' => ['TYPE'],
		'OCA\\Polls\\Db\\Share' => ['TYPE_USER', 'TYPE_ADMIN', 'TYPE_GROUP', 'TYPE_PUBLIC', 'TYPE_EMAIL', 'TYPE_CONTACT', 'TYPE_EXTERNAL', 'TYPE_CONTACTGROUP'],
	];

	/** Untyped scanner collisions resolved to actual callers, not permission to invoke Polls effect APIs. */
	public const NAME_CLASHES = [
		'OCA\\Polls\\Model\\User\\Anon::getShareType' => 'OCP share type in Files/Deck/Talk; PO-2 uses only Anon::TYPE and native vote serialization',
		'OCA\\Polls\\Service\\ShareService::register' => 'MCP Policy DeferredQueue registration; PO-2 never registers a Polls share',
		'OCA\\Polls\\Service\\ShareService::request' => 'SharedWriteGuard and Files requests; PO-2 never requests/creates a Polls share',
	];
	/** The source pins 8.6.3 in NC31/32 and 9.x in NC33–35 rename this predicate in opposite directions. */
	public const VERSION_GATED = [
		'OCA\\Polls\\Db\\Poll::getIsExpired' => ['missing' => ['31', '32'], 'fallback' => 'OCA\\Polls\\Db\\Poll::getExpired',
			'by' => \OCA\Mcp\Modules\Polls\Integration\PollsExpiration::class],
		'OCA\\Polls\\Db\\Poll::getExpired' => ['missing' => ['33', '34', '35'], 'fallback' => 'OCA\\Polls\\Db\\Poll::getIsExpired',
			'by' => \OCA\Mcp\Modules\Polls\Integration\PollsExpiration::class],
	];

}
