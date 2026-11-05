<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Forms\Db\Form;

/** Stable total ordering and state digest over the reauthorized candidate list. */
final class FormListOrder {
	/** Orders newest first, breaking equal timestamps by descending identity.
	 * @param list<Form> $forms Authorized candidates.
	 * @return list<Form> Ordered candidates.
	 */
	public static function sorted(array $forms): array {
		usort($forms, static fn (Form $a, Form $b): int => [$b->getLastUpdated(), $b->getId()] <=> [$a->getLastUpdated(), $a->getId()]);
		return $forms;
	}

	/** Digests only the permitted identities and modification timestamps.
	 * @param list<Form> $forms Sorted authorized candidates.
	 * @return string Stateless cursor binding, never a database snapshot.
	 */
	public static function digest(array $forms): string {
		return hash('sha256', json_encode(array_map(static fn (Form $form): array => [(int)$form->getId(), (int)$form->getLastUpdated()], $forms), JSON_THROW_ON_ERROR));
	}
}
