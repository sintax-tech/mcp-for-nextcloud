<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Forms\Db\Form;
use OCP\AppFramework\Http\DataResponse;

require_once __DIR__ . '/../FormsFixture.php';

/** Stateful native-shaped test double; integration, not this double, proves actual Forms behavior. */
final class CreateNativeState {
	public ?Form $form = null;
	public array $questions = [];
	public array $options = [];
	public array $calls = [];
	public array $collisions = [];
	public bool $partialOptions = false;
	public ?string $throwAfter = null;
	public array $shares = [];
	/** Creates a synthetic private native-shaped empty form before a possible post-insert exception.
	 * @param int|null $fromId Must be null.
	 * @return DataResponse Native-style 201 array.
	 */
	public function newForm(?int $fromId): DataResponse {
		if ($fromId !== null) { throw new \LogicException('Clone prohibited'); }
		$this->calls[] = 'form'; $this->form = new Form(); $this->form->setId(71); $this->form->setOwnerId('alice'); $this->form->setHash('native-capability');
		$this->form->data = ['id' => 71, 'ownerId' => 'alice', 'title' => '', 'description' => '', 'access' => ['permitAllUsers' => false, 'showToAllUsers' => false],
			'state' => 0, 'expires' => 0, 'isAnonymous' => false, 'submitMultiple' => false, 'allowEditSubmissions' => false, 'showExpiration' => false];
		$this->after('form'); return new DataResponse($this->form->read(), 201);
	}
	/** Updates only requested native metadata before a possible response failure.
	 * @param int $id Fresh form ID.
	 * @param array $fields Whitelisted fields.
	 * @return DataResponse Native-style scalar ID.
	 */
	public function updateForm(int $id, array $fields): DataResponse { $this->calls[] = 'metadata'; $this->form->data = $fields + $this->form->data; $this->after('metadata'); return new DataResponse($id); }
	/** Adds a synthetic native-shaped question with the same default fields as the pins.
	 * @param int $formId Fresh form.
	 * @param string $type Closed type.
	 * @param string|null $subtype Grid subtype.
	 * @param string $text Submitted text.
	 * @param int|null $fromId Must remain null.
	 * @param int|null $position Must remain null.
	 * @return DataResponse Created row.
	 */
	public function newQuestion(int $formId, string $type, ?string $subtype, string $text, ?int $fromId, ?int $position): DataResponse {
		if ($fromId !== null || $position !== null) { throw new \LogicException('Existing/reordered input'); }
		$this->calls[] = 'question'; $id = 100 + count($this->questions);
		$this->questions[$id] = ['id' => $id, 'formId' => $formId, 'type' => $type, 'text' => $text, 'description' => '', 'isRequired' => false, 'order' => count($this->questions) + 1, 'extraSettings' => $subtype === null ? [] : ['questionType' => $subtype]];
		$this->after('question'); return new DataResponse($this->questions[$id], 201);
	}
	/** Applies the permitted patch to a new synthetic question.
	 * @param int $formId Fresh parent.
	 * @param int $id Fresh question.
	 * @param array $fields Whitelisted patch.
	 * @return DataResponse Updated identity.
	 */
	public function updateQuestion(int $formId, int $id, array $fields): DataResponse { $this->calls[] = 'settings'; $this->questions[$id] = $fields + $this->questions[$id]; $this->after('settings'); return new DataResponse($id); }
	/** Reproduces a native 201 subset when an intermediate option insert fails.
	 * @param int $formId Fresh form.
	 * @param int $questionId Fresh question.
	 * @param array $texts Batch in order.
	 * @param string $category Explicit choice/row/column.
	 * @return DataResponse Observed native-style subset.
	 */
	public function newOption(int $formId, int $questionId, array $texts, string $category): DataResponse {
		$this->calls[] = 'options'; $batch = [];
		foreach ($texts as $i => $text) {
			if ($this->partialOptions && $i === 1) { continue; }
			$id = 200 + count($this->options); $batch[] = $this->options[$id] = ['id' => $id, 'questionId' => $questionId, 'text' => $text, 'optionType' => $category, 'order' => $i + 1];
		}
		$this->after('options'); return new DataResponse($batch, 201);
	}
	/** Resolves only the exact synthetic form, under the native EDIT mode expected by the writer.
	 * @param int $id Fresh identity.
	 * @param string $permission Native EDIT.
	 * @return Form Synthetic native entity.
	 */
	public function getFormIfAllowed(int $id, string $permission): Form { if ($id !== 71 || $permission !== 'edit') { throw new \RuntimeException('Wrong target'); } return $this->form; }
	/** Returns readback through the form mapper-shaped double.
	 * @param int $id Fresh ID.
	 * @return Form Synthetic form.
	 */
	public function findById(int $id): Form { return $this->form; }
	/** Returns explicit share state for private-invariant tests.
	 * @param int $id Fresh form.
	 * @return array Synthetic shares.
	 */
	public function getShares(int $id): array { return $this->shares; }
	/** Returns ordered question entities through the mapper-shaped double.
	 * @param int $id Fresh form.
	 * @return list<CreateRowView> Test rows.
	 */
	public function findByForm(int $id): array { return array_map(static fn (array $row): CreateRowView => new CreateRowView($row), array_values($this->questions)); }
	/** Returns ordered option entities through the mapper-shaped double.
	 * @param int $id Fresh question.
	 * @return list<CreateRowView> Test rows.
	 */
	public function findByQuestion(int $id): array { return array_map(static fn (array $row): CreateRowView => new CreateRowView($row), array_values(array_filter($this->options, static fn (array $o): bool => $o['questionId'] === $id))); }
	/** Returns a synthetic collision list; only the boolean outcome may reach product output.
	 * @param int $id Fresh form ID.
	 * @param string $uid Actor/owner.
	 * @return array Synthetic native helper results.
	 */
	public function getAllFormFoldersById(int $id, string $uid): array { return $this->collisions; }
	/** Fails after a synthetic sink, with deliberately private diagnostic text.
	 * @param string $stage Completed synthetic sink.
	 * @return void
	 */
	private function after(string $stage): void { if ($this->throwAfter === $stage) { throw new \RuntimeException('private path/secret DB details'); } }
}
