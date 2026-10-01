<?php
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin page in the app's own "MCP for Nextcloud" section, in blocks: status (endpoint, service switch, version,
 * user summary), OAuth clients, hidden files & tags, OCR, the users × permissions matrix and the active
 * connections. The matrix, OAuth clients and tags are loaded and saved by js/admin-grants.js; the
 * connections by js/connections.js.
 */
class AdminSettings implements ISettings {
    public function __construct(
        private GrantPolicy $policy,
        private IURLGenerator $urlGenerator,
        private OcrSupport $ocr,
        private IAppManager $appManager,
    ) {}

    /** @return TemplateResponse the rendered settings section */
    public function getForm(): TemplateResponse {
        $eligible = $this->policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY);
        $connected = array_intersect($eligible, $this->policy->flaggedUsers(GrantPolicy::CONNECTED_KEY));
        return new TemplateResponse('mcp', 'admin', [
            'endpoint' => $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post'),
            'serviceEnabled' => $this->policy->globalEnabled(),
            'ocrActive' => $this->ocr->isActive(),
            'ocrUrl' => OcrSupport::APP_URL,
            'version' => $this->appManager->getAppVersion('mcp'),
            'eligibleUsers' => count($eligible),
            'connectedUsers' => count($connected),
        ], '');
    }

    /** @return string settings section id, the app's own "MCP for Nextcloud" entry */
    public function getSection(): string {
        return AdminSection::ID;
    }

    /** @return int ordering within the section */
    public function getPriority(): int {
        return 50;
    }
}
