<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once dirname(__DIR__, 3) . '/Unit/Tools/Forms/Editing/EditingFixture.php';
require_once dirname(__DIR__, 3) . '/Unit/Tools/Forms/Creation/NativeNoSuchFormException.php';

use OCA\Forms\Exception\NoSuchFormException;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Tests\Unit\Tools\Forms\Editing\EditingForm;
use OCP\AppFramework\Http\DataResponse;

/** Read-only native-shaped row; writes go through the fixture so an unexpected setter fails loudly. */
final class StructureRow {
	/** @param array<string,mixed> $data stored fields */
	public function __construct(public array $data) {
	}

	/**
	 * @param string $name getter
	 * @param array<mixed> $args none
	 * @return mixed field value
	 */
	public function __call(string $name, array $args): mixed {
		if (!str_starts_with($name, 'get')) {
			throw new \RuntimeException('unexpected write');
		}
		return $this->data[lcfirst(substr($name, 3))];
	}
}

/**
 * Synthetic Forms graph reproducing the pinned ApiController structure semantics (lock, archive, shift, renumbering,
 * selector cleanup, file job) for unit tests. It is not an integration replacement; the pinned sources are checked by
 * StructureSourceContractTest and runtime cells cover the installed app.
 */
final class StructureFixture {
	public EditingForm $form;
	/** @var array<int,StructureRow> every question, deleted ones included (order 0) */
	public array $questions = [];
	/** @var array<int,StructureRow> every option */
	public array $options = [];
	/** @var list<string> native permissions of the actor */
	public array $permissions = ['edit', 'submit'];
	public bool $exists = true;
	public bool $validSettings = true;
	public bool $failAfterWrite = false;
	public bool $skipInsert = false;
	/** @var list<array{0:string,1:array<mixed>}> native mutator calls */
	public array $calls = [];
	private int $next = 100;

	/** Builds a form with a selected email question, a dropdown with two options and a file question. */
	public function __construct() {
		$this->form = new EditingForm(['title' => 'Form', 'state' => 0, 'lockedBy' => null, 'lockedUntil' => 0, 'lastUpdated' => 1,
			'confirmationEmailEnabled' => true, 'confirmationEmailQuestionId' => 2,
			'hash' => 'publichash', 'description' => '', 'fileId' => null, 'fileFormat' => null, 'created' => 1, 'expires' => 0,
			'isAnonymous' => false, 'submitMultiple' => false, 'allowEditSubmissions' => false, 'showExpiration' => false,
			'submissionMessage' => null, 'maxSubmissions' => null, 'confirmationEmailSubject' => null, 'confirmationEmailBody' => null,
			'allowComments' => false]);
		$this->question(2, 1, 'short', 'Email', ['validationType' => 'email']);
		$this->question(3, 2, 'dropdown', 'Choice', []);
		$this->question(4, 3, 'file', 'Upload', []);
		$this->option(10, 3, 1, 'A', 'choice');
		$this->option(11, 3, 2, 'B', 'choice');
	}

	/** Adds a stored question. @param int $id id @param int $order order @param string $type type @param string $text text @param array<string,mixed> $settings settings @return void */
	public function question(int $id, int $order, string $type, string $text, array $settings): void {
		$this->questions[$id] = new StructureRow(['id' => $id, 'formId' => 1, 'order' => $order, 'type' => $type, 'text' => $text, 'description' => '', 'isRequired' => false,
			'extraSettings' => $settings]);
	}

	/** Adds a stored option. @param int $id id @param int $question question @param int $order order @param string $text text @param string|null $type option type @return void */
	public function option(int $id, int $question, int $order, string $text, ?string $type): void {
		$this->options[$id] = new StructureRow(['id' => $id, 'questionId' => $question, 'order' => $order, 'text' => $text, 'optionType' => $type]);
	}

	/** @return EditContext the graph, with this fixture as every native port */
	public function context(): EditContext {
		return new EditContext('5.4.1', $this, $this, $this, $this, $this, new class {
			/** @param int $id form @return array<mixed> no share */
			public function findByForm(int $id): array {
				return [];
			}
		});
	}

	// FormsService and ConfigService

	/** @param int $id form @param string $permission native permission @return EditingForm form @throws NoSuchFormException like native */
	public function getFormIfAllowed(int $id, string $permission): EditingForm {
		if (!$this->exists || $id !== 1) {
			throw new NoSuchFormException('Could not find form');
		}
		if (!in_array($permission, $this->permissions, true)) {
			throw new NoSuchFormException('denied', 403);
		}
		return $this->form;
	}

	/** @param object $form form @return bool archived */
	public function isFormArchived(object $form): bool {
		return $this->form->fields['state'] === 2;
	}

	/** @param object $form form @return list<string> permissions */
	public function getPermissions(object $form): array {
		return $this->permissions;
	}

	/** @param array<string,mixed> $settings settings @param string $type current type @return bool controlled result */
	public function areExtraSettingsValid(array $settings, string $type): bool {
		return $settings === [] || $this->validSettings;
	}

	/** @return bool app-level confirmation switch */
	public function getAllowConfirmationEmail(): bool {
		return true;
	}

	// Mappers

	/** @param int $id form @return list<StructureRow> active questions by order */
	public function findByForm(int $id): array {
		$active = array_values(array_filter($this->questions, static fn (StructureRow $q): bool => $q->data['order'] > 0));
		usort($active, static fn (StructureRow $a, StructureRow $b): int => $a->data['order'] <=> $b->data['order']);
		return $active;
	}

