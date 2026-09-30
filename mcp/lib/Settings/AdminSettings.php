<?php
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin page (Additional settings): endpoint URL, service switch and the users × permissions matrix.
 * The matrix itself is loaded and saved by js/admin-grants.js through GrantsController.
 */
class AdminSettings implements ISettings {
    public function __construct(
        private GrantPolicy $policy,
        private IURLGenerator $urlGenerator,
    ) {}

    /** @return TemplateResponse the rendered settings section */
    public function getForm(): TemplateResponse {
        return new TemplateResponse('mcp', 'admin', [
            'endpoint' => $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post'),
            'serviceEnabled' => $this->policy->globalEnabled(),
        ], '');
    }

    /** @return string settings section id ('additional') */
    public function getSection(): string {
        return 'additional';
    }

    /** @return int ordering within the section */
    public function getPriority(): int {
        return 50;
    }
}
