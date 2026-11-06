<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Deletion;

use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Restructuring\StructureEffect;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructureRequest;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\AppFramework\Http\DataResponse;

/**
 * forms_delete_question: logical deletion (order 0) through native deleteQuestion, allowed with answers (decision F1).
 *
 * Answers and options stay stored but orphaned; there is no restore. A selected confirmation-email question loses the
 * selection (FO4). For a file question, Forms queues a job that may delete its upload folders asynchronously (decision F3):
 * neither the job nor Files is inspected, and the receipt never claims what happened to the files.
 */
final class QuestionDeletion implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_delete_question';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		return StructureRequest::bounded(StructureRequest::of($args, ['form_id', 'question_id'], ['form_id', 'question_id']));
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		$q = StructureState::question($s, $r['question_id']) ?? throw new ToolFailure(CommonMessages::notFound());
		$after = [];
		foreach ($s['questions'] as $other) {
			if ($other['id'] !== $q['id']) {
				$after[(string)$other['id']] = $other['order'] > $q['order'] ? $other['order'] - 1 : $other['order'];
			}
		}
		$selector = $s['header']['selector'] === $q['id'] ? null : $s['header']['selector'];
		$effects = [StructureMessages::questionSoftDelete(), StructureMessages::answersOrphaned()];
		if ($q['type'] === 'file') {
			$effects[] = StructureMessages::uploadFoldersJob();
		}
		return StructureEffect::of($s, ['target' => ['questionId' => $q['id'], 'questionType' => $q['type'], 'questionText' => $q['text']],
			'before' => array_map([StructureEffect::class, 'listed'], $s['questions']), 'after' => $after, 'effects' => $effects,
			'irreversible' => true, 'selector' => $selector, 'confirmation' => StructureEffect::confirmation($s, $q, $selector),
			'expected' => ['orders' => $after, 'file' => $q['type'] === 'file']]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->deleteQuestion($r['form_id'], $r['question_id']);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		return $response instanceof DataResponse && $response->getStatus() === 200 && $response->getData() === $r['question_id'];
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		$gone = StructureState::question($after, $r['question_id']) === null;
		$orders = [];
		foreach ($after['questions'] as $q) {
			$orders[(string)$q['id']] = $q['order'];
		}
		$expected = $e['expected']['orders'];
		ksort($orders);
		ksort($expected);
		$moved = false;
		foreach ($e['before'] as $q) {
			$moved = $moved || (array_key_exists((string)$q['id'], $orders) && $orders[(string)$q['id']] !== $q['order']);
		}
		$job = $e['expected']['file'] ? ['queuedByNativeDeletion' => true, 'observed' => false] : null;
		return ['observed' => ['questionActive' => !$gone, 'orders' => $orders], 'complete' => $gone && $orders === $expected,
			'changed' => $gone || $moved, 'extra' => ['uploadFolderJob' => $job, 'filesObserved' => false]];
	}
}
