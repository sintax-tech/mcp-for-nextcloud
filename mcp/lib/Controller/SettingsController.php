<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Form targets of the admin and personal settings pages. Admin routes rely on Nextcloud's default
 * admin-only and CSRF checks; the personal route only lets a user change their own connection.
 */
class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private GrantPolicy $policy,
        private TokenService $oauthTokens,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $enabled '1' to enable the service, '0' to disable it
     * @return Response redirect to the admin page, or 400 for invalid input
     */
    public function global(string $enabled = '0'): Response {
        return $this->guard(function () use ($enabled): Response {
            if (!in_array($enabled, ['0', '1'], true)) {
                throw new InvalidArgumentException('Invalid value');
            }
            $this->policy->setGlobalEnabled($enabled === '1');
            return $this->adminRedirect();
        });
    }

    /**
     * @param string $uid existing Nextcloud user id
     * @param string $module grant module (kind=grant)
     * @param string $operation grant operation (kind=grant)
     * @param string $enabled '1' or '0'
     * @param string $kind 'eligible' or 'grant'
     * @return Response redirect to the admin page, or 400 for invalid input
     */
    public function user(string $uid = '', string $module = '', string $operation = '', string $enabled = '0', string $kind = 'grant'): Response {
        return $this->guard(fn (): Response => $this->applyUser($uid, $module, $operation, $enabled, $kind));
    }

    /**
     * @param string $enabled '1' to connect (needs service on and eligibility), '0' to disconnect
     * @return Response redirect to the personal page, or 400 for invalid input
     */
    #[NoAdminRequired]
    public function personal(string $enabled = '0'): Response {
        return $this->guard(fn (): Response => $this->applyPersonal($enabled));
    }

    /**
     * Validation failures become a generic 400 instead of an unhandled 500.
     *
     * @param callable():Response $action
     */
    private function guard(callable $action): Response {
        try {
            return $action();
        } catch (InvalidArgumentException) {
            return new DataDisplayResponse('Invalid request', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    /** @throws InvalidArgumentException for an unknown user, value, kind, module or operation */
    private function applyUser(string $uid, string $module, string $operation, string $enabled, string $kind): Response {
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

    /** @throws InvalidArgumentException without a valid user, value, service or eligibility */
    private function applyPersonal(string $enabled): Response {
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
        if ($enabled === '0') {
            // Disconnecting also revokes every OAuth access and refresh token of the user.
            $this->oauthTokens->revokeUser($uid);
        }
        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'personal-info']));
    }

    private function adminRedirect(): RedirectResponse {
        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'additional']));
    }
}
