<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Writing;

use OCP\IUserManager;
use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;

/**
 * Gate for writes on calendars owned by somebody else.
 *
 * It runs only after the ACL has already accepted the write, so a calendar the caller may not
 * write keeps answering with its own error, with or without `confirm_shared`. An accepted write
 * on a calendar of somebody else returns a non-error result asking for confirmation and touches
 * nothing until the call is repeated with `confirm_shared: true`.
 */
final class TaskSharedNotice {

	/**
	 * @param IUserManager $users resolves the display name of the calendar owner
	 */
	public function __construct(private IUserManager $users) {}

	/**
	 * @param Calendar $calendar calendar the write would touch
	 * @param string $userId authenticated UID
	 * @param array<string, mixed> $arguments tool arguments, read for `confirm_shared`
	 * @return array{content: list<array{type:string, text:string}>}|null confirmation to answer with, or null when the write may proceed
	 */
	public function confirm(Calendar $calendar, string $userId, array $arguments): ?array {
		if (!$calendar->ownedByOther($userId) || ($arguments['confirm_shared'] ?? null) === true) {
			return null;
		}
		$displayName = $this->users->get($calendar->ownerId)?->getDisplayName() ?: $calendar->ownerId;
		return SchemaBuilder::result([
			'requiresConfirmation' => true,
			'scope' => 'shared',
			'owner' => $calendar->ownerId,
			'ownerDisplayName' => $displayName,
			'resource' => $calendar->name,
			'message' => Translator::t('The calendar \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.', [$calendar->name, $displayName]),
		]);
	}
}
