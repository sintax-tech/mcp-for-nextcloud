<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The admin template's scripts and styles exist, and the removed user picker is gone. */
final class AdminAssetsTest extends TestCase {
    public function testReferencedScriptsAndStylesExist(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $template, $matches, PREG_SET_ORDER);
        $this->assertCount(2, $matches);
        foreach ($matches as [, $kind, $name]) {
            $file = $kind === 'Script' ? "$app/js/$name.js" : "$app/css/$name.css";
            $this->assertFileExists($file);
        }
        $this->assertFileDoesNotExist($app . '/js/admin-user-picker.js');
        $this->assertFileDoesNotExist($app . '/lib/Controller/UsersController.php');
    }

    public function testRoutesDropTheOldAdminFormsAndKeepOAuth(): void {
        $routes = array_column((require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'], 'name');
        foreach (['settings#global', 'settings#user', 'users#index'] as $gone) {
            $this->assertNotContains($gone, $routes);
        }
        foreach (['settings#personal', 'grants#index', 'grants#update', 'grants#bulk', 'grants#service', 'o_auth#token', 'metadata#protectedResource'] as $kept) {
            $this->assertContains($kept, $routes);
        }
    }
}
