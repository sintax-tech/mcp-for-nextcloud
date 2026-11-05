<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCP\IURLGenerator;

/** Builds official authenticated routes; a UI results link does not grant MCP submissions access. */
final class FormDirectLinks {
	/** Receives the official route generator.
	 * @param IURLGenerator $urls Generates the installation's absolute native routes.
	 */
	public function __construct(private IURLGenerator $urls) {
	}

	/** Shapes links for an already authorized form without exposing its raw hash as a separate field.
	 * @param Form $form Authorized native entity.
	 * @param object $service Proven FormsService for the current actor.
	 * @param list<string> $permissions Native permissions, not cosmetic partial-array permissions.
	 * @return array<string,string> Existing authenticated UI routes.
	 */
	public function of(Form $form, object $service, array $permissions): array {
		$views = ['submit'];
		if (in_array(Constants::PERMISSION_EDIT, $permissions, true)) { $views[] = 'edit'; }
		if ($service->canSeeResults($form)) { $views[] = 'results'; }
		$links = [];
		foreach ($views as $view) { $links[$view] = $this->urls->linkToRouteAbsolute('forms.page.views', ['hash' => $form->getHash(), 'view' => $view]); }
		return $links;
	}
}
