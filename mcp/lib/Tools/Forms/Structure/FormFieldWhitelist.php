<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Structure;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCA\Mcp\Tools\Forms\FormsText;
use OCA\Mcp\Tools\Forms\Links\FormDirectLinks;
use OCA\Mcp\Tools\Forms\Links\PublicLinkShaper;

/** One whitelist for list and structure; raw hashes, owners, shares, submissions and counts never escape. */
final class FormFieldWhitelist {
	/** Receives focused link and question shapers.
	 * @param FormDirectLinks $direct Official authenticated routes.
	 * @param PublicLinkShaper $public Editor-only TYPE_LINK routes.
	 * @param FormStructureShape $questions Visible question/option structure.
	 */
	public function __construct(private FormDirectLinks $direct, private PublicLinkShaper $public, private FormStructureShape $questions) {
	}

	/** Shapes an authorized real Form; editor privileges come only from canEditForm, including archived forms.
	 * @param Form $form Authorized native entity.
	 * @param object $service Proven FormsService.
	 * @param bool $structure Whether to read the form's questions and respondent settings.
	 * @return array<string,mixed> Whitelisted read-only data.
	 */
	public function of(Form $form, object $service, bool $structure): array {
		$native = $structure ? $service->getForm($form) : $form->read();
		$permissions = $service->getPermissions($form);
		$result = array_intersect_key($native, array_flip(['id', 'title', 'state', 'expires', 'lastUpdated']));
		$result['title'] = FormsText::clean($result['title'] ?? '');
		$result['permissions'] = array_values(array_intersect($permissions, Constants::PERMISSION_ALL));
		$result['links'] = ['direct' => $this->direct->of($form, $service, $permissions)];
		if ($structure) {
			$result += array_intersect_key($native, array_flip(['isAnonymous', 'submitMultiple', 'allowEditSubmissions', 'canSubmit', 'isMaxSubmissionsReached']));
			$result['description'] = FormsText::clean($native['description'] ?? '');
			$result['submissionMessage'] = FormsText::clean($native['submissionMessage'] ?? '');
			$result['questions'] = $this->questions->of($native['questions']);
		}
		if ($service->canEditForm($form)) {
			$result += array_intersect_key($native, array_flip(['access', 'showExpiration', 'maxSubmissions', 'lockedBy', 'lockedUntil', 'allowComments', 'confirmationEmailEnabled', 'confirmationEmailSubject', 'confirmationEmailBody']));
			foreach (['lockedBy', 'confirmationEmailSubject', 'confirmationEmailBody'] as $key) {
				if (isset($result[$key])) { $result[$key] = FormsText::clean($result[$key]); }
			}
			$result['links']['public'] = $this->public->of($service->getShares((int)$form->getId()));
		}
		return $result;
	}
}
