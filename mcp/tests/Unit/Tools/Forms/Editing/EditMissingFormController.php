<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

/** Discriminant native-access error port used solely to prove the second throttle ordering. */
final class EditMissingFormController {
	/** Retains the unit test's event trace by reference.
	 * @param list<string> $events Ordering trace.
	 */
	public function __construct(private array &$events) { }
	/** Records native entry and emits the exact Forms exception handled by the shared throttle bridge.
	 * @param int $form Synthetic form.
	 * @param int $question Synthetic question.
	 * @param array<string,mixed> $patch Admitted point patch.
	 * @return never
	 */
	public function updateQuestion(int $form,int $question,array $patch):never{$this->events[]='native';throw new \OCA\Forms\Exception\NoSuchFormException('private access detail');}
}
