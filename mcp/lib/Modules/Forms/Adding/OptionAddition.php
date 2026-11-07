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
use OCA\Mcp\Modules\Forms\Restructuring\OptionPartition;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Restructuring\StructureEffect;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructureRequest;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\AppFramework\Http\DataResponse;

/**
 * forms_add_option: appends options to one partition of a question through native newOption, with an explicit option type.
 *
 * The explicit type removes the 5.3.0 default (null) versus 5.3.7+ default ('choice'). The native call skips a failed insert
 * silently and still answers 201, so only the readback says how many options exist.
 */
final class OptionAddition implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_add_option';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		$r = StructureRequest::of($args, ['form_id', 'question_id', 'texts', 'option_type'], ['form_id', 'question_id']);
		$texts = $args['texts'] ?? null;
		if (!is_array($texts) || !array_is_list($texts) || $texts === [] || count($texts) > StructureState::MAX_ITEMS) {
			EditValues::invalid('texts');
		}
		$r['texts'] = array_map(static fn (mixed $text): string => EditValues::text($text, 1, 1024, 'texts'), $texts);
		if (!in_array($args['option_type'] ?? null, OptionPartition::TYPES, true)) {
			EditValues::invalid('option_type');
		}
		$r['option_type'] = $args['option_type'];
		return StructureRequest::bounded($r);
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		$q = StructureState::question($s, $r['question_id']) ?? throw new ToolFailure(CommonMessages::notFound());
		$type = OptionPartition::nativeType($q, $r['option_type'], true);
		if (count($q['options']) + count($r['texts']) > StructureState::MAX_ITEMS) {
			throw new ToolFailure(StructureMessages::limit());
		}
		$partition = OptionPartition::members($q, $type);
		$order = $partition === [] ? 1 : $partition[count($partition) - 1]['order'] + 1;
		$new = [];
		foreach ($r['texts'] as $text) {
			$new[] = ['text' => $text, 'order' => $order++, 'optionType' => $type];
		}
		$existing = array_column($q['options'], 'text');
		$effects = [StructureMessages::optionsAppended()];
		if ($type === 'choice' && in_array(null, array_column($q['options'], 'optionType'), true)) {
			$effects[] = StructureMessages::legacyOptions();
		}
		return StructureEffect::of($s, ['target' => ['questionId' => $q['id'], 'questionType' => $q['type'], 'optionType' => $type],
			'before' => $q['options'], 'after' => ['newOptions' => $new], 'effects' => $effects,
			'answers' => ['mayExist' => true, 'counted' => false, 'duplicatesExisting' => array_values(array_intersect($r['texts'], $existing))],
			'expected' => ['new' => $new, 'ids' => array_column($q['options'], 'id')]]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->newOption($r['form_id'], $r['question_id'], $r['texts'], $r['option_type']);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		$data = $response instanceof DataResponse ? $response->getData() : null;
		return $response instanceof DataResponse && $response->getStatus() === 201 && is_array($data) && array_is_list($data) && count($data) === count($r['texts']);
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		$q = StructureState::question($after, $r['question_id']);
		$added = $q === null ? [] : array_values(array_filter($q['options'], static fn (array $o): bool => !in_array($o['id'], $e['expected']['ids'], true)));
		$kept = $q !== null && count($q['options']) - count($added) === count($e['expected']['ids']);
		$shape = array_map(static fn (array $o): array => ['text' => $o['text'], 'order' => $o['order'], 'optionType' => $o['optionType']], $added);
		return ['observed' => ['options' => $added], 'complete' => $kept && $shape === $e['expected']['new'], 'changed' => $added !== [],
			'extra' => ['optionIds' => array_column($added, 'id')]];
	}
}
