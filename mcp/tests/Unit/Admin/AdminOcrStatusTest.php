<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Settings\AdminSettings;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** The admin page tells whether Workflow OCR is active and never blocks on it. */
final class AdminOcrStatusTest extends TestCase {
    private function params(bool $active): array {
        $apps = $this->createMock(IAppManager::class);
        // The page also asks about the optional apps with a minimum version, for the unsupported-version notice.
        $apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app === 'workflow_ocr' && $active);
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('globalEnabled')->willReturn(true);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/mcp');
        return (new AdminSettings($policy, $urls, new OcrSupport($apps), $apps, $this->connections(), $this->createMock(\OCA\Mcp\Service\LogsAccess::class)))->getForm()->getParams();
    }

    private function connections(): ConnectionList {
        $connections = $this->createMock(ConnectionList::class);
        $connections->method('count')->willReturn(4);
        return $connections;
    }

    public function testStatusFollowsTheApp(): void {
        $this->assertTrue($this->params(true)['ocrActive']);
        $inactive = $this->params(false);
        $this->assertFalse($inactive['ocrActive']);
        $this->assertSame('https://apps.nextcloud.com/apps/workflow_ocr', $inactive['ocrUrl']);
    }

    /** The status block counts eligible users and, among them, those who activated their connection. */
    public function testStatusSummaryCountsEligibleAndConnectedUsers(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppVersion')->with('mcp')->willReturn('0.8.0');
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('flaggedUsers')->with(GrantPolicy::ELIGIBLE_KEY)->willReturn(['ana', 'bob', 'carl']);
        $policy->method('connectedUsers')->willReturn(['bob']);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/mcp');
        $params = (new AdminSettings($policy, $urls, new OcrSupport($apps), $apps, $this->connections(), $this->createMock(\OCA\Mcp\Service\LogsAccess::class)))->getForm()->getParams();
        $this->assertSame(['0.8.0', 3, 1, 4], [$params['version'], $params['eligibleUsers'], $params['connectedUsers'], $params['activeConnections']]);
    }

    public function testConnectedUsersCardExplainsItsDefinition(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        $this->assertStringContainsString("Eligible users who activated their connection.", $template);
    }

    public function testTemplateShowsTheWarningLinkAndOcrmypdfNote(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        $this->assertStringContainsString("\$_['ocrUrl']", $template);
        $this->assertStringContainsString('ocrmypdf', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);
    }
}
