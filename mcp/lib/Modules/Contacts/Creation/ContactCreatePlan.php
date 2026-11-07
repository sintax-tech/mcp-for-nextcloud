<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Creation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/**
 * Binds the UID of a contact creation to the plan the person confirms.
 *
 * Tasks hide a provisional UID because the confirmed call generates another one. A contact plan
 * already shows the UID (the card in `after`, and the vCard inside it). The plan/confirm pattern
 * of Mail, Notes, Tables and Forms is the one that fits: confirmation repeats `plan_state` and
 * writes the plan that value was computed for. The UID is generated once. `plan_state` carries it
 * as a prefix and the HMAC covers that UID plus the fields, so a confirmed call cannot store a
 * different one. When the fields differ, {@see PlanChanged} returns a new plan that keeps the UID
 * and says the plan changed; nothing is written.
 */
final class ContactCreatePlan {
	/** Hex length of {@see self::generate()}; the prefix of {@see self::token()}. */
	private const UID_LENGTH = 32;

	/** Arguments that change the card. `confirm`, `confirm_shared` and `plan_state` do not. */
	private const FIELDS = ['addressbook', 'name', 'organization', 'title', 'note', 'url', 'emails', 'phones'];

	/**
	 * @param PlanState $states instance-secret HMAC of what the plan showed
	 */
	public function __construct(private PlanState $states) {
	}

	/**
	 * The UID this call must use: the one carried by `plan_state`, or a new one when the call is a preview.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return string 32 lowercase hex characters
	 */
	public function uid(array $arguments): string {
		$carried = $this->carried($arguments[PlanState::ARGUMENT] ?? null);

		return $carried ?? $this->generate();
	}

	/**
	 * Puts `plan_state` on the plan. The value starts with the UID the plan shows.
	 *
	 * @param array<string, mixed> $plan plan of a creation, with `after.uid`
	 * @param string $userId authenticated user the plan is for
	 * @param array<string, mixed> $arguments validated tool arguments, without using `plan_state` as an input of the HMAC
	 * @return array<string, mixed> the same plan plus `plan_state`
	 */
	public function stamp(array $plan, string $userId, array $arguments): array {
		$plan[PlanState::ARGUMENT] = $this->token($userId, (string)($plan['after']['uid'] ?? ''), $arguments);

		return $plan;
	}

	/**
	 * Accepts a confirmation only when it repeats the plan's `plan_state`.
	 *
	 * A well-formed token whose UID is the one this plan shows, but whose HMAC is not, means the
	 * fields changed. The answer is this plan, UID unchanged, with a warning. A token that does not
	 * carry that UID is not a plan to approve.
	 *
	 * @param array<string, mixed> $plan plan just built for these arguments, already stamped
	 * @param array<string, mixed> $arguments validated tool arguments of the confirmed call
	 * @param string $userId authenticated user
	 * @throws ArgumentValidationException when `plan_state` is missing or does not carry this plan's UID
	 * @throws PlanChanged when the fields are not the ones that token approved; nothing was written
	 */
	public function approve(array $plan, array $arguments, string $userId): void {
		PlanState::require($arguments);
		$given = $arguments[PlanState::ARGUMENT];
		$uid = (string)($plan['after']['uid'] ?? '');
		$expected = (string)($plan[PlanState::ARGUMENT] ?? '');
		if (!is_string($given) || $this->carried($given) !== $uid || $uid === '') {
			throw new ArgumentValidationException(
				'Invalid argument: ' . PlanState::ARGUMENT,
				PlanState::ARGUMENT,
				Translator::t('required with confirm: true; send the plan_state of the plan'),
			);
		}
		if ($expected !== '' && hash_equals($expected, $given)) {
			return;
		}
		$plan['warnings'] = [['message' => Translator::t('The creation plan changed. Review the new plan before confirming.')]];
		throw new PlanChanged($plan);
	}

	/**
	 * @return string 32 lowercase hex characters, the same shape {@see ContactCard} used to draw at random
	 */
	private function generate(): string {
		return bin2hex(random_bytes(16));
	}

	/**
	 * @param mixed $token `plan_state` as sent, or null
	 * @return string|null the UID prefix when the token has the shape this class writes
	 */
	private function carried(mixed $token): ?string {
		if (!is_string($token) || strlen($token) !== self::UID_LENGTH + 1 + 64 || $token[self::UID_LENGTH] !== '.') {
			return null;
		}
		$uid = substr($token, 0, self::UID_LENGTH);
		$hmac = substr($token, self::UID_LENGTH + 1);
		if (!preg_match('/^[0-9a-f]{32}$/', $uid) || !preg_match('/^[0-9a-f]{64}$/', $hmac)) {
			return null;
		}

		return $uid;
	}

	/**
	 * @param string $userId authenticated user
	 * @param string $uid UID the plan shows and the write must store
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return string `uid` + `.` + the HMAC of that UID and the fields that change the card
	 */
	private function token(string $userId, string $uid, array $arguments): string {
		$fields = [];
		foreach (self::FIELDS as $field) {
			if (array_key_exists($field, $arguments)) {
				$fields[$field] = $arguments[$field];
			}
		}

		return $uid . '.' . $this->states->of('contacts_create_contact', $userId, ['uid' => $uid, 'fields' => $fields]);
	}
}
