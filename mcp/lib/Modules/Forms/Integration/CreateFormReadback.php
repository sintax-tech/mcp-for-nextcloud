<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCP\IURLGenerator;

/** Reads new objects through native mappers after native EDIT, without finally-to-empty helpers. */
class CreateFormReadback {
	/** Receives the native authenticated route generator.
	 * @param IURLGenerator $urls Native editor URL generation only.
	 */
	public function __construct(private IURLGenerator $urls) {
	}
	/** Reads the complete current creation prefix under owner/private invariants.
	 * @param CreateFormContext $context Exact proven native graph.
	 * @param int $id Identity returned by this operation's native creation.
	 * @param string $uid Authenticated actor and required owner.
	 * @return array<string,mixed> Internal snapshot, never serialized wholesale.
	 * @throws \Throwable On access, ownership, sharing, read or structure failure.
	 */
	public function of(CreateFormContext $context, int $id, string $uid): array {
		$form = $context->service->getFormIfAllowed($id, Constants::PERMISSION_EDIT);
		if (!$form instanceof Form || $form->getId() !== $id || $form->getOwnerId() !== $uid) { throw new \RuntimeException('Creation identity changed'); }
		// The mapper is used for readback only, after native EDIT on the exact new identity.
		$form = $context->forms->findById($id);
		if (!$form instanceof Form || $form->getId() !== $id || $form->getOwnerId() !== $uid) { throw new \RuntimeException('Creation owner changed'); }
		$data = $form->read();
		if (($data['access'] ?? null) !== ['permitAllUsers' => false, 'showToAllUsers' => false]
			|| ($data['state'] ?? null) !== 0 || $context->service->getShares($id) !== []) { throw new \RuntimeException('Creation is no longer private and active'); }
		$rows = $context->questions->findByForm($id);
		if (count($rows) > 50) { throw new \RuntimeException('Creation structure changed'); }
		$questions = []; $items = 0;
		foreach ($rows as $row) {
			if ((int)$row->getFormId() !== $id) { throw new \RuntimeException('Foreign question'); }
			$q = array_intersect_key($row->read(), array_flip(['id', 'formId', 'order', 'type', 'text', 'description', 'isRequired', 'extraSettings']));
			$q['options'] = [];
			foreach ($context->options->findByQuestion($row->getId()) as $option) {
				if (++$items > 1000 || (int)$option->getQuestionId() !== (int)$row->getId()) { throw new \RuntimeException('Creation options changed'); }
				$q['options'][] = array_intersect_key($option->read(), array_flip(['id', 'questionId', 'order', 'text', 'optionType']));
			}
			$questions[] = $q;
		}
		$header = array_intersect_key($data, array_flip(['id', 'ownerId', 'title', 'description', 'access', 'state', 'lastUpdated', 'lockedBy', 'lockedUntil',
			'expires', 'isAnonymous', 'submitMultiple', 'allowEditSubmissions', 'showExpiration', 'fileId', 'fileFormat',
			'confirmationEmailEnabled', 'confirmationEmailQuestionId', 'confirmationEmailSubject', 'confirmationEmailBody']));
		// Strict encoding rejects invalid native text instead of certifying a repaired prefix.
		json_encode([$header, $questions], JSON_THROW_ON_ERROR);
		return ['header' => $header, 'questions' => $questions, 'editorUrl' => $this->urls->linkToRouteAbsolute('forms.page.views', ['hash' => $form->getHash(), 'view' => 'edit'])];
	}
	/** Projects only newly returned IDs whose values were observed in an authorized snapshot.
	 * @param array<string,mixed> $snapshot Authorized current snapshot.
	 * @param list<int> $questionIds Identities returned by native calls in this operation.
	 * @param list<int> $optionIds Identities returned by native calls in this operation.
	 * @return array<string,mixed> Applied Forms prefix without owner/hash/shares or unrelated questions.
	 */
	public function receipt(array $snapshot, array $questionIds, array $optionIds): array {
		$questions = [];
		foreach ($snapshot['questions'] as $q) {
			if (!in_array($q['id'], $questionIds, true)) { continue; }
			$q['options'] = array_values(array_filter($q['options'], static fn (array $o): bool => in_array($o['id'], $optionIds, true)));
			unset($q['formId']);
			foreach ($q['options'] as &$o) { unset($o['questionId']); } unset($o);
			$questions[] = $q;
		}
		return ['formId' => $snapshot['header']['id'], 'editorUrl' => $snapshot['editorUrl'], 'title' => $snapshot['header']['title'],
			'description' => $snapshot['header']['description'], 'questions' => $questions];
	}
}
