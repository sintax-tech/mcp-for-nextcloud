<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Settings\AdminSection;
use OCA\Mcp\Settings\AdminSettings;
use OCA\Mcp\Settings\PersonalSection;
use OCA\Mcp\Settings\PersonalSettings;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\TestCase;

/** The app has its own "MCP for Nextcloud" entry with an icon in both settings menus, and renders only there. */
final class SettingsSectionTest extends TestCase {
    private const APP = __DIR__ . '/../../..';

    public function testInfoXmlRegistersBothSections(): void {
        $settings = simplexml_load_file(self::APP . '/appinfo/info.xml')->settings;
        $this->assertSame(AdminSection::class, (string)$settings->{'admin-section'});
        $this->assertSame(PersonalSection::class, (string)$settings->{'personal-section'});
        $this->assertSame(AdminSettings::class, (string)$settings->admin);
        $this->assertSame(PersonalSettings::class, (string)$settings->personal);
    }

    public function testSectionsShareIdNameAndIcon(): void {
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/$app/img/$image");
        foreach ([new AdminSection($urls), new PersonalSection($urls)] as $section) {
            $this->assertInstanceOf(IIconSection::class, $section);
            $this->assertSame('mcp', $section->getID());
            $this->assertSame('MCP for Nextcloud', $section->getName());
            $this->assertSame(75, $section->getPriority());
            $this->assertSame('/apps/mcp/img/app-dark.svg', $section->getIcon());
        }
    }

    public function testSettingsRenderIntoTheOwnSectionOnly(): void {
        $admin = (new \ReflectionClass(AdminSettings::class))->newInstanceWithoutConstructor();
        $personal = (new \ReflectionClass(PersonalSettings::class))->newInstanceWithoutConstructor();
        $this->assertSame(AdminSection::ID, $admin->getSection());
        $this->assertSame(PersonalSection::ID, $personal->getSection());
    }

    /** Both icons are monochrome SVGs: white for dark backgrounds, black for the settings menu. */
    public function testIconsAreValidMonochromeSvg(): void {
        foreach (['app.svg' => '#fff', 'app-dark.svg' => '#000'] as $file => $color) {
            $path = self::APP . '/img/' . $file;
            $this->assertFileExists($path);
            $svg = (string)file_get_contents($path);
            $this->assertNotFalse(simplexml_load_string($svg), $file);
            preg_match_all('/(?:fill|stroke)="(#[0-9a-f]+)"/i', $svg, $colors);
            $this->assertSame([$color], array_values(array_unique($colors[1])), $file);
        }
    }

    public function testPersonalFormRedirectsBackToTheOwnSection(): void {
        $source = (string)file_get_contents(self::APP . '/lib/Controller/SettingsController.php');
        $this->assertStringContainsString("['section' => PersonalSection::ID]", $source);
        $this->assertStringNotContainsString('personal-info', $source);
    }
}
