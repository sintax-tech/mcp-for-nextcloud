<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

/** Explicit fixture-only dependency fault after the real target mapper writes; never installed into native DI or production. */
final class EditFaultFormMapper extends \OCA\Forms\Db\FormMapper {
	/** Has no backend fields because the only permitted call is the deliberately failing update.
	 */
	public function __construct() { }
	/** Injects the final Form write failure after the real native target update.
	 * @param \OCP\AppFramework\Db\Entity $entity Synthetic form awaiting native final update.
	 * @return \OCA\Forms\Db\Form No value: the deliberate fixture fault throws.
	 */
	public function update(\OCP\AppFramework\Db\Entity $entity): \OCA\Forms\Db\Form { throw new \RuntimeException('Injected final Form update fault in isolated test dependency'); }
}
