<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

/** Exact new native calls and scanner collisions; optional-field omission is separately checked. */
final class PollsCreateApiUsage {
	/** Argument names that are identical in all four native pins. Layout variants have focused source contracts. */
	public const POSITIONAL = [
		'OCA\\Polls\\Service\\PollService::__construct' => ['appSettings','eventDispatcher','poll','pollMapper','userMapper','userSession','voteMapper'],
		'OCA\\Polls\\Service\\OptionService::add' => ['pollId','simpleOption','voteYes'],
		'OCA\\Polls\\Service\\OptionService::get' => ['optionId'],
		'OCA\\Polls\\Model\\Settings\\AppSettings::getPollCreationAllowed' => [],
		'OCA\\Polls\\Model\\SimpleOption::fromArray' => ['option'],
		'OCA\\Polls\\Db\\Option::getPollId' => [],
		'OCA\\Polls\\Db\\Option::getId' => [],
		'OCA\\Polls\\Db\\Option::getTimestamp' => [],
		'OCA\\Polls\\Db\\Option::getDuration' => [],
		'OCA\\Polls\\Db\\Option::getPollOptionText' => [],
		'OCA\\Polls\\Db\\Option::getPollOptionHash' => [],
		'OCA\\Polls\\Db\\Poll::getDescription' => [],
		'OCA\\Polls\\Db\\Poll::getTitle' => [],
		'OCA\\Polls\\Db\\Poll::getType' => [],
		'OCA\\Polls\\Db\\Poll::getOwner' => [],
		'OCA\\Polls\\Db\\Poll::getLastInteraction' => [],
		'OCA\\Polls\\Db\\Poll::getVotingVariant' => [],
		'OCA\\Polls\\Db\\Poll::getAllowMaybe' => [],
		'OCA\\Polls\\Db\\Poll::getAllowComment' => [],
		'OCA\\Polls\\Db\\Poll::getUseNo' => [],
		'OCA\\Polls\\Db\\Poll::getAllowProposals' => [],
		'OCA\\Polls\\Db\\Poll::getProposalsExpire' => [],
		'OCA\\Polls\\Db\\Poll::getAdminAccess' => [],
		'OCA\\Polls\\Db\\Poll::getMiscSettings' => [],
	];
	/** The option parent setter was renamed; both real native APIs are independently gated. */
	public const VERSION_GATED = [
		'OCA\\Polls\\Db\\Poll::getTimezoneName' => ['missing'=>['31','32'],
			'pins'=>['31'=>'8.6.3','32'=>'8.6.3','33'=>'9.0.7','34'=>'9.1.4','35'=>'9.3.0'],
			'omittedField'=>'timezoneName','by'=>\OCA\Mcp\Tools\Polls\Create\CreateDefaults::class],
		'OCA\\Polls\\Db\\Option::setPoll' => ['missing'=>['31','32'],
			'fallback'=>'OCA\\Polls\\Db\\Option::setPollId','by'=>\OCA\Mcp\Tools\Polls\Create\CreateNormalizer::class],
	];
	/** New native classes are constructor dependencies/value objects; unrelated method names never authorize calls on them. */
	public const NAME_CLASHES = [
		'OCA\\Polls\\Db\\UserMapper::getUser' => 'OCP\\IUserSession::getUser in endpoint/session guards; this mapper is only a constructor dependency',
		'OCA\\Polls\\Db\\UserMapper::getParticipant' => 'OCA\\Talk\\Room::getParticipant and Deck recipient resolution, never Polls UserMapper',
		'OCA\\Polls\\Model\\SimpleOption::getDateTime' => 'Tasks/Calendar/Talk date values; MCP SimpleOption receives only fromArray',
		'OCA\\Polls\\Model\\SimpleOption::setInterval' => 'OCP\\BackgroundJob\\TimedJob::setInterval, never SimpleOption',
		'OCA\\Polls\\Model\\SimpleOption::getDuration' => 'OCA\\Polls\\Db\\Option::getDuration in CreateNormalizer, never SimpleOption',
		'OCA\\Polls\\Model\\SimpleOption::getOrder' => 'Deck stacks/cards getOrder, never SimpleOption',
		'OCA\\Polls\\Model\\SimpleOption::getTimestamp' => 'OCA\\Polls\\Db\\Option::getTimestamp and Calendar DateTime values, never SimpleOption',
		'OCA\\Polls\\Model\\SimpleOption::setTimestamp' => 'DateTime::setTimestamp in Deck CardDueDate, never SimpleOption',
	];
}
