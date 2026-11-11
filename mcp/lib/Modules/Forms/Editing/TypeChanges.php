<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Modules\Forms\FormsVersionProfiles;
use OCA\Mcp\Modules\Forms\Integration\EditState;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Restructuring\OptionPartition;
use OCA\Mcp\Modules\Forms\Restructuring\QuestionTypes;
use OCA\Mcp\Modules\Forms\Restructuring\StructureEffect;
use OCA\Mcp\Modules\Forms\Restructuring\StructureOperation;
use OCA\Mcp\Modules\Forms\Restructuring\StructureRequest;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\AppFramework\Http\DataResponse;

/**
 * forms_change_question_type: one native updateQuestion PATCH with the target type and, when given, the final settings.
 *
 * Only pins whose profile permits type updates reach the native call. Those pins validate extraSettings only against the
 * current type and never validate the type, so we validate the final settings for the target and require that native
 * accepts them for the current type too; otherwise we refuse before the call. Settings are never dropped silently, answers
 * and options are never converted or deleted, and grid is never a target.
 */
final class TypeChanges implements StructureOperation {
	/** {@inheritDoc} */
	public function name(): string {
		return 'forms_change_question_type';
	}

	/** {@inheritDoc} */
	public function request(array $args): array {
		$r = StructureRequest::of($args, ['form_id', 'question_id', 'type', 'settings'], ['form_id', 'question_id']);
		if (($args['type'] ?? null) === 'grid') {
			throw new ArgumentValidationException('Invalid argument: type', 'type', StructureMessages::gridTarget());
		}
		if (!in_array($args['type'] ?? null, QuestionTypes::TARGETS, true)) {
			EditValues::invalid('type');
		}
		$r['type'] = $args['type'];
		if (array_key_exists('settings', $args)) {
			$r['settings'] = TypeSettings::final($r['type'], $args['settings']);
		}
		return StructureRequest::bounded($r);
	}

	/** {@inheritDoc} */
	public function effect(EditContext $c, array $r, array $s): array {
		if (!(FormsVersionProfiles::get($c->version)['updateQuestionAllowsType'] ?? false)) {
			throw new ToolFailure(StructureMessages::typeChangeUnavailable($c->version));
		}
		$q = StructureState::question($s, $r['question_id']) ?? throw new ToolFailure(CommonMessages::notFound());
		if (!in_array($q['type'], EditSettings::TYPES, true)) {
			throw new ToolFailure(StructureMessages::refused());
		}
		$noop = $q['type'] === $r['type'];
		// The same type is a no-op: different settings would never be applied, so they are refused, never shown as an effect.
		if ($noop && array_key_exists('settings', $r) && EditSettings::stored($r['settings']) !== EditValues::canonical($q['extraSettings'])) {
			throw new ArgumentValidationException('Invalid argument: settings', 'settings', StructureMessages::sameTypeSettings());
		}
		if (!$noop && !array_key_exists('settings', $r) && ($keys = TypeSettings::incompatible($r['type'], $q['extraSettings'])) !== []) {
			throw new ArgumentValidationException('Invalid argument: settings', 'settings', StructureMessages::settingsRequired($keys));
		}
		if (!$noop && array_key_exists('settings', $r) && $c->service->areExtraSettingsValid($r['settings'], $q['type']) !== true) {
			throw new ArgumentValidationException('Invalid argument: settings', 'settings', StructureMessages::settingsCurrentType());
		}
		$patch = $noop ? [] : ['type' => $r['type']] + (array_key_exists('settings', $r) ? ['extraSettings' => $r['settings']] : []);
		$next = ['type' => $r['type'], 'extraSettings' => !$noop && array_key_exists('settings', $r) ? EditSettings::stored($r['settings']) : EditValues::canonical($q['extraSettings'])];
		$selected = $s['header']['selector'] === $q['id'];
		$selector = $selected && !$noop && !EditState::email($next + $q) ? null : $s['header']['selector'];
		$effects = $noop ? [] : [StructureMessages::answersNotConverted()];
		if (!$noop && $q['options'] !== []) {
			$effects[] = OptionPartition::kind($r['type']) === null ? StructureMessages::optionsHidden() : StructureMessages::optionsKept();
		}
		if (!$noop && $q['type'] === 'file') {
			$effects[] = StructureMessages::uploadsUntouched();
		}
		return StructureEffect::of($s, ['target' => ['questionId' => $q['id'], 'questionText' => $q['text']],
			'before' => ['type' => $q['type'], 'settings' => $q['extraSettings']], 'after' => ['type' => $next['type'], 'settings' => $next['extraSettings']],
			'noop' => $noop, 'effects' => $effects, 'selector' => $selector, 'confirmation' => StructureEffect::confirmation($s, $q, $selector),
			'expected' => ['patch' => $patch, 'question' => $next, 'options' => $q['options']]]);
	}

	/** {@inheritDoc} */
	public function call(EditContext $c, array $r, array $e): mixed {
		return $c->controller->updateQuestion($r['form_id'], $r['question_id'], $e['expected']['patch']);
	}

	/** {@inheritDoc} */
	public function responseValid(mixed $response, array $r): bool {
		return $response instanceof DataResponse && $response->getStatus() === 200 && $response->getData() === $r['question_id'];
	}

	/** {@inheritDoc} */
	public function observe(EditContext $c, array $r, array $e, array $after, mixed $response): array {
		$q = StructureState::question($after, $r['question_id']);
		$observed = $q === null ? null : EditValues::canonical(['type' => $q['type'], 'extraSettings' => $q['extraSettings']]);
		$expected = $e['expected'];
		return ['observed' => ['question' => $observed, 'optionsPreserved' => $q !== null && $q['options'] === $expected['options']],
			'complete' => $q !== null && $observed === EditValues::canonical($expected['question']) && $q['options'] === $expected['options'],
			'changed' => $observed !== null && ($observed['type'] !== $e['before']['type'] || $observed['extraSettings'] !== EditValues::canonical($e['before']['settings']))];
	}
}
