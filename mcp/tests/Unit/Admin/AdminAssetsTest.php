<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The admin template's scripts and styles exist, and the removed user picker is gone. */
final class AdminAssetsTest extends TestCase {
    /**
     * Front-end globals Nextcloud 34 and 35 removed (developer manual, "Upgrade to Nextcloud 34/35"). Authored modules and generated bundles
     * run on every declared major, so none of them may use these globals.
     */
    private const REMOVED_GLOBALS = [
        '/window\.McpAdminPicker\b/' => 'global picker facade',
        '/\bOC\.Notifications?\b/' => 'OC.Notification (34)',
        '/\bOC\.Dialogs\.fileexists\b/' => 'OC.Dialogs.fileexists (34)',
        '/\bOC\.Apps\b/' => 'OC.Apps (34)',
        '/\bOC\.\w*[mM]enu\w*\b/' => 'OC.*menu* (34)',
        '/\bOC\.Files\.Client\b/' => 'OC.Files.Client (34)',
        '/\bsnapper\b/' => 'snapper (34)',
        '/\bjQuery\b|(?<![\w.])\$\s*\(/' => 'jQuery (34)',
        '/\bBackbone\b/' => 'Backbone (34)',
        '/\bHandlebars\b/' => 'Handlebars (34)',
        '/\boc_(?:appswebroots|config|current_user|debug|defaults|isadmin|requesttoken|webroot)\b/' => 'oc_* aliases (35)',
        '/\bOCDialogs\b/' => 'OCDialogs (35)',
        '/(?<![\w.$])_\.\w/' => 'underscore (35)',
        '/\bClipboard(?:JS)?\b/' => 'Clipboard (35)',
        '/(?<![\w.])dav\./' => 'dav (35)',
        '/(?<![\w.])moment\b/' => 'moment (35)',
    ];

    /** @return list<string> All authored browser modules, recursively. */
    private function sourceFiles(): array {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    public function testScriptsAndTemplatesUseNoFrontEndGlobalRemovedUpToNextcloud35(): void {
        $app = dirname(__DIR__, 3);
        $files = [...$this->sourceFiles(), ...glob($app . '/js/*.js') ?: [], ...glob($app . '/templates/*.php') ?: []];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            foreach (self::REMOVED_GLOBALS as $pattern => $global) {
                $this->assertSame(0, preg_match($pattern, $source, $match), basename($file) . " uses $global: " . ($match[0] ?? ''));
            }
        }
    }


    public function testReferencedScriptsAndStylesExist(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        preg_match_all("/Util::add(Script|Style)\\(\\s*'mcp'\\s*,\\s*'([^']+)'/", $template, $matches, PREG_SET_ORDER);
        $this->assertCount(2, $matches);
        foreach ($matches as [, $kind, $name]) {
            $file = $kind === 'Script' ? "$app/js/$name.js" : "$app/css/$name.css";
            $this->assertFileExists($file);
        }
        foreach (['admin-grants', 'admin-tabs', 'admin-picker', 'connections', 'admin-user-picker'] as $retired) {
            $this->assertFileDoesNotExist("$app/js/$retired.js");
        }
        $this->assertFileDoesNotExist($app . '/lib/Controller/UsersController.php');
    }

    /**
     * Every element id the admin scripts look up appears exactly once, and the div tags balance, so
     * getElementById() never binds to a stray copy of a section (the hidden-tags block was once merged in three times).
     */
    public function testAdminTemplateHasUniqueIdsAndBalancedDivs(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        preg_match_all('/\bid="([^"]+)"/', $template, $ids);
        $this->assertNotEmpty($ids[1]);
        // Conditional PHP branches may repeat a literal ID; the rendered-page browser gate checks uniqueness.
        // Dynamic level/drawer controls are owned by their modules. Rendered browser scenarios
        // verify selector bindings and unique IDs; a source regex cannot distinguish generated IDs.
        $this->assertSame(1, substr_count($template, "t('Excluded from MCP access')"));
        $this->assertSame(preg_match_all('/<div\b/', $template), preg_match_all('/<\/div>/', $template), 'div tags must balance');
    }

