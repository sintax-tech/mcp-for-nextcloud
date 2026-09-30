<?php
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCP\Util;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    public function __construct(
        private GrantPolicy $policy,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
    ) {}

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

    public function getSection(): string { return 'personal-info'; }
    public function getPriority(): int { return 50; }
}
