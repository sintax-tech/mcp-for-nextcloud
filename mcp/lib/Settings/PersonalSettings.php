<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\Util;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/** Personal page in the app's own "MCP for Cloud" section: status, own connection switch, own OAuth connections (src/shared/connections.js, in js/mcp-personal.js) and app password guidance. */
class PersonalSettings implements ISettings {
	public function __construct(
		private GrantPolicy $policy,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
	) {}

	/** @return TemplateResponse the rendered settings section */
	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$eligible = $uid !== '' && $this->policy->eligible($uid);
		} catch (PolicyUnavailable) {
			// Shown as "cannot be checked right now", never as a refusal or a permission; connecting stays disabled.
			$eligible = null;
		}
		return new TemplateResponse('mcp', 'personal', [
			'endpoint' => $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post'),
			'action' => $this->urlGenerator->linkToRoute('mcp.settings.personal'),
			'token' => Util::callRegister(),
			'eligible' => $eligible,
			'connected' => $uid !== '' && $this->policy->connected($uid),
			'global' => $this->policy->globalEnabled(),
		], '');
	}

	/** @return string settings section id, the app's own "MCP for Cloud" entry */
	public function getSection(): string { return PersonalSection::ID; }
	/** @return int ordering within the section */
	public function getPriority(): int { return 50; }
}
