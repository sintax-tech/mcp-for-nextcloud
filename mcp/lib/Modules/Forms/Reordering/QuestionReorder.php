<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reordering;

use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Restructuring\StructureEffect;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructureRequest;
use OCP\AppFramework\Http\DataResponse;

/**
 * forms_reorder_questions: the complete set of active questions in a new order, through native reorderQuestions.
 *
 * Native writes row by row without a transaction, so a failure can leave a partial order; the receipt says so.
 */
final class QuestionReorder implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_reorder_questions';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		$r = StructureRequest::of($args, ['form_id', 'question_ids'], ['form_id']);
		$r['question_ids'] = StructureRequest::ids($args['question_ids'] ?? null, 'question_ids');
		return StructureRequest::bounded($r);
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		$expected = OrderSet::complete($s['questions'], $r['question_ids'], 'question_ids');
		return StructureEffect::of($s, ['target' => [], 'before' => array_map([StructureEffect::class, 'listed'], $s['questions']),
			'after' => array_map(static fn (int $id): array => ['id' => $id, 'order' => $expected[(string)$id]], $r['question_ids']),
			'noop' => OrderSet::unchanged($s['questions'], $expected), 'effects' => [StructureMessages::reorderNotAtomic()],
			'answers' => ['mayExist' => true, 'counted' => false], 'expected' => $expected]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->reorderQuestions($r['form_id'], $r['question_ids']);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		return $response instanceof DataResponse && $response->getStatus() === 200 && is_array($response->getData())
			&& count($response->getData()) === count($r['question_ids']);
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		return OrderSet::observe($after['questions'], $e['before'], $e['expected']);
	}
}
