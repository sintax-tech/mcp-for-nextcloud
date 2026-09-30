<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private GrantPolicy $policy,
    ) {
        parent::__construct($appName, $request);
    }

    public function global(string $enabled = '0'): RedirectResponse {
        $this->policy->setGlobalEnabled($enabled === '1');
        return $this->adminRedirect();
    }

    public function user(string $uid = '', string $module = '', string $operation = '', string $enabled = '0', string $kind = 'grant'): RedirectResponse {
        if ($uid === '' || $this->userManager->get($uid) === null || !in_array($enabled, ['0', '1'], true)) {
            throw new InvalidArgumentException('Invalid user or value');
        }
        if ($kind === 'eligible') {
            $this->policy->setEligible($uid, $enabled === '1');
        } elseif ($kind === 'grant') {
            $this->policy->setGrant($uid, $module, $operation, $enabled === '1');
        } else {
            throw new InvalidArgumentException('Invalid setting');
        }
        return $this->adminRedirect();
    }

    #[NoAdminRequired]
    public function personal(string $enabled = '0'): RedirectResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$user->isEnabled() || !in_array($enabled, ['0', '1'], true)) {
            throw new InvalidArgumentException('Invalid user or value');
        }
        $uid = $user->getUID();
        // Reactivation needs the service on and admin eligibility still valid; disconnecting is always allowed.
        if ($enabled === '1' && (!$this->policy->globalEnabled() || !$this->policy->eligible($uid))) {
            throw new InvalidArgumentException('Connection not allowed');
        }
        $this->policy->setConnected($uid, $enabled === '1');
        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'personal-info']));
    }

    private function adminRedirect(): RedirectResponse {
        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'additional']));
    }
}
