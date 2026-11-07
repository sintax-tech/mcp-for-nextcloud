<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Deletion;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Planning\ContactChange;

/** The plan of `contacts_delete_contact`: the whole card, the permanent warning and the required backup folder. */
final class DeleteContactPreview {
	/** @param ContactChange $changes the plan shared with the edit */
	public function __construct(private ContactChange $changes) {}

	/**
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated contact address and concurrency fields
	 * @return array<string, mixed> deletion plan, including the current required copy directory
	 * @throws ToolFailure when access or the ETag refuses the deletion
	 */
	public function plan(string $userId, array $arguments): array {
		return $this->changes->prepare('contacts_delete_contact', $arguments, $userId)['plan'];
	}
}
