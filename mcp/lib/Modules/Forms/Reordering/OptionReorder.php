<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reordering;

use OCA\Mcp\Modules\Forms\Editing\EditValues;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Restructuring\OptionPartition;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Restructuring\StructureEffect;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructureRequest;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\AppFramework\Http\DataResponse;

/**
 * forms_reorder_options: the complete option partition of one question in a new order, through native reorderOptions.
 *
 * A choice question sends no type, so native counts every option, legacy untyped ones included; a grid question names the
 * row or column partition. Options outside the partition keep their order.
 */
final class OptionReorder implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_reorder_options';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		$r = StructureRequest::of($args, ['form_id', 'question_id', 'option_type', 'option_ids'], ['form_id', 'question_id']);
		if (array_key_exists('option_type', $args)) {
			if (!in_array($args['option_type'], OptionPartition::TYPES, true)) {
				EditValues::invalid('option_type');
			}
			$r['option_type'] = $args['option_type'];
		}
		$r['option_ids'] = StructureRequest::ids($args['option_ids'] ?? null, 'option_ids');
		return StructureRequest::bounded($r);
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		$q = StructureState::question($s, $r['question_id']) ?? throw new ToolFailure(CommonMessages::notFound());
		$type = OptionPartition::nativeType($q, $r['option_type'] ?? null, false);
		$partition = OptionPartition::members($q, $type);
		$expected = OrderSet::complete($partition, $r['option_ids'], 'option_ids', array_column($q['options'], 'id'));
		return StructureEffect::of($s, ['target' => ['questionId' => $q['id'], 'questionType' => $q['type'], 'optionType' => $type],
			'before' => $partition, 'after' => array_map(static fn (int $id): array => ['id' => $id, 'order' => $expected[(string)$id]], $r['option_ids']),
			'noop' => OrderSet::unchanged($partition, $expected), 'effects' => [StructureMessages::reorderNotAtomic()],
			'expected' => ['orders' => $expected, 'nativeType' => $type, 'others' => array_values(array_udiff($q['options'], $partition,
				static fn (array $a, array $b): int => $a['id'] <=> $b['id']))]]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->reorderOptions($r['form_id'], $r['question_id'], $r['option_ids'], $e['expected']['nativeType']);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		return $response instanceof DataResponse && $response->getStatus() === 200 && is_array($response->getData())
			&& count($response->getData()) === count($r['option_ids']);
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		$q = StructureState::question($after, $r['question_id']);
		$options = $q === null ? [] : $q['options'];
		$result = OrderSet::observe($options, $e['before'], $e['expected']['orders']);
		$others = array_values(array_filter($options, static fn (array $o): bool => !array_key_exists((string)$o['id'], $e['expected']['orders'])));
		$result['complete'] = $result['complete'] && $others === $e['expected']['others'];
		return $result;
	}
}
