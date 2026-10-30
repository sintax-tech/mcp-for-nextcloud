<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

/** Declares every untyped native Forms call; LibScanner still rejects undeclared class/member use. */
final class FormsApiUsage {
	public const EXTRA_CLASSES = ['OCA\\Forms\\Service\\FormsService', 'OCA\\Forms\\Db\\FormMapper', ...FormsResultsApiUsage::EXTRA_CLASSES];
	public const POSITIONAL = [
		...FormsResultsApiUsage::POSITIONAL,
		'OCA\\Forms\\Db\\Form::__construct' => [],
		'OCA\\Forms\\Db\\Form::setAccess' => ['access'],
		'OCA\\Forms\\Db\\Form::getId' => [],
		'OCA\\Forms\\Db\\Form::getHash' => [],
		'OCA\\Forms\\Db\\Form::getLastUpdated' => [],
		'OCA\\Forms\\Db\\Form::read' => [],
		'OCA\\Forms\\Service\\FormsService::getPermissions' => ['form'],
		'OCA\\Forms\\Service\\FormsService::hasUserAccess' => ['form'],
		'OCA\\Forms\\Service\\FormsService::getSharedForms' => ['user', 'filterShown'],
		'OCA\\Forms\\Service\\FormsService::getFormIfAllowed' => ['formId', 'permissions'],
		'OCA\\Forms\\Service\\FormsService::getForm' => ['form'],
		'OCA\\Forms\\Service\\FormsService::canSeeResults' => ['form'],
		'OCA\\Forms\\Service\\FormsService::canEditForm' => ['form'],
		'OCA\\Forms\\Service\\FormsService::getShares' => ['formId'],
		'OCA\\Forms\\Db\\FormMapper::findAllByOwnerId' => ['ownerId'],
	];
	public const REVIEWED_TRAILING = ['OCA\\Forms\\Db\\FormMapper::findAllByOwnerId' => ['queryTerm' => 'null: discover all native own forms, without a title filter']];
	public const CONSTANTS = ['OCA\\Forms\\Constants' => ['PERMISSION_ALL', 'PERMISSION_EDIT', 'PERMISSION_EMBED', 'PERMISSION_SUBMIT', ...FormsResultsApiUsage::CONSTANT_NAMES]];
}
