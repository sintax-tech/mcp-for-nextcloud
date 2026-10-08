<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Settings\AdminSettings;
use OCA\Mcp\Tools\Files\OcrSupport;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * An optional app in a version MCP does not support is hidden like a missing one; the admin page is where the
 * administrator learns why its tools are gone and which version brings them back.
 */
final class AdminUnsupportedAppsTest extends TestCase {
    /** The admin notice; its placeholders are the app name, the installed version and the minimum. */
    private const NOTICE = '%1$s %2$s is installed, but MCP for Nextcloud supports it from version %3$s on. Its tools stay hidden until the app is updated; the saved permissions are kept.';

    /**
     * @param array<string, string> $versions installed version per app id
     * @param list<string> $enabled apps enabled for anyone
     * @return array<string, mixed> parameters of the admin template
     */
    private function params(array $versions, array $enabled): array {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $versions[$app] ?? '0');
        $apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => in_array($app, $enabled, true));
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/mcp');
        $connections = $this->createMock(ConnectionList::class);
        return (new AdminSettings($this->createMock(GrantPolicy::class), $urls, new OcrSupport($apps), $apps, $connections, $this->createMock(\OCA\Mcp\Service\LogsAccess::class)))->getForm()->getParams();
    }

    public function testAnOldTalkIsListedWithItsProductNameAndBothVersions(): void {
        $params = $this->params(['spreed' => '21.0.4', 'deck' => '1.15.0'], ['spreed', 'deck']);

        $this->assertSame([['name' => 'Talk', 'installed' => '21.0.4', 'required' => '21.1.4']], $params['unsupportedApps']);
    }

    public function testNothingIsListedWhenEveryOptionalAppIsSupportedOrOff(): void {
        $this->assertSame([], $this->params(['spreed' => '22.0.0', 'deck' => '1.16.0'], ['spreed', 'deck'])['unsupportedApps']);
        $this->assertSame([], $this->params(['spreed' => '21.0.4'], [])['unsupportedApps']);
    }

    public function testEveryAppWithAMinimumHasAProductName(): void {
        foreach (array_keys(\OCA\Mcp\Service\Compat\AppEnablement::MINIMUM_VERSIONS) as $app) {
            $this->assertArrayHasKey($app, AdminSettings::APP_NAMES, $app . ' needs the name the admin knows it by');
        }
    }

    public function testTheTemplateShowsTheNoticeInThePermissionsBlock(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        $this->assertStringContainsString("\$_['unsupportedApps']", $template);
        $this->assertStringContainsString(self::NOTICE, $template);
        $this->assertLessThan(strpos($template, self::NOTICE), strpos($template, 'id="mcp-block-matrix"'));
    }
}