    /**
     * The OAuth clients block opens the Clients and connections tab (before the Active connections
     * block), and each string of the section is translated.
     */
    public function testOauthClientsSectionIsPlacedAndTranslated(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $start = strpos($template, 'id="mcp-block-oauth"');
        $end = strpos($template, 'id="mcp-connections"');
        $this->assertIsInt($start, 'the OAuth clients block');
        $this->assertIsInt($end, 'the active connections block');
        $this->assertTrue($start < $end, 'the OAuth clients block opens the Clients and connections tab');
        foreach (['mcp-oauth-hosts', 'mcp-oauth-hosts-status', 'mcp-native-client', 'mcp-native-client-status', 'mcp-native-client-details'] as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), $id);
        }
        $this->assertStringContainsString('data-copy="mcp-native-client-id"', $template);
        $section = substr($template, $start, $end - $start);
        preg_match_all("/\\\$l->t\\('([^']+)'\\)/", $section, $fromTemplate);
        $oauthScript = (string)file_get_contents($app . '/src/admin/clients/oauth-clients.js');
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

    /**
     * Five WAI-ARIA tabs in the planned order (overview, access, clients, protection, advanced), each
     * a mapped URL hash, each bound to a role=tabpanel that names the tab back, the panels wrapping
     * the named section blocks in that order, and none of them hidden: without JavaScript the whole
     * page stays visible. The tabs script is loaded by the template.
     */
    public function testAdminPageHasAccessibleTabsMappedToUrlHashes(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');

        $this->assertStringContainsString("Util::addScript('mcp', 'admin')", $template);
        $this->assertSame(2, substr_count($template, 'role="tablist"'));
        $this->assertStringContainsString('id="mcp-scope-tabs" class="mcp-drawer-tabs" role="tablist"', $template);
        preg_match_all('/<button type="button" role="tab" id="(mcp-tab-[a-z]+)" data-tab-hash="([a-z]+)" aria-controls="(mcp-panel-[a-z]+)" aria-selected="(true|false)">/', $template, $tabs, PREG_SET_ORDER);
        $this->assertCount(5, $tabs);
        $this->assertSame(['overview', 'access', 'clients', 'protection', 'advanced'], array_map(static fn (array $tab) => $tab[2], $tabs));
        foreach ($tabs as [, $tabId, $hash]) {
            $this->assertSame($hash, substr($tabId, strlen('mcp-tab-')), "tab $tabId must carry its own name as the hash");
        }
        $this->assertSame('true', $tabs[0][4]);
        foreach (array_slice($tabs, 1) as $tab) {
            $this->assertSame('false', $tab[4], 'only the first tab starts selected');
        }

        $panels = [];
        preg_match_all('/<div role="tabpanel" id="(mcp-panel-[a-z]+)" aria-labelledby="(mcp-tab-[a-z]+)" class="mcp-tab-panel" tabindex="0">/', $template, $panelMatches, PREG_SET_ORDER);
        foreach ($panelMatches as $panel) {
            $panels[$panel[1]] = $panel[2];
        }
        $this->assertCount(5, $panels);
        foreach ($tabs as [, $tabId, , $panelId]) {
            $this->assertSame($tabId, $panels[$panelId] ?? null, "panel $panelId must name its tab");
        }
        $this->assertSame(0, preg_match('/class="mcp-tab-panel"[^>]*\shidden\b/', $template), 'no panel ships hidden: without JS the page must not be empty');

        // The panels wrap their blocks in the tab order; ids and classes of the blocks stay as they were.
        $ordered = [
            'role="tablist"',
            'id="mcp-panel-overview"', 'id="mcp-block-status"',
            'id="mcp-panel-access"', 'id="mcp-block-matrix"',
            'id="mcp-panel-clients"', 'id="mcp-block-oauth"', 'id="mcp-connections"',
            'id="mcp-panel-protection"', 'id="mcp-block-tags"',
            'id="mcp-panel-advanced"', 'id="mcp-block-logs"', 'id="mcp-block-logs-privacy"', 'id="mcp-block-ocr"',
            'class="mcp-attribution"',
        ];
        $positions = array_map(static fn (string $needle) => strpos($template, $needle), $ordered);
        $this->assertSame(false, in_array(false, $positions, true), 'every tab, panel and block must exist');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'tabs, panels and blocks must appear in the tab order');
    }

    /** Nine inline sections plus two resource/inspection drawers, each with its own heading. */
    public function testAdminPageHasNineInlineSectionsAndTwoDrawers(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        preg_match_all('/<div class="section mcp-block(?: mcp-matrix-block)?" id="([^"]+)"[^>]*>\s*<h2><\?php p\(\$l->t\(\'([^\']+)\'\)\); \?><\/h2>/', $template, $blocks);
        $this->assertSame(['mcp-block-status', 'mcp-group-editor', 'mcp-block-matrix', 'mcp-block-oauth', 'mcp-connections', 'mcp-block-tags', 'mcp-block-logs', 'mcp-block-logs-privacy', 'mcp-block-ocr'], $blocks[1]);
        $this->assertSame(['Status', 'Group grants', 'Permissions', 'OAuth clients', 'Active connections', 'Excluded from MCP access', 'Server log', 'Log privacy', 'OCR'], $blocks[2]);
        foreach (['mcp-scope' => 'Resource scope', 'mcp-inspector' => 'Access inspector'] as $id => $heading) {
            $this->assertStringContainsString('class="section mcp-block mcp-drawer" id="' . $id . '"', $template);
            $this->assertStringContainsString("t('" . $heading . "')", $template);
        }
        $this->assertStringContainsString('data-scope="admin"', $template);
    }

    /** The catalog-driven app selector is labelled and does not alter server-side user filters. */
    public function testMatrixAppFilterIsLabelledAndOnlyFiltersColumns(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertStringContainsString('<label for="mcp-app">', $template);
        $this->assertStringContainsString('<select id="mcp-app" disabled>', $template);

    }

    /** The three permission states are drawn in one place and the bulk actions confirm, report and undo with the same endpoint. */
    public function testMatrixStatesAndBulkUndoStayInTheExistingEndpoints(): void {
        $app = dirname(__DIR__, 3);
        $this->assertStringContainsString('id="mcp-bulk-notice-text" tabindex="-1"', (string)file_get_contents($app . '/templates/admin.php'));
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertGreaterThan(strpos($template, 'class="mcp-matrix-wrap"'), strpos($template, 'class="mcp-legend"'), 'the legend sits under the table');
        $this->assertGreaterThan(strpos($template, 'class="mcp-matrix-wrap"'), strpos($template, 'id="mcp-bulk-notice"'), 'the notice sits under the table');
        $this->assertStringContainsString('<option value="eligible" selected>', $template);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertDoesNotMatchRegularExpression('/\\.mcp-bulk-notice \\{[^}]*position: sticky/s', $css, 'the undo notice never covers a row');
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertStringContainsString('id="mcp-bulk-notice" role="status" aria-live="polite"', $template);
        $this->assertStringContainsString('id="mcp-bulk-undo" hidden', $template);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertStringContainsString('.mcp-state-unavailable', $css);
        $this->assertDoesNotMatchRegularExpression('/#mcp-admin \.mcp-(state|bulk-notice|sr-only)[^{]*\{[^}]*#[0-9a-fA-F]{3,6}\b/', $css);
    }

    /** Names, header rows and their corner have separate stacking layers; desktop legacy has no gap. */
    public function testMatrixStickyLayersAndToggleClearanceAreScoped(): void {
        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/admin.css');
        $this->assertMatchesRegularExpression('/\.mcp-matrix \.mcp-user-col \{[^}]*position: sticky;[^}]*inset-inline-start: 44px;[^}]*z-index: 1;/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix thead th \{[^}]*position: sticky;[^}]*top: 0;[^}]*z-index: 2;/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix thead \.mcp-user-col \{[^}]*z-index: 3;/s', $css);
        $this->assertStringContainsString('#app-content-vue #mcp-admin .mcp-tab-bar', $css);
        $this->assertStringContainsString('#app-content-vue .settings-content #mcp-admin .mcp-tab-bar', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 1024px\) \{\s*#app-content #mcp-admin \.mcp-tab-bar/', $css);
    }

    /** The personal page loads the shared connections script, and every id that script looks up appears once. */
    public function testPersonalTemplateCarriesTheConnectionsBlock(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/personal.php');
        $this->assertStringContainsString("Util::addScript('mcp', 'personal')", $template);
        $this->assertStringContainsString('data-scope="personal"', $template);
        preg_match_all("/querySelector\\('(#?[^']+)'\\)/", (string)file_get_contents($app . '/src/settings/connections.js'), $used);
        $used[1] = array_map(static fn (string $id): string => ltrim($id, '#'), $used[1]);
        $used[1] = array_values(array_filter($used[1], static fn (string $id): bool => str_starts_with($id, 'mcp-')));
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


    /**
     * Log privacy keeps its native checkbox, id and saving; the "Learn more" button ships
     * hidden and the detail ships visible, so without JavaScript nothing of the page is lost.
     */
    public function testLogPrivacyHasItsOwnBlockWithAWiredSwitchAndLearnMore(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, substr_count($template, 'id="mcp-block-logs-privacy"'));
        $this->assertSame(1, substr_count($template, 'class="checkbox" id="mcp-logs-hide-file-names"'), 'the native Nextcloud checkbox');
        $this->assertSame(1, preg_match('/<button type="button" class="mcp-learn-more" id="mcp-logs-privacy-more" aria-expanded="false" aria-controls="mcp-logs-privacy-detail" hidden>/', $template));
        $this->assertSame(1, substr_count($template, 'id="mcp-logs-privacy-detail" class="mcp-learn-more-box"'), 'the detail ships visible for the no-JS page');
        $this->assertSame(1, substr_count($template, 'class="mcp-picker-summary mcp-hint" id="mcp-logs-summary"'));

    }


    /**
     * Real containment, not just order: each block id's ancestor with role=tabpanel must be the panel
     * of its tab, so a block can never leak out of its tab and show on every one.
     */
    public function testEveryBlockLivesInsideItsOwnTabPanel(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        // The panel/block structure is plain HTML; the PHP in the template only echoes texts.
        $html = preg_replace('/<\?php.*?\?>/s', '', $template);
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $loaded = $doc->loadHTML($html);
        } finally {
            libxml_use_internal_errors($previous);
        }
        $this->assertTrue($loaded);
        $blocks = [
            'mcp-panel-overview' => ['mcp-block-status'],
            'mcp-panel-access' => ['mcp-block-matrix'],
            'mcp-panel-clients' => ['mcp-block-oauth', 'mcp-connections'],
            'mcp-panel-protection' => ['mcp-block-tags'],
            'mcp-panel-advanced' => ['mcp-block-logs', 'mcp-block-logs-privacy', 'mcp-block-ocr'],
        ];
        foreach ($blocks as $panelId => $blockIds) {
            foreach ($blockIds as $blockId) {
                $block = $doc->getElementById($blockId);
                $this->assertNotNull($block, $blockId);
                $ancestor = $block->parentNode;
                while ($ancestor instanceof \DOMElement && $ancestor->getAttribute('role') !== 'tabpanel') {
                    $ancestor = $ancestor->parentNode;
                }
                $this->assertInstanceOf(\DOMElement::class, $ancestor, "$blockId must live inside a tab panel");
                $this->assertSame($panelId, $ancestor->getAttribute('id'), "$blockId must live inside $panelId");
            }
        }
    }

    /**
     * The unreadable exclusion setting refuses every file, so the sign of it cannot stay inside a
     * hidden tab panel: the Status block of Overview shows the same alert with a link to the
     * protection tab, the tab carries a marker, and the script toggles both.
     */
    public function testUnreadableTagsSettingIsSignalledInOverviewAndOnTheTab(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertSame(1, preg_match('/<div id="mcp-tags-invalid-overview" class="mcp-tags-warning" role="alert" hidden>/', $template), 'the Overview alert starts hidden and is announced when shown');
        $this->assertSame(1, substr_count($template, '<a href="#protection">'), 'the alert links to the Data protection tab');
        $this->assertSame(1, substr_count($template, '<span id="mcp-tab-protection-alert" class="mcp-tab-alert" hidden>'), 'the tab marker');
        // The Overview alert repeats the repair sentence of the protection block.
        $this->assertSame(2, substr_count($template, "t('The saved exclusion tags setting cannot be read"), 'the same text on both alerts');

    }
    public function testThemeColourMarksOnlyFocusAndSelection(): void {
        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/admin.css');
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string)$rules, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);
        foreach ($matches as [, $selector, $body]) {
            $selector = trim($selector);
            if (str_contains($body, '--color-primary-element')) {
                $this->assertMatchesRegularExpression('/:focus-visible|\[aria-(selected|current|pressed)="true"\]/', $selector,
                    "only focus and selection (selected, current, pressed) use the theme colour: $selector");
            }
            // A -text variable may fall back to its background colour on themes that lack it; a border may use the
            // background colour, as NcNoteCard does, since the word next to it carries the state.
            $text = preg_replace('/var\(--color-(error|warning|success)-text, var\(--color-\1\)\)/', '', $body);
            $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(color|outline(-color)?)\s*:[^;]*var\(--color-(error|warning|success)\)/', (string)$text,
                "semantic colours as text or outline take their -text variant: $selector");
            $this->assertStringNotContainsString('border-radius: var(--border-radius-large', $body, "--border-radius-large is deprecated: $selector");
        }
    }
}
