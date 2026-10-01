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
        $this->assertCount(3, $matches);
        foreach ($matches as [, $kind, $name]) {
            $file = $kind === 'Script' ? "$app/js/$name.js" : "$app/css/$name.css";
            $this->assertFileExists($file);
        }
        $this->assertFileDoesNotExist($app . '/js/admin-user-picker.js');
        $this->assertFileDoesNotExist($app . '/lib/Controller/UsersController.php');
    }

    /**
     * Every element id the admin script looks up appears exactly once, and the div tags balance, so
     * getElementById() never binds to a stray copy of a section (the hidden-tags block was once merged in three times).
     */
    public function testAdminTemplateHasUniqueIdsAndBalancedDivs(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $script = (string)file_get_contents($app . '/js/admin-grants.js') . file_get_contents($app . '/js/connections.js');
        preg_match_all("/getElementById\\('([^']+)'\\)/", $script, $used);
        $this->assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), "id $id must appear exactly once");
        }
        $this->assertSame(1, substr_count($template, "t('Hidden files & tags')"));
        $this->assertSame(preg_match_all('/<div\b/', $template), preg_match_all('/<\/div>/', $template), 'div tags must balance');
    }

    /**
     * The blocks follow the planned order (status, OAuth clients, hidden tags, OCR, permissions), and each
     * string of the OAuth clients section is translated.
     */
    public function testOauthClientsSectionIsPlacedAndTranslated(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $status = strpos($template, "t('Status')");
        $oauth = strpos($template, "t('OAuth clients')");
        $tags = strpos($template, "t('Hidden files & tags')");
        $ocr = strpos($template, "t('OCR')");
        $toolbar = strpos($template, 'class="mcp-toolbar"');
        $this->assertTrue($status < $oauth && $oauth < $tags && $tags < $ocr && $ocr < $toolbar);
        foreach (['mcp-oauth-hosts', 'mcp-oauth-hosts-status', 'mcp-native-client', 'mcp-native-client-status', 'mcp-native-client-details'] as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), $id);
        }
        $this->assertStringContainsString('data-copy="mcp-native-client-id"', $template);
        $section = substr($template, $oauth, $toolbar - $oauth);
        preg_match_all("/\\\$l->t\\('([^']+)'\\)/", $section, $fromTemplate);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $oauthScript = substr($script, (int)strpos($script, 'async function initOauthClients'));
        preg_match_all("/t\\('mcp', '([^']+)'/", $oauthScript, $fromScript);
        $strings = array_unique(array_merge($fromTemplate[1], $fromScript[1]));
        $this->assertGreaterThan(8, count($strings));
        foreach (['pt_BR', 'es'] as $lang) {
            $translations = json_decode((string)file_get_contents("$app/l10n/$lang.json"), true)['translations'];
            foreach ($strings as $text) {
                $this->assertArrayHasKey($text, $translations, "$lang: $text");
            }
        }
    }

    /** Six blocks, each a core settings section with its own heading, in the planned order. */
    public function testAdminPageHasSixSectionBlocks(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        preg_match_all('/<div class="section mcp-block" id="([^"]+)"[^>]*>\s*<h2><\?php p\(\$l->t\(\'([^\']+)\'\)\); \?><\/h2>/', $template, $blocks);
        $this->assertSame(['mcp-block-status', 'mcp-block-oauth', 'mcp-block-tags', 'mcp-block-ocr', 'mcp-block-matrix', 'mcp-connections'], $blocks[1]);
        $this->assertSame(['Status', 'OAuth clients', 'Hidden files & tags', 'OCR', 'Permissions', 'Active connections'], $blocks[2]);
        $this->assertStringContainsString('data-scope="admin"', $template);
    }

    /** The personal page loads the shared connections script, and every id that script looks up appears once. */
    public function testPersonalTemplateCarriesTheConnectionsBlock(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/personal.php');
        $this->assertStringContainsString("Util::addScript('mcp', 'connections')", $template);
        $this->assertStringContainsString('data-scope="personal"', $template);
        preg_match_all("/getElementById\\('([^']+)'\\)/", (string)file_get_contents($app . '/js/connections.js'), $used);
        // Admin-only elements: the search box and the Status card the revoke buttons refresh.
        foreach (array_diff(array_unique($used[1]), ['mcp-connections-search', 'mcp-count-connections']) as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), "id $id must appear exactly once");
        }
        $admin = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, substr_count($admin, 'id="mcp-count-connections"'), 'the admin Status card the script refreshes');
        $this->assertStringNotContainsString('mcp-count-connections', $template, 'the personal page has no Status card');
        $this->assertStringNotContainsString('mcp-connections-search', $template, 'the personal list has no user search');
        $this->assertSame(preg_match_all('/<div\b/', $template), preg_match_all('/<\/div>/', $template), 'div tags must balance');
    }

    /** Revoke buttons name their connection, an emptied last page steps back, and narrow screens have a layout. */
    public function testConnectionsScriptAndStylesCoverAccessibilityAndNarrowScreens(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/connections.js');
        foreach (["'Revoke {client} for {user}'", "'Revoke {client}'", "'Revoke all connections of {user}'"] as $label) {
            $this->assertStringContainsString($label, $script);
        }
        $this->assertStringContainsString('state.page > 1', $script);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertStringContainsString('@media (max-width: 768px)', $css);
        $narrow = substr($css, (int)strpos($css, '@media (max-width: 768px)'));
        $this->assertStringNotContainsString('#', preg_replace('/#mcp-admin/', '', $narrow), 'only theme variables and no fixed colors');
        $this->assertStringContainsString('overflow-x: auto', $narrow);
    }

    /** The checkout limit field repeats the controller's ceiling in its max attribute; the two must not drift apart. */
    public function testCheckoutLimitFieldMaxMatchesTheController(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, preg_match('/<input[^>]*id="mcp-checkout-limit"[^>]*>/', $template, $input));
        $this->assertSame(1, preg_match('/\bmax="(\d+)"/', $input[0], $max));
        $this->assertSame(\OCA\Mcp\Controller\CheckoutLimitController::MAX_MIB, (int)$max[1]);
        $this->assertStringContainsString('Math.max(1, Math.ceil(data.configuredBytes / 1048576))', (string)file_get_contents($app . '/js/admin-grants.js'));
    }

    public function testRoutesDropTheOldAdminFormsAndKeepOAuth(): void {
        $routes = array_column((require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'], 'name');
        foreach (['settings#global', 'settings#user', 'users#index'] as $gone) {
            $this->assertNotContains($gone, $routes);
        }
        foreach (['settings#personal', 'grants#index', 'grants#update', 'grants#bulk', 'grants#service', 'grants#oauthClients', 'grants#updateOauthClients', 'o_auth#token', 'metadata#protectedResource',
            'connections#index', 'connections#destroy', 'connections#revokeUser', 'my_connections#index', 'my_connections#destroy', 'checkout_limit#show', 'checkout_limit#update'] as $kept) {
            $this->assertContains($kept, $routes);
        }
    }

    /** Both checkout routes must exist, and the upload one has to answer PUT and POST. */
    public function testCheckoutRoutesExistAndAcceptPutAndPost(): void {
        $routes = (require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'];
        $checkout = array_values(array_filter($routes, static fn (array $route) => str_starts_with((string)$route['name'], 'checkout#')));
        $this->assertSame(['checkout#download', 'checkout#upload', 'checkout#uploadPost'],
            array_column($checkout, 'name'));
        $this->assertSame(['/checkout/{token}', '/checkout/{token}', '/checkout/{token}'], array_column($checkout, 'url'));
        $this->assertSame(['GET', 'PUT', 'POST'], array_column($checkout, 'verb'));
    }
}
