<?php
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCP\Util;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Settings\ISettings;

/** Admin page (Additional settings): endpoint URL, global switch, eligibility and the grant matrix of one user. */
class AdminSettings implements ISettings {
    public function __construct(
        private GrantPolicy $policy,
        private IURLGenerator $urlGenerator,
        private IRequest $request,
        private IUserManager $userManager,
    ) {}

    /** @return TemplateResponse the rendered settings section */
    public function getForm(): TemplateResponse {
        $uid = $this->request->getParam('mcp_uid', '');
        $uid = is_string($uid) && $this->userManager->get($uid) !== null ? $uid : '';
        $grants = [];
        if ($uid !== '') {
            foreach (GrantPolicy::CATALOG as $module => $operations) {
                foreach ($operations as $operation) {
                    $grants[$module][$operation] = $this->policy->granted($uid, $module, $operation);
                }
            }
        }
        return new TemplateResponse('mcp', 'admin', [
            'endpoint' => $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post'),
            'globalAction' => $this->urlGenerator->linkToRoute('mcp.settings.global'),
            'userAction' => $this->urlGenerator->linkToRoute('mcp.settings.user'),
            'token' => Util::callRegister(),
            'enabled' => $this->policy->globalEnabled(),
            'catalog' => GrantPolicy::CATALOG,
            'selectedUid' => $uid,
            'selectedEligible' => $uid !== '' && $this->policy->eligible($uid),
            'grants' => $grants,
            'settingsUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'additional']),
        ], '');
    }

    /** @return string settings section id ('additional') */
    public function getSection(): string { return 'additional'; }
    /** @return int ordering within the section */
    public function getPriority(): int { return 50; }
}
