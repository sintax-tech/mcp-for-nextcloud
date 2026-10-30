<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

/** Test-only barrier runs a native logical deletion in a second process after active admission and before controller lookup. */
final class EditBarrierQuestionMapper extends \OCA\Forms\Db\QuestionMapper {
	/** Receives the unchanged native constructor inputs and one synthetic target.
	 * @param \OCP\IDBConnection $db Disposable database.
	 * @param \OCA\Forms\Db\OptionMapper $options Native option reader.
	 * @param int $form Synthetic form.
	 * @param string $uid Synthetic native actor.
	 */
	public function __construct(\OCP\IDBConnection $db, \OCA\Forms\Db\OptionMapper $options, private int $form, private string $uid) { parent::__construct($db,$options); }
	/** Deletes through the real controller in another process, then continues the unchanged native lookup.
	 * @param int|float $questionId Synthetic question already admitted by the MCP guard.
	 * @return \OCA\Forms\Db\Question Native row, which remains findable with order zero.
	 */
	public function findById(int|float $questionId): \OCA\Forms\Db\Question {
		$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__,2).'/ci/forms/edit-native-delete.php').' '.escapeshellarg($this->uid).' '.escapeshellarg((string)$this->form).' '.escapeshellarg((string)$questionId);
		$lines=[];exec($command.' 2>&1',$lines,$exit);
		if($exit!==0||implode('',$lines)!=='native-logical-delete'){throw new \RuntimeException('Second-process native deletion barrier failed');}
		return parent::findById($questionId);
	}
}
