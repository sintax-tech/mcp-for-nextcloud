<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Presentation\DeckStatusMessages as Text;

/** Scalar confirmation state shared by the two focused status previews and handlers. */
final class CardStatusPlan {
	/** @param array<string,mixed> $state Authorized snapshot. @param string $field Explicit status field. @return void */
	public static function validate(array $state, string $field): void {
		if ($field === 'done' && $state['card']['archived']) {
			throw new DeckRefusalException(Text::archivedCard());
		}
	}

	/** @param array<string,mixed> $state Authorized snapshot. @param string $field done or archived. @param bool $value Intent. @return bool Whether an effect is needed. */
	public static function changes(array $state, string $field, bool $value): bool {
		$current = $field === 'done' ? $state['card']['done'] !== null : $state['card']['archived'];
		return $current !== $value;
	}

	/** @param array<string,mixed> $state Read state. @param string $field Status field. @param array<string,mixed> $arguments Validated args. @return array<string,mixed> Canonical argument-bound snapshot. */
	public static function snapshot(array $state, string $field, array $arguments): array {
		return ['state' => $state, 'field' => $field, 'requested' => $arguments[$field],
			'lastModifiedSent' => $arguments['lastModified'] ?? null];
	}

	/**
	 * @param string $tool Fixed tool identity, not an effect dispatcher.
	 * @param string $uid Caller for the HMAC.
	 * @param string $field done or archived.
	 * @param array<string,mixed> $arguments Validated intent.
	 * @param array<string,mixed> $state Native authorized scalar snapshot.
	 * @param PlanState $signer Instance-secret fingerprint service.
	 * @return array<string,mixed> Read-only public plan.
	 */
	public static function of(string $tool, string $uid, string $field, array $arguments, array $state, PlanState $signer): array {
		self::validate($state, $field);
		$value = $arguments[$field];
		$changed = self::changes($state, $field, $value);
		$destination = $state['autoMove']['destination'];
		$outside = $field === 'done' && $value && !$changed && $destination !== null && $destination['id'] !== $state['card']['stackId'];
		$message = $changed ? Text::intention($field, $value) : ($outside ? Text::noMove() : Text::unchanged());
		$board = $state['board'] + ['shared' => $state['board']['owner'] !== $uid];
		return ['action' => $tool, 'card' => $state['card'], 'board' => $board,
			'field' => $field, 'before' => $state['card'][$field], 'after' => $value, 'changed' => $changed,
			'currentList' => $state['list'], 'doneColumn' => $destination,
			'automaticMove' => $field === 'done' && $value && $changed && $destination !== null && $destination['id'] !== $state['card']['stackId'],
			'lastModified' => ['current' => $state['card']['lastModified'], 'sent' => $arguments['lastModified'] ?? null],
			'shared' => $board['shared'] ? [$board] : [], 'recoverable' => true,
			'message' => $message, 'limitations' => Text::limitations(),
			'noDestination' => $field === 'done' && $value && $state['autoMove']['supported'] && $destination === null ? Text::noDestination() : null,
			PlanState::ARGUMENT => $signer->of($tool, $uid, self::snapshot($state, $field, $arguments))];
	}
}
