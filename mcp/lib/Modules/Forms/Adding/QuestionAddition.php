<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Adding;

use OCA\Mcp\Modules\Forms\Editing\EditValues;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Restructuring\QuestionTypes;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Restructuring\StructureEffect;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructureRequest;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Http\DataResponse;

/**
 * forms_add_question: one question without options, at the end or at a position, through native newQuestion.
 *
 * The predicted orders follow the native shiftQuestionsForInsert exactly. A grid question carries its cell type, which the
 * native results code reads without a default. Cloning (fromId) is never used.
 */
final class QuestionAddition implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_add_question';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		$r = StructureRequest::of($args, ['form_id', 'type', 'text', 'position', 'grid_type'], ['form_id']);
		if (!in_array($args['type'] ?? null, QuestionTypes::CREATABLE, true)) {
			EditValues::invalid('type');
		}
		$r['type'] = $args['type'];
		$r['text'] = EditValues::text($args['text'] ?? null, 1, 2048, 'text');
		if (array_key_exists('position', $args)) {
			$r['position'] = StructureRequest::id($args['position'], 'position');
		}
		$grid = $r['type'] === 'grid';
		if ($grid !== array_key_exists('grid_type', $args) || ($grid && !in_array($args['grid_type'], QuestionTypes::GRID_TYPES, true))) {
			EditValues::invalid('grid_type');
		}
		if ($grid) {
			$r['grid_type'] = $args['grid_type'];
		}
		return StructureRequest::bounded($r);
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		$questions = $s['questions'];
		if (count($questions) >= StructureState::MAX_ITEMS) {
			throw new ToolFailure(StructureMessages::limit());
		}
		$max = $questions === [] ? 0 : $questions[count($questions) - 1]['order'];
		$position = $r['position'] ?? $max + 1;
		if ($position > $max + 1) {
			EditValues::invalid('position');
		}
		$shifted = [];
		foreach ($questions as $q) {
			if (isset($r['position']) && $q['order'] >= $position) {
				$shifted[(string)$q['id']] = $q['order'] + 1;
			}
		}
		$new = ['type' => $r['type'], 'text' => $r['text'], 'order' => $position,
			'extraSettings' => isset($r['grid_type']) ? ['questionType' => $r['grid_type']] : []];
		$after = array_map(static fn (array $q): array => ['id' => $q['id'], 'order' => $shifted[(string)$q['id']] ?? $q['order']], $questions);
		$effects = [StructureMessages::addQuestionNoOptions()];
		if ($shifted !== []) {
			$effects[] = StructureMessages::questionsShift(count($shifted));
		}
		return StructureEffect::of($s, ['target' => [], 'before' => array_map([StructureEffect::class, 'listed'], $questions),
			'after' => ['newQuestion' => $new, 'questions' => $after], 'effects' => $effects,
			'answers' => ['mayExist' => false, 'counted' => false], 'expected' => ['new' => $new, 'shifted' => $shifted]]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->newQuestion($r['form_id'], $r['type'], $r['grid_type'] ?? null, $r['text'], null, $r['position'] ?? null);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		$data = $response instanceof DataResponse ? $response->getData() : null;
		return $response instanceof DataResponse && $response->getStatus() === 201 && is_array($data) && is_int($data['id'] ?? null)
			&& ($data['formId'] ?? null) === $r['form_id'] && ($data['type'] ?? null) === $r['type'];
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		$id = $this->responseValid($response, $r) ? $response->getData()['id'] : null;
		$created = $id === null ? null : StructureState::question($after, $id);
		$expected = $e['expected'];
		$shiftedOk = true;
		$moved = false;
		foreach ($e['before'] as $q) {
			$now = StructureState::question($after, $q['id']);
			$want = $expected['shifted'][(string)$q['id']] ?? $q['order'];
			$shiftedOk = $shiftedOk && $now !== null && $now['order'] === $want;
			$moved = $moved || ($now !== null && $now['order'] !== $q['order']);
		}
		$observed = $created === null ? null : ['type' => $created['type'], 'text' => $created['text'], 'order' => $created['order'],
			'extraSettings' => $created['extraSettings'], 'options' => count($created['options'])];
		$match = $observed !== null && EditValues::canonical(array_diff_key($observed, ['options' => true])) === EditValues::canonical($expected['new'])
			&& $observed['options'] === 0;
		return ['observed' => ['questionId' => $id, 'question' => $observed], 'complete' => $match && $shiftedOk,
			'changed' => $created !== null || $moved, 'extra' => ['questionId' => $created === null ? null : $id]];
	}
}
