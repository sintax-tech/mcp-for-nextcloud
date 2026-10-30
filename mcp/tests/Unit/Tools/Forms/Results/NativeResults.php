<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Results;

require_once __DIR__ . '/../FormsFixture.php';
foreach (['Submission','Answer','Question','Option'] as $entity) { require_once __DIR__ . '/../../../../fixtures/forms-results-native/' . $entity . '.php'; }

/** Builds actual native entity bodies against OCP Entity, not product-shaped serializer mocks. */
final class NativeResults {
	/** Constructs a native entity through its official setters.
	 * @param string $kind Native short entity name.
	 * @param array $values Setter values.
	 * @return object Native entity.
	 */
	public static function entity(string $kind, array $values): object {
		$class = 'OCA\\Forms\\Db\\' . $kind; $entity = new $class();
		foreach ($values as $key => $value) { $entity->{'set' . ucfirst($key)}($value); }
		return $entity;
	}
}