	/** @param int $id question @param string|null $type filter @return list<StructureRow> options by order, id */
	public function findByQuestion(int $id, ?string $type = null): array {
		$rows = array_values(array_filter($this->options, static fn (StructureRow $o): bool => $o->data['questionId'] === $id && ($type === null || $o->data['optionType'] === $type)));
		usort($rows, static fn (StructureRow $a, StructureRow $b): int => [$a->data['order'], $a->data['id']] <=> [$b->data['order'], $b->data['id']]);
		return $rows;
	}

	// ApiController

	/** Native newQuestion without cloning. @return DataResponse 201 */
	public function newQuestion(int $formId, ?string $type, ?string $subtype, string $text, ?int $fromId, ?int $position): DataResponse {
		$this->calls[] = ['newQuestion', func_get_args()];
		$active = $this->findByForm($formId);
		$max = $active === [] ? 0 : end($active)->data['order'];
		if ($position !== null) {
			foreach ($active as $q) {
				if ($q->data['order'] >= $position) {
					$q->data['order']++;
				}
			}
		}
		$id = $this->next++;
		$this->question($id, $position ?? $max + 1, (string)$type, $text, $subtype ? ['questionType' => $subtype] : []);
		return $this->touch(new DataResponse(['id' => $id, 'formId' => $formId, 'type' => $type], 201));
	}

	/** Native reorderQuestions. @param int $formId form @param list<int> $order ids @return DataResponse 200 */
	public function reorderQuestions(int $formId, array $order): DataResponse {
		$this->calls[] = ['reorderQuestions', func_get_args()];
		foreach ($order as $i => $id) {
			$this->questions[$id]->data['order'] = $i + 1;
			if ($this->failAfterWrite) {
				throw new \RuntimeException('private native detail');
			}
		}
		return $this->touch(new DataResponse(array_fill_keys(array_map('strval', $order), [])));
	}

	/** Native newOption. @param int $formId form @param int $questionId question @param list<string> $texts texts @param string|null $type type @return DataResponse 201 */
	public function newOption(int $formId, int $questionId, array $texts, ?string $type): DataResponse {
		$this->calls[] = ['newOption', func_get_args()];
		$rows = $this->findByQuestion($questionId, $type);
		$order = $rows === [] ? 1 : end($rows)->data['order'] + 1;
		$added = [];
		foreach ($texts as $i => $text) {
			if ($this->skipInsert && $i === 0) {
				$order++;
				continue;
			}
			$id = $this->next++;
			$this->option($id, $questionId, $order++, $text, $type);
			$added[] = ['id' => $id];
		}
		return $this->touch(new DataResponse($added, 201));
	}

	/** Native reorderOptions. @param int $formId form @param int $questionId question @param list<int> $order ids @param string|null $type type @return DataResponse 200 */
	public function reorderOptions(int $formId, int $questionId, array $order, ?string $type): DataResponse {
		$this->calls[] = ['reorderOptions', func_get_args()];
		foreach ($order as $i => $id) {
			$this->options[$id]->data['order'] = $i + 1;
		}
		return $this->touch(new DataResponse(array_fill_keys(array_map('strval', $order), [])));
	}

	/** Native deleteQuestion. @param int $formId form @param int $questionId question @return DataResponse 200 */
	public function deleteQuestion(int $formId, int $questionId): DataResponse {
		$this->calls[] = ['deleteQuestion', func_get_args()];
		$q = $this->questions[$questionId];
		$deleted = $q->data['order'];
		$q->data['order'] = 0;
		if ($this->failAfterWrite) {
			throw new \RuntimeException('private native detail');
		}
		foreach ($this->findByForm($formId) as $other) {
			if ($other->data['order'] > $deleted) {
				$other->data['order']--;
			}
		}
		if ($this->form->fields['confirmationEmailQuestionId'] === $questionId) {
			$this->form->fields['confirmationEmailQuestionId'] = null;
		}
		return $this->touch(new DataResponse($questionId));
	}

	/** Native deleteOption. @param int $formId form @param int $questionId question @param int $optionId option @return DataResponse 200 */
	public function deleteOption(int $formId, int $questionId, int $optionId): DataResponse {
		$this->calls[] = ['deleteOption', func_get_args()];
		unset($this->options[$optionId]);
		foreach ($this->findByQuestion($questionId) as $i => $option) {
			$option->data['order'] = $i + 1;
		}
		return $this->touch(new DataResponse($optionId));
	}

	/** Native updateQuestion. @param int $formId form @param int $questionId question @param array<string,mixed> $patch patch @return DataResponse 200 */
	public function updateQuestion(int $formId, int $questionId, array $patch): DataResponse {
		$this->calls[] = ['updateQuestion', func_get_args()];
		$q = $this->questions[$questionId];
		if ($this->form->fields['confirmationEmailQuestionId'] === $questionId
			&& !(($patch['type'] ?? $q->data['type']) === 'short' && (($patch['extraSettings'] ?? $q->data['extraSettings'])['validationType'] ?? null) === 'email')) {
			$this->form->fields['confirmationEmailQuestionId'] = null;
		}
		if (isset($patch['extraSettings'])) {
			$patch['extraSettings'] = \OCA\Mcp\Modules\Forms\Editing\EditSettings::stored($patch['extraSettings']);
		}
		$q->data = array_replace($q->data, $patch);
		return $this->touch(new DataResponse($questionId));
	}

	/** Renews the native lock and lastUpdated, as formMapper->update after obtainFormLock does. @param DataResponse $r response @return DataResponse same */
	private function touch(DataResponse $r): DataResponse {
		$this->form->fields['lockedBy'] = 'alice';
		$this->form->fields['lockedUntil'] = time() + 900;
		$this->form->fields['lastUpdated'] = time();
		return $r;
	}
}
