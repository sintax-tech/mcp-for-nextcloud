<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Modules\Forms\Editing\EditValues;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Fresh, canonical snapshot of a whole editable form: header, every active question with its options, shares and ACL.
 *
 * It is read only after native EDIT admitted the form. No submission, answer or Files data is read. The same snapshot feeds the
 * plan, the plan_state, the pre-call revalidation and the readback, so any concurrent change is detected.
 */
class StructureState {
	/** Most active questions, and most options per question, a structure operation reads. */
	public const MAX_ITEMS = 200;

	/** @param StructureTarget $target not-found/forbidden classification of the form */
	public function __construct(private StructureTarget $target) {
	}

	/**
	 * @param EditContext $c admitted native graph
	 * @param int $formId target form
	 * @param string $uid actor
	 * @param bool $writable whether archived forms and third-party locks are refused (false only for the readback)
	 * @return array<string,mixed> canonical snapshot
	 * @throws ToolFailure not found, forbidden, archived, locked, limit or refused
	 */
	public function read(EditContext $c, int $formId, string $uid, bool $writable = true): array {
		$form = $this->target->form($c, $formId);
		$header = ['id' => $form->getId(), 'title' => $form->getTitle(), 'owner' => $form->getOwnerId(), 'state' => $form->getState(),
			'lockedBy' => $form->getLockedBy(), 'lockedUntil' => $form->getLockedUntil(), 'lastUpdated' => $form->getLastUpdated(),
			'confirmationEnabled' => (bool)$form->getConfirmationEmailEnabled(), 'selector' => $form->getConfirmationEmailQuestionId(),
			'confirmationAllowedByApp' => $c->config->getAllowConfirmationEmail(), 'fields' => self::fingerprint($form)];
		if ($writable && $c->service->isFormArchived($form)) {
			throw new ToolFailure(StructureMessages::archived());
		}
		if ($writable && self::lockedByOther($header, $uid)) {
			throw new ToolFailure(StructureMessages::locked());
		}
		$rows = $c->questions->findByForm($formId);
		if (count($rows) > self::MAX_ITEMS) {
			throw new ToolFailure(StructureMessages::limit());
		}
		$questions = [];
		foreach ($rows as $row) {
			$questions[] = $this->project($c, $row, $formId);
		}
		usort($questions, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
		$shares = [];
		foreach ($c->shares->findByForm($formId) as $share) {
			if ($share->getFormId() !== $formId) {
				throw new ToolFailure(StructureMessages::refused());
			}
			$permissions = $share->getPermissions();
			sort($permissions);
			$shares[] = ['id' => $share->getId(), 'type' => $share->getShareType(), 'permissions' => $permissions];
		}
		usort($shares, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		$permissions = $c->service->getPermissions($form);
		sort($permissions);
		$access = $form->getAccess();
		$state = ['header' => $header, 'questions' => $questions,
			'shared' => $header['owner'] !== $uid || $shares !== [] || ($access['permitAllUsers'] ?? false) === true,
			'acl' => ['permissions' => $permissions, 'shares' => $shares, 'access' => $access]];
		EditValues::json($state);
		return EditValues::canonical($state);
	}

	/**
	 * Fingerprint of every other mutable form field of Db/Form, identical in the four pins, so a concurrent header change in
	 * the same second (same lastUpdated, same lock) still changes the plan_state. Only the SHA-256 digest enters the snapshot:
	 * the public share hash and the confirmation email are never copied into a plan, a receipt or a log.
	 *
	 * @param object $form native form, already admitted with EDIT
	 * @return string SHA-256 of the canonical field values
	 */
	private static function fingerprint(object $form): string {
		// Mutable Db/Form fields besides those projected in the header (title, owner, state, lock, lastUpdated, confirmation
		// selector and flag) and the ACL (access): the same accessors in the versions admitted by FormsVersionProfiles.
		$fields = ['hash' => $form->getHash(), 'description' => $form->getDescription(), 'fileId' => $form->getFileId(),
			'fileFormat' => $form->getFileFormat(), 'created' => $form->getCreated(), 'expires' => $form->getExpires(),
			'isAnonymous' => $form->getIsAnonymous(), 'submitMultiple' => $form->getSubmitMultiple(),
			'allowEditSubmissions' => $form->getAllowEditSubmissions(), 'showExpiration' => $form->getShowExpiration(),
			'submissionMessage' => $form->getSubmissionMessage(), 'maxSubmissions' => $form->getMaxSubmissions(),
			'confirmationEmailSubject' => $form->getConfirmationEmailSubject(), 'confirmationEmailBody' => $form->getConfirmationEmailBody(),
			'allowComments' => $form->getAllowComments()];
		return hash('sha256', EditValues::json(EditValues::canonical($fields)));
	}

	/**
	 * Native third-party lock condition of FormsService::obtainFormLock, identical in the four pins.
	 *
	 * @param array<string,mixed> $header snapshot header
	 * @param string $uid actor
	 * @return bool whether another user holds a lock that the native call would refuse
	 */
	public static function lockedByOther(array $header, string $uid): bool {
		return $header['lockedBy'] !== null && $header['lockedBy'] !== $uid
			&& ($header['lockedUntil'] >= time() || $header['lockedUntil'] === 0);
	}

	/**
	 * @param array<string,mixed> $state snapshot
	 * @param int $id question id
	 * @return array<string,mixed>|null the active question, or null when it is missing, deleted or foreign
	 */
	public static function question(array $state, int $id): ?array {
		foreach ($state['questions'] as $question) {
			if ($question['id'] === $id) {
				return $question;
			}
		}
		return null;
	}

	/**
	 * @param EditContext $c native graph
	 * @param object $row native question
	 * @param int $formId form the question must belong to
	 * @return array<string,mixed> projected active question with ordered options
	 */
	private function project(EditContext $c, object $row, int $formId): array {
		if ($row->getFormId() !== $formId || $row->getOrder() <= 0 || !is_array($row->getExtraSettings())) {
			throw new ToolFailure(StructureMessages::refused());
		}
		// Every mutable question field is signed, so a concurrent change of any of them invalidates the plan_state.
		$q = ['id' => $row->getId(), 'type' => $row->getType(), 'order' => $row->getOrder(), 'text' => $row->getText(),
			'description' => $row->getDescription(), 'isRequired' => (bool)$row->getIsRequired(), 'extraSettings' => $row->getExtraSettings(), 'options' => []];
		$options = $c->options->findByQuestion($q['id']);
		if (count($options) > self::MAX_ITEMS || strlen(EditValues::json($q['extraSettings'])) > 8192) {
			throw new ToolFailure(StructureMessages::limit());
		}
		foreach ($options as $option) {
			if ($option->getQuestionId() !== $q['id']) {
				throw new ToolFailure(StructureMessages::refused());
			}
			$q['options'][] = ['id' => $option->getId(), 'order' => $option->getOrder(), 'text' => $option->getText(), 'optionType' => $option->getOptionType()];
		}
		usort($q['options'], static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
		return $q;
	}
}
