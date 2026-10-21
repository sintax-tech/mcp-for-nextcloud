<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCP\Util;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/** Personal page in the app's own "MCP for Cloud" section: status, own connection switch, own OAuth connections (src/settings/connections.js) and app password guidance. */
class PersonalSettings implements ISettings {
    public function __construct(
        private GrantPolicy $policy,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
    ) {}

    /** @return TemplateResponse the rendered settings section */
    public function getForm(): TemplateResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        return new TemplateResponse('mcp', 'personal', [
            'endpoint' => $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post'),
            'action' => $this->urlGenerator->linkToRoute('mcp.settings.personal'),
            'token' => Util::callRegister(),
            'eligible' => $uid !== '' && $this->policy->eligible($uid),
            'connected' => $uid !== '' && $this->policy->connected($uid),
            'global' => $this->policy->globalEnabled(),
        ], '');
    }

    /** @return string settings section id, the app's own "MCP for Cloud" entry */
    public function getSection(): string { return PersonalSection::ID; }
    /** @return int ordering within the section */
    public function getPriority(): int { return 50; }
}
