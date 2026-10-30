<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

/** Explicitly artificial legacy/inconsistent selector fixture; OCS rejects creating this state and that refusal is preserved in logs. */
final class EditInconsistentSelection {
	/** Adjusts only a disposable native entity and verifies persistence before invoking the real tool.
	 * @param int $form Synthetic form.
	 * @param int $question Synthetic non-email question.
	 * @return void
	 */
	public static function set(int $form,int $question):void{
		$mapper=\OCP\Server::get(\OCA\Forms\Db\FormMapper::class);$row=$mapper->findById($form);$row->setConfirmationEmailQuestionId($question);$mapper->update($row);
		if($mapper->findById($form)->getConfirmationEmailQuestionId()!==$question){throw new \RuntimeException('Artificial inconsistent selector was not persisted');}
	}
}
