<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Editing;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Planning\ContactChange;

/** The plan of `contacts_edit_contact`: every property of the contact before and after. */
final class EditContactPreview {
	/** @param ContactChange $changes the plan shared with the deletion */
	public function __construct(private ContactChange $changes) {}

	/**
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments; omitted fields remain unchanged
	 * @return array<string, mixed> the plan; nothing is written
	 * @throws ToolFailure when access or the ETag refuses the edit
	 * @throws InvalidArgumentException when no field to change was given
	 */
	public function plan(string $userId, array $arguments): array {
		return $this->changes->prepare('contacts_edit_contact', $arguments, $userId)['plan'];
	}
}
