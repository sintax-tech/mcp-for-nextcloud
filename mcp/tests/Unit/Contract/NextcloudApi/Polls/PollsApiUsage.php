<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

/** All untyped native PO-1 calls, including constructor-only dependencies with no operations allowed. */
final class PollsApiUsage {
	/** Native argument names, from the four verified Polls releases. */
	public const POSITIONAL = [
		...PollsContentApiUsage::POSITIONAL,
		...PollsCreateApiUsage::POSITIONAL,
		'OCA\\Polls\\UserSession::cleanSession' => [],
		'OCA\\Polls\\UserSession::getCurrentUser' => [],
		'OCA\\Polls\\UserSession::getCurrentUserId' => [],
		'OCA\\Polls\\UserSession::getIsLoggedIn' => [],
		'OCA\\Polls\\UserSession::getShareToken' => [],
		'OCA\\Polls\\Model\\UserBase::getId' => [],
		'OCA\\Polls\\Model\\UserBase::getType' => [],
		'OCA\\Polls\\Helper\\Container::queryClass' => ['class'],
		'OCA\\Polls\\Service\\PollService::listPolls' => [],
		'OCA\\Polls\\Service\\PollService::get' => ['pollId'],
		'OCA\\Polls\\Service\\OptionService::list' => ['pollId'],
		'OCA\\Polls\\Service\\OptionService::__construct' => ['eventDispatcher','logger','optionMapper','pollMapper','userSession','poll','voteService'],
		'OCA\\Polls\\Db\\Poll::__construct' => [],
		'OCA\\Polls\\Db\\Poll::getId' => [],
		'OCA\\Polls\\Db\\Poll::getCreated' => [],
		'OCA\\Polls\\Db\\Poll::getAccess' => [],
		'OCA\\Polls\\Db\\Poll::getIsAllowed' => ['permission'],
		'OCA\\Polls\\Db\\Poll::getVoteUrl' => [],
		'OCA\\Polls\\Db\\Poll::jsonSerialize' => [],
		'OCA\\Polls\\Db\\Option::jsonSerialize' => [],
	];
	/** Constants used dynamically, still covered by the contract. */
	public const CONSTANTS = [
		'OCA\\Polls\\Db\\Poll' => ['PERMISSION_POLL_ACCESS', 'PERMISSION_POLL_EDIT', 'PERMISSION_POLL_RESULTS_VIEW', 'PERMISSION_OPTION_ADD'],
		...PollsContentApiUsage::CONSTANTS,
		'OCA\\Polls\\Model\\UserBase' => ['TYPE_USER','TYPE_ADMIN'],
	];
	/** Method-name collisions from the untyped scanner, each resolved to its real receiver. */
	public const NAME_CLASHES = [
		...PollsContentApiUsage::NAME_CLASHES,
		...PollsCreateApiUsage::NAME_CLASHES,
		...PollsMutationApiUsage::NAME_CLASHES,
		'OCA\\Deck\\Db\\Board::getShareToken' => 'OCA\\Polls\\UserSession::getShareToken, only PollsSessionGuard calls the new method',
		'OCA\\Polls\\Db\\Option::getCreated' => 'OCA\\Polls\\Db\\Poll::getCreated, only PollsShapes::key uses this getter',
		'OCA\\Polls\\Db\\Option::getDateTime' => 'DateTime properties in Tasks/Calendar/Talk; PO-1 serializes options, no getDateTime call',
		'OCA\\Polls\\Db\\Option::getDescription' => 'Tables and Deck descriptions, not Polls options',
		'OCA\\Polls\\Db\\Option::setDescription' => 'CalendarSelftest event description, not Polls options',
		'OCA\\Polls\\Db\\Option::setInterval' => 'BackgroundJob schedule interval, not Polls options',
		'OCA\\Polls\\Db\\Option::setText' => 'Calendar event text, not Polls options',
		'OCA\\Polls\\Model\\UserBase::getShareType' => 'OCP share types in Files/Deck/Talk, not the native user type (getType)',
	];
}
