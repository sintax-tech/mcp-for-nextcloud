<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Explicit scalar projections; public share userId is itself a credential and must never escape. */
final class PollsWriteProjection {
	/**
	 * @param object $poll Native poll.
	 * @return array<string,mixed> Approved configuration with strict bool/int normalization.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function configuration(object $poll): array {
		$values = array_intersect_key($poll->getConfigurationArray(), PollsWriteSchema::configuration());
		foreach (PollsWriteSchema::BOOLEANS as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}
			if (!in_array($values[$key], [0, 1, false, true], true)) {
				throw PollsRefusal::unavailable();
			}
			$values[$key] = (bool) $values[$key];
		}
		return $values;
	}

	/**
	 * @param object $option Native option.
	 * @return array<string,mixed> No results, votes, aliases or counts.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function option(object $option): array {
		if (!$option instanceof \OCA\Polls\Db\Option) {
			throw PollsRefusal::unavailable();
		}
		return [
			'text' => $option->getPollOptionText(),
			'timestamp' => $option->getTimestamp(),
			'duration' => $option->getDuration(),
			'id' => $option->getId(),
			'order' => $option->getOrder(),
			'deleted' => $option->getDeleted() > 0,
			'owner' => $option->getOwner(),
		];
	}

	/**
	 * @param object $share Native share.
	 * @return array<string,mixed> Credential-free individual/public metadata.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function share(object $share): array {
		if (!$share instanceof \OCA\Polls\Db\Share) {
			throw PollsRefusal::unavailable();
		}
		$type = $share->getType();
		$email = $share->getEmailAddress();
		// In 9.x the native writer stores email/contact recipients as external. Expose only their valid address.
		if ($type === 'external' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
			$type = 'email';
		}
		$user = match ($type) {
			'user', 'admin', 'group', 'contact' => $share->getUserId(),
			'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
			default => '',
		};
		return [
			'id' => $share->getId(),
			'type' => $type,
			'userId' => $user,
			'label' => $type === 'public' ? $share->getLabel() : '',
			'locked' => $share->getLocked() > 0,
			'deleted' => $share->getDeleted() > 0,
			'invitationSent' => $share->getInvitationSent(),
		];
	}
}
