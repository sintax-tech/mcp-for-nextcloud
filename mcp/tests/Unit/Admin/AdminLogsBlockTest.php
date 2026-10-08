<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Settings\AdminSettings;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** The "Server log" block says why the log tools are missing when the server does not write its log to a file. */
final class AdminLogsBlockTest extends TestCase {
    /** @return array<string, mixed> parameters of the admin template */
    private function params(bool $available, string $type): array {
        $apps = $this->createMock(IAppManager::class);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/mcp');
        $logs = $this->createMock(LogsAccess::class);
        $logs->method('available')->willReturn($available);
        $logs->method('logType')->willReturn($type);
        return (new AdminSettings($this->createMock(GrantPolicy::class), $urls, new OcrSupport($apps), $apps,
            $this->createMock(ConnectionList::class), $logs))->getForm()->getParams();
    }

    public function testTheTemplateKnowsWhetherTheLogIsAFileAndWhereItGoesInstead(): void {
        $this->assertSame([true, 'file'], [$this->params(true, 'file')['logsAvailable'], $this->params(true, 'file')['logType']]);
        $this->assertSame([false, 'syslog'], [$this->params(false, 'syslog')['logsAvailable'], $this->params(false, 'syslog')['logType']]);
    }

    public function testTheTemplateExplainsAMissingFileLog(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        $this->assertStringContainsString("if (!\$_['logsAvailable'])", $template);
        $this->assertStringContainsString('id="mcp-logs-groups"', $template);
    }
}
