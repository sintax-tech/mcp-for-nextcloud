<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Form target of the personal settings page: a user can only change their own connection.
 * The admin page uses the JSON API in GrantsController instead.
 */
class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private GrantPolicy $policy,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $enabled '1' to connect (needs service on and eligibility), '0' to disconnect
     * @return Response redirect to the personal page, or 400 for invalid input
     */
    #[NoAdminRequired]
    public function personal(string $enabled = '0'): Response {
        try {
            return $this->applyPersonal($enabled);
        } catch (InvalidArgumentException) {
            return new DataDisplayResponse('Invalid request', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
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
        return new RedirectResponse($this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'personal-info']));
    }
}
