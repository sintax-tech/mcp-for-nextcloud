<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

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
        $apps->method('isEnabledForAnyone')->with('workflow_ocr')->willReturn($active);
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('globalEnabled')->willReturn(true);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/mcp');
        return (new AdminSettings($policy, $urls, new OcrSupport($apps)))->getForm()->getParams();
    }

    public function testStatusFollowsTheApp(): void {
        $this->assertTrue($this->params(true)['ocrActive']);
        $inactive = $this->params(false);
        $this->assertFalse($inactive['ocrActive']);
        $this->assertSame('https://apps.nextcloud.com/apps/workflow_ocr', $inactive['ocrUrl']);
    }

    public function testTemplateShowsTheWarningLinkAndOcrmypdfNote(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        $this->assertStringContainsString("\$_['ocrUrl']", $template);
        $this->assertStringContainsString('ocrmypdf', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);
    }
}
