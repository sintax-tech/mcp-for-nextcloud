<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCA\Mcp\Tools\ToolFailure;

/** Reads only authorized active structure and the minimal native metadata needed to predict a point edit. */
class EditState {
	/** Reads the current actor-relative state, never a foreign/deleted question fallback or submission.
	 * @param EditContext $c Proven native graph.
	 * @param array<string,mixed> $r Closed point request.
	 * @param string $uid Actor.
	 * @param bool $writable Whether to enforce pre-sink archive/lock admission.
	 * @return array<string,mixed> Internal snapshot; never serialized wholesale.
	 */
	public function read(EditContext $c, array $r, string $uid, bool $writable = true): array {
		$form = $c->service->getFormIfAllowed($r['form_id'], Constants::PERMISSION_EDIT);
		if (!$form instanceof Form || $form->getId() !== $r['form_id']) { throw new ToolFailure(EditMessages::refused()); }
		$header = ['id' => $form->getId(), 'title' => $form->getTitle(), 'owner' => $form->getOwnerId(), 'state' => $form->getState(),
			'lockedBy' => $form->getLockedBy(), 'lockedUntil' => $form->getLockedUntil(), 'lastUpdated' => $form->getLastUpdated(),
			'confirmationEnabled' => (bool)$form->getConfirmationEmailEnabled(), 'selector' => $form->getConfirmationEmailQuestionId(),
			'confirmationAllowedByApp' => $c->config->getAllowConfirmationEmail()];
		if ($writable && ($header['state'] === 2 || ($header['lockedBy'] !== null && $header['lockedBy'] !== $uid && ($header['lockedUntil'] === 0 || $header['lockedUntil'] >= time())))) { throw new ToolFailure(EditMessages::refused()); }
		$rows = $c->questions->findByForm($r['form_id']);
		if (count($rows) > 200) { throw new ToolFailure(EditMessages::limit()); }
		$question = null;
		foreach ($rows as $row) {
			if ($row->getFormId() !== $r['form_id'] || $row->getOrder() <= 0) { throw new ToolFailure(EditMessages::refused()); }
			if ($row->getId() === $r['question_id']) { $question = $row; }
		}
		if ($question === null) { throw new ToolFailure(EditMessages::refused()); }
		$q = ['id' => $question->getId(), 'type' => $question->getType(), 'order' => $question->getOrder(), 'text' => $question->getText(),
			'description' => $question->getDescription(), 'isRequired' => (bool)$question->getIsRequired(), 'extraSettings' => $question->getExtraSettings(), 'options' => []];
		if (!in_array($q['type'], EditSettings::TYPES, true) || !is_array($q['extraSettings'])) { throw new ToolFailure(EditMessages::refused()); }
		$options = $c->options->findByQuestion($r['question_id']);
		if (count($options) > 200 || strlen(EditValues::json($q['extraSettings'])) > 8192) { throw new ToolFailure(EditMessages::limit()); }
		$option = null;
		foreach ($options as $row) {
			if ($row->getQuestionId() !== $r['question_id']) { throw new ToolFailure(EditMessages::refused()); }
			$o = ['id' => $row->getId(), 'order' => $row->getOrder(), 'text' => $row->getText(), 'optionType' => $row->getOptionType()];
			$q['options'][] = $o; if (($r['option_id'] ?? null) === $o['id']) { $option = $o; }
		}
		if ($r['option'] && $option === null) { throw new ToolFailure(EditMessages::refused()); }
		usort($q['options'], static fn (array $a, array $b): int => [$a['order'],$a['id']] <=> [$b['order'],$b['id']]);
		$shares = [];
		foreach ($c->shares->findByForm($r['form_id']) as $share) {
			if ($share->getFormId() !== $r['form_id']) { throw new ToolFailure(EditMessages::refused()); }
			$permissions = $share->getPermissions(); sort($permissions);
			$shares[] = ['id' => $share->getId(), 'type' => $share->getShareType(), 'permissions' => $permissions];
		}
		usort($shares, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		$permissions = $c->service->getPermissions($form); sort($permissions); $access = $form->getAccess();
		$s = ['header' => $header, 'question' => $q, 'option' => $option,
			'shared' => $header['owner'] !== $uid || $shares !== [] || ($access['permitAllUsers'] ?? false) === true,
			'acl' => ['permissions' => $permissions, 'shares' => $shares, 'access' => $access]];
		EditValues::json($s);
		return EditValues::canonical($s);
	}
	/** Builds only changed native fields and the exact expected target/selector without making a call.
	 * @param EditContext $c Current native validator.
	 * @param array<string,mixed> $r Closed request.
	 * @param array<string,mixed> $s Current authorized snapshot.
	 * @return array{patch:array<string,mixed>,expected:array<string,mixed>,selector:mixed} Prepared effect.
	 */
	public function effect(EditContext $c, array $r, array $s): array {
		$old = $r['option'] ? $s['option'] : $s['question']; $next = $old; $patch = [];
		foreach (['text' => 'text', 'description' => 'description', 'required' => 'isRequired'] as $key => $native) {
			if (array_key_exists($key, $r['changes']) && $old[$native] !== $r['changes'][$key]) { $patch[$native] = $r['changes'][$key]; $next[$native] = $r['changes'][$key]; }
		}
		if (!$r['option'] && (array_key_exists('settings', $r['changes']) || array_key_exists('unset_settings', $r['changes']))) {
			$final = EditSettings::merge($old['type'], $old['extraSettings'], $r['changes']);
			if ($c->service->areExtraSettingsValid($final, $old['type']) !== true) { EditValues::invalid('changes.settings'); }
			$stored = EditSettings::stored($final);
			if ($stored !== EditValues::canonical($old['extraSettings'])) { $patch['extraSettings'] = $final; $next['extraSettings'] = $stored; }
		}
		$selector = $s['header']['selector'];
		if (!$r['option'] && $patch !== [] && $selector === $r['question_id'] && !self::email($next)) { $selector = null; }
		return ['patch' => $patch, 'expected' => EditValues::canonical($next), 'selector' => $selector];
	}
	/** Tests the native recipient-type predicate without reading any respondent data.
	 * @param array<string,mixed> $question Authorized question shape.
	 * @return bool Whether the shape is a short email question.
	 */
	public static function email(array $question): bool { return $question['type'] === 'short' && ($question['extraSettings']['validationType'] ?? null) === 'email'; }
}
