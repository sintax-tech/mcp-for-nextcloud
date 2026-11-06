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
 * forms_delete_option: permanent deletion through native deleteOption, allowed with answers (decision F2).
 *
 * Native then renumbers every remaining option of the question from 1, ignoring the partition, so grid rows and columns
 * interleave. The plan shows that renumbering and the receipt checks it.
 */
final class OptionDeletion implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_delete_option';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		return StructureRequest::bounded(StructureRequest::of($args, ['form_id', 'question_id', 'option_id'], ['form_id', 'question_id', 'option_id']));
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		$q = StructureState::question($s, $r['question_id']) ?? throw new ToolFailure(CommonMessages::notFound());
		$option = null;
		$after = [];
		foreach ($q['options'] as $o) {
			if ($o['id'] === $r['option_id']) {
				$option = $o;
				continue;
			}
			$after[] = ['id' => $o['id'], 'order' => count($after) + 1, 'optionType' => $o['optionType']];
		}
		if ($option === null) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		$effects = [StructureMessages::optionHardDelete(), StructureMessages::answersOrphaned(), StructureMessages::optionsRenumbered()];
		if (count($after) === 0) {
			$effects[] = StructureMessages::questionWithoutOptions();
		}
		return StructureEffect::of($s, ['target' => ['questionId' => $q['id'], 'questionType' => $q['type'], 'optionId' => $option['id'],
			'optionText' => $option['text'], 'optionType' => $option['optionType']], 'before' => $q['options'], 'after' => $after,
			'effects' => $effects, 'irreversible' => true, 'expected' => $after]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->deleteOption($r['form_id'], $r['question_id'], $r['option_id']);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		return $response instanceof DataResponse && $response->getStatus() === 200 && $response->getData() === $r['option_id'];
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		$q = StructureState::question($after, $r['question_id']);
		$options = $q === null ? [] : array_map(static fn (array $o): array => ['id' => $o['id'], 'order' => $o['order'], 'optionType' => $o['optionType']], $q['options']);
		$gone = $q !== null && !in_array($r['option_id'], array_column($q['options'], 'id'), true);
		return ['observed' => ['optionPresent' => !$gone, 'options' => $options], 'complete' => $gone && $options === $e['expected'], 'changed' => $gone];
	}
}
