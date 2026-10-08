<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Settings;

use OCA\Mcp\Service\Compat\AppEnablement;
use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin page in the app's own "MCP for Nextcloud" section, in blocks: status (endpoint, service switch, version,
 * user summary), OAuth clients, hidden files & tags, OCR, the users × permissions matrix, the groups that may read
 * the server log and the active connections. The matrix, OAuth clients, tags and log groups are loaded and saved by
 * js/admin-grants.js; the connections by js/connections.js.
 */
class AdminSettings implements ISettings {
    /** Product name of each optional app with a minimum version, as the administrator knows it in the app list. */
    public const APP_NAMES = ['deck' => 'Deck', 'spreed' => 'Talk'];

    public function __construct(
        private GrantPolicy $policy,
        private IURLGenerator $urlGenerator,
        private OcrSupport $ocr,
        private IAppManager $appManager,
        private ConnectionList $connections,
        private LogsAccess $logs,
    ) {}

    /** @return TemplateResponse the rendered settings section */
    public function getForm(): TemplateResponse {
        $eligible = $this->policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY);
        return new TemplateResponse('mcp', 'admin', [
            'endpoint' => $this->urlGenerator->linkToRouteAbsolute('mcp.mcp.post'),
            'serviceEnabled' => $this->policy->globalEnabled(),
            'ocrActive' => $this->ocr->isActive(),
            'ocrUrl' => OcrSupport::APP_URL,
            'version' => $this->appManager->getAppVersion('mcp'),
            'eligibleUsers' => count($eligible),
            'connectedUsers' => count($this->policy->connectedUsers()),
            'activeConnections' => $this->connections->count(),
            'unsupportedApps' => $this->unsupportedApps(),
            'logsAvailable' => $this->logs->available(),
            'logType' => $this->logs->logType(),
        ], '');
    }

    /**
     * Optional apps that are enabled but older than MCP supports: their tools are hidden, and the page says why.
     *
     * @return list<array{name:string, installed:string, required:string}> one entry per such app
     */
    private function unsupportedApps(): array {
        return array_map(static fn (array $app): array => [
            'name' => self::APP_NAMES[$app['app']] ?? $app['app'],
            'installed' => $app['installed'],
            'required' => $app['required'],
        ], AppEnablement::unsupported($this->appManager));
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
