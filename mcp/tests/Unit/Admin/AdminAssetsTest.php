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
     * Front-end globals Nextcloud 34 and 35 removed (developer manual, "Upgrade to Nextcloud 34/35"). The scripts have
     * no build step and run on every declared major, so none of them may appear.
     */
    private const REMOVED_GLOBALS = [
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

    public function testScriptsAndTemplatesUseNoFrontEndGlobalRemovedUpToNextcloud35(): void {
        $app = dirname(__DIR__, 3);
        $files = [...glob($app . '/js/*.js') ?: [], ...glob($app . '/templates/*.php') ?: []];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            foreach (self::REMOVED_GLOBALS as $pattern => $global) {
                $this->assertSame(0, preg_match($pattern, $source, $match), basename($file) . " uses $global: " . ($match[0] ?? ''));
            }
        }
    }

    /**
     * OCP.Toast still exists up to 35 but is deprecated: when it is missing the error must still reach the user through
     * something that is no Nextcloud global, never be dropped.
     */
    public function testErrorsReachTheUserEvenWithoutTheToastGlobal(): void {
        $app = dirname(__DIR__, 3);
        foreach (['connections', 'admin-grants'] as $script) {
            $source = (string)file_get_contents("$app/js/$script.js");
            $this->assertSame(1, preg_match('/function notifyError\(message\) \{(.*?)\n\t\}/s', $source, $body), $script);
            $this->assertStringContainsString('OCP.Toast.error(message)', $body[1], $script);
            $this->assertMatchesRegularExpression('/\} else \{/', $body[1], "$script: no unconditional fallback after OCP.Toast");
            $this->assertMatchesRegularExpression('/textContent = message|window\.alert\(message\)/', $body[1], "$script: the fallback must show the message");
        }
    }

    public function testReferencedScriptsAndStylesExist(): void {
        AdminAssetContract::verify();
    }

    /**
     * Every element id the admin scripts look up appears exactly once, and the div tags balance, so
     * getElementById() never binds to a stray copy of a section (the hidden-tags block was once merged in three times).
     */
    public function testAdminTemplateHasUniqueIdsAndBalancedDivs(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $script = (string)file_get_contents($app . '/js/admin-grants.js') . file_get_contents($app . '/js/connections.js') . file_get_contents($app . '/js/admin-tabs.js') . file_get_contents($app . '/js/admin-picker.js') . file_get_contents($app . '/js/admin-groups.js');
        preg_match_all("/getElementById\\('([^']+)'\\)/", $script, $used);
        $this->assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), "id $id must appear exactly once");
        }
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
        foreach (['mcp-oauth-hosts', 'mcp-oauth-hosts-status', 'mcp-native-client', 'mcp-native-client-status', 'mcp-native-client-details',
            'mcp-custom-clients', 'mcp-custom-add'] as $id) {
            $this->assertSame(1, substr_count($template, 'id="' . $id . '"'), $id);
        }
        $this->assertStringContainsString('data-copy="mcp-native-client-id"', $template);
        $section = substr($template, $start, $end - $start);
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

    /**
     * Five WAI-ARIA tabs in the planned order (overview, access, clients, protection, advanced), each
     * a mapped URL hash, each bound to a role=tabpanel that names the tab back, the panels wrapping
     * the named section blocks in that order, and none of them hidden: without JavaScript the whole
     * page stays visible. The tabs script is loaded by the template.
     */
    public function testAdminPageHasAccessibleTabsMappedToUrlHashes(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');

        $this->assertStringContainsString("Util::addScript('mcp', 'admin-tabs')", $template);
        // One tab bar for the page; the resource scope drawer has its own tablist for its modules (S9).
        $this->assertSame(1, substr_count($template, 'class="mcp-tab-bar" role="tablist"'));
        $this->assertSame(2, substr_count($template, 'role="tablist"'));
        $this->assertStringContainsString('<div id="mcp-scope-tabs" class="mcp-drawer-tabs" role="tablist"', $template);
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
            'id="mcp-panel-access"', 'id="mcp-block-matrix"', 'id="mcp-inspector"',
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

    /** Ten blocks, each a core settings section with its own heading, in the tab order. */
    /** The matrix draws "Restricted to N" through js/admin-scope.js and reloads after a scope is saved (B7). */
    public function testTheMatrixShowsTheResourceScopeOfARestrictedModule(): void {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/js/admin-grants.js');
        $this->assertStringContainsString("window.McpScopeUi.indicator(user.uid, module, user.scope[module])", $script);
        $this->assertStringContainsString("window.addEventListener('mcp-scope-saved', reloadLocked)", $script);
    }

    public function testAdminPageHasTenSectionBlocks(): void {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin.php');
        // A block may be a drawer (S9), whose heading sits in the drawer head next to its close button, or the matrix,
        // whose bands above the table are compact (S12).
        preg_match_all('/<div class="section mcp-block(?: mcp-drawer| mcp-matrix-block)?" id="([^"]+)"[^>]*>\s*(?:<div class="mcp-drawer-head">\s*)?<h2(?: id="[a-z-]+" tabindex="-1")?><\?php p\(\$l->t\(\'([^\']+)\'\)\); \?><\/h2>/', $template, $blocks);
        $this->assertSame(['mcp-block-status', 'mcp-group-editor', 'mcp-block-matrix', 'mcp-scope', 'mcp-inspector', 'mcp-block-oauth', 'mcp-connections', 'mcp-block-tags', 'mcp-block-logs', 'mcp-block-logs-privacy', 'mcp-block-ocr'], $blocks[1]);
        $this->assertSame(['Status', 'Group grants', 'Permissions', 'Resource scope', 'Access inspector', 'OAuth clients', 'Active connections', 'Excluded from MCP access', 'Server log', 'Log privacy', 'OCR'], $blocks[2]);
        $this->assertStringContainsString('data-scope="admin"', $template);
    }

    /** The catalog-driven app selector is labelled and does not alter server-side user filters. */
    public function testMatrixAppFilterIsLabelledAndOnlyFiltersColumns(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertStringContainsString('<label for="mcp-app">', $template);
        $this->assertStringContainsString('<select id="mcp-app" disabled>', $template);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringContainsString('Object.keys(state.data.catalog)', $script);
        $this->assertStringContainsString('header.dataset.module = module', $script);
        $this->assertStringContainsString('cell.dataset.module = module', $script);
        $this->assertStringContainsString("params.set('app', state.app)", $script);
        $this->assertStringContainsString("new URLSearchParams({ search: state.search, group: state.group, filter: state.filter, page: String(state.page) })", $script);
        $this->assertStringContainsString("{ module, operation, granted: null }", $script);
        $tabs = (string)file_get_contents($app . '/js/admin-tabs.js');
        $this->assertStringContainsString("const hash = fragment.split('?')[0]", $tabs);
        $this->assertStringContainsString('tabQueries.set(hash, fragment.slice(hash.length))', $tabs);
    }

    /** The three permission states are drawn in one place and the bulk actions confirm, report and undo with the same endpoint. */
    public function testMatrixStatesAndBulkUndoStayInTheExistingEndpoints(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringContainsString('const PERMISSION_STATES = {', $script);
        foreach (['allowed', 'denied', 'unavailable'] as $state) {
            $this->assertStringContainsString("$state: { className: 'mcp-state-$state'", $script);
        }
        $this->assertStringContainsString("textContent: checked === null ? '—' : '⊘'", $script);
        $this->assertStringContainsString("mark.setAttribute('aria-hidden', 'true')", $script);
        // None of the three states is told by colour alone: allowed is a checked box, denied an empty box, and an
        // unavailable cell has no box at all but the ⊘ mark, with the state named in its title and for screen readers.
        $this->assertStringContainsString("const box = el('input', { type: 'checkbox', checked })", $script);
        $this->assertStringContainsString("paintState(cell, checked ? 'allowed' : 'denied')", $script);
        $this->assertStringContainsString("cell.title = detail ? PERMISSION_STATES[key].name() + ': ' + detail : PERMISSION_STATES[key].name()", $script);
        $this->assertStringContainsString("cell.append(mark, el('span', { className: 'mcp-sr-only'", $script);
        $legend = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertStringContainsString('<input type="checkbox" checked disabled aria-hidden="true" tabindex="-1"> <?php p($l->t(\'Allowed\')); ?>', $legend);
        $this->assertStringContainsString('<input type="checkbox" disabled aria-hidden="true" tabindex="-1"> <?php p($l->t(\'Denied\')); ?>', $legend);
        $this->assertStringContainsString('<span class="mcp-state-mark" aria-hidden="true">⊘</span> <?php p($l->t(\'Unavailable\')); ?>', $legend);
        $css = (string)file_get_contents($app . '/css/admin.css');
        foreach (['allowed', 'denied'] as $state) {
            $this->assertDoesNotMatchRegularExpression('/\.mcp-state-' . $state . '\s*\{[^}]*color/s', $css, "$state is told by the box, not by a colour");
        }
        // The levels view keeps the rule: the level is a word, the origin an icon next to it, and an unavailable or
        // unknown module the same mark as above, a word and no control.
        $this->assertStringContainsString("el('span', { className: 'mcp-level-word', textContent: levelName })", $script);
        $this->assertStringContainsString("if (mark === 'exception') return 'icon-user'", $script);
        $this->assertStringContainsString("return origin.source === 'group' || origin.source === 'unknown' ? 'icon-group' : null", $script);
        $this->assertStringContainsString("glyph.setAttribute('aria-hidden', 'true')", $script);
        $this->assertStringContainsString("textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable')", $script);
        $this->assertStringContainsString("cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only'", $script);
        $this->assertStringContainsString('<span class="mcp-origin-icon icon-user" aria-hidden="true"></span> <?php p($l->t(\'Exception: differs from what the person inherits\')); ?>', $legend);
        $this->assertStringContainsString('<span class="mcp-origin-icon icon-group" aria-hidden="true"></span> <?php p($l->t(\'From a group\')); ?>', $legend);
        // A bulk change is one all-or-nothing command, undone by its server receipt, never by replaying old values.
        $this->assertSame(1, substr_count($script, "api('POST', '/api/grants/commands'"), 'the bulk menu sends one command');
        $this->assertSame(1, substr_count($script, "api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision })"));
        $this->assertStringNotContainsString("'/api/grants/bulk'", $script);
        $this->assertStringContainsString('async function undoBulk(', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
        // Selection, user, "Can connect" and the row's actions (S5: the actions menu replaced the "Connected" column).
        $this->assertStringContainsString('const FIXED_COLUMNS = 4', $script);
        $this->assertDoesNotMatchRegularExpression('/\\b3 \\+ |cells\\.length \\+ 3/', $script, 'fixed columns come from the constant');
        $this->assertStringContainsString('window.history.replaceState(', $script);
        $this->assertStringContainsString('noticeText.focus({ preventScroll: true })', $script);
        $this->assertStringContainsString('function setBulkBusy(on)', $script);
        $this->assertStringContainsString('if (!bulkBusy && !notice.matches(\':hover\')', $script, 'the undo countdown waits for the lock');
        $this->assertSame(2, preg_match_all('/setBulkBusy\(true\)\s+try \{/', $script), 'bulk and undo both run under the lock');
        $this->assertSame(2, preg_match_all('/\} finally \{\s+setBulkBusy\(false\)/', $script), 'the lock is released on failure too');
        $this->assertStringContainsString('id="mcp-bulk-notice-text" tabindex="-1"', (string)file_get_contents($app . '/templates/admin.php'));
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertGreaterThan(strpos($template, 'class="mcp-matrix-wrap"'), strpos($template, 'class="mcp-legend"'), 'the legend sits under the table');
        $this->assertGreaterThan(strpos($template, 'class="mcp-matrix-wrap"'), strpos($template, 'id="mcp-bulk-notice"'), 'the notice sits under the table');
        $this->assertStringContainsString('<option value="eligible" selected>', $template);
        $this->assertStringContainsString("filter: 'eligible'", $script);
        $this->assertStringContainsString('function rowUnavailableReason(user)', $script);
        $this->assertStringContainsString("td.mcp-row-unavailable", $script);
        $this->assertStringContainsString('the account is enabled and “Can connect” is on', $script);
        $this->assertStringContainsString("freshCell.querySelector('input')?.focus()", $script);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertDoesNotMatchRegularExpression('/\\.mcp-bulk-notice \\{[^}]*position: sticky/s', $css, 'the undo notice never covers a row');
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertStringContainsString('id="mcp-bulk-notice" role="status" aria-live="polite"', $template);
        $this->assertStringContainsString('id="mcp-bulk-undo" hidden', $template);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertStringContainsString('.mcp-state-unavailable', $css);
        $this->assertDoesNotMatchRegularExpression('/#mcp-admin \.mcp-(state|bulk-notice|sr-only)[^{]*\{[^}]*#[0-9a-fA-F]{3,6}\b/', $css);
    }

    /**
     * The theme colour marks only focus, selection and the main action, never a state: an administrator may pick a red
     * theme, and a state painted in it reads as an error. Semantic colours are text only through their -text variants
     * (Nextcloud 35 documents --color-error, --color-warning and --color-success as background colours).
     */
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

    /**
     * Taking "Can connect" away signs the person out of every MCP client, so neither view does it without an explicit yes:
     * the checkbox of the operations view asks before saving, and the level popover asks before its command.
     */
    public function testCanConnectIsNeverTakenAwayWithoutConfirmation(): void {
        $app = dirname(__DIR__, 3);
        $grants = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertMatchesRegularExpression("/if \(module === null && !value && !window\.confirm\(t\('mcp', 'Turn off “Can connect” for \{user\}\?'.*?\)\) \{\s*box\.checked = true\s*return\s*\}/s", $grants);
        $this->assertLessThan(strpos($grants, "api('PUT', '/api/grants/' + encodeURIComponent(user.uid)"), strpos($grants, "Turn off “Can connect” for {user}?"), 'asked before the save');
        $popover = (string)file_get_contents($app . '/js/admin-level-popover.js');
        $this->assertMatchesRegularExpression("/if \(losesEligibility\(.*?&& !window\.confirm\(.*?\) \{\s*render\(\)\s*return\s*\}/s", $popover);
        $this->assertLessThan(strpos($popover, "matrix.api('POST', '/api/grants/commands'"), strpos($popover, 'if (losesEligibility('), 'asked before the command');
        $template = (string)file_get_contents($app . '/templates/admin.php');
        $this->assertStringContainsString('<div id="mcp-level-popover" class="mcp-popover" role="dialog" aria-labelledby="mcp-level-popover-title" hidden></div>', $template);
    }

    /**
     * S5: the matrix keeps the explanation in the legend and the row's actions in one menu. No long text above the table,
     * no compact-rows switch, no pager above it, and no button repeated in every row or cell.
     */
    public function testMatrixRowsCarryOneMenuAndNoRepeatedControls(): void {
        $app = dirname(__DIR__, 3);
        $template = (string)file_get_contents($app . '/templates/admin.php');
        foreach (['Compact rows', 'Users need “Can connect”', 'id="mcp-prev"', 'id="mcp-next"', 'id="mcp-page-info"'] as $gone) {
            $this->assertStringNotContainsString($gone, $template);
        }
        $this->assertStringContainsString('<div id="mcp-row-menu" class="mcp-row-menu" role="menu" hidden></div>', $template);
        $this->assertStringContainsString('id="mcp-only-exceptions" aria-pressed="false"', $template);
        $this->assertStringContainsString('id="mcp-prev-bottom"', $template);
        $this->assertGreaterThan(strpos($template, 'id="mcp-legend-levels"'), strpos($template, "Reading starts allowed, writing denied. Nextcloud permissions still apply."), 'the short help is in the legend');
        $overrides = (string)file_get_contents($app . '/js/admin-overrides.js');
        foreach (['mcp-reset-row', 'mcp-reset-cell', 'mcp-reset-help'] as $gone) {
            $this->assertStringNotContainsString($gone, $overrides);
        }
        $grants = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringNotContainsString('mcp-badge-connected', $grants);
        $this->assertStringNotContainsString("className: 'mcp-connected'", $grants);
        $this->assertStringContainsString("row.append(el('td', { className: 'mcp-select-col' }))", $grants);
        $this->assertStringContainsString('row.append(actionsCell(user))', $grants);
        // S6: the "All" menus of the column headers gave way to the selection bar, whose bulk change keeps the explicit
        // confirmation of 331bdede (who changes, who is left out and why, who already has it) and the receipt undo.
        $this->assertStringNotContainsString('function bulkMenu(', $grants);
        $this->assertStringNotContainsString('mcp-bulk-menu', $grants . (string)file_get_contents($app . '/css/admin.css'));
        $this->assertStringContainsString("async function bulk(module, level, uids) {", $grants);
        $this->assertStringContainsString("'%n selected person is left out: this permission is unavailable or unknown for them.'", $grants);
        $this->assertStringContainsString("'%n selected person already has it.'", $grants);
        $this->assertStringContainsString("matrix.bulk(moduleChoice.value || null, levelChoice.value, uids)", $overrides);
        $this->assertStringContainsString('window.McpAdminOverrides?.decorateHeader?.(selectHeader)', $grants);
    }

    /**
     * S9/S10: the heading of a drawer starts at the start side (Nextcloud 35 centres a section heading as a flex box), and
     * an unrestricted module's tab says "no restriction" in words that fit every module, whatever its grammatical gender.
     */
    public function testDrawerHeadingStartsAtTheStartAndTabsNameNoRestriction(): void {
        $app = dirname(__DIR__, 3);
        $css = (string)file_get_contents($app . '/css/admin.css');
        $this->assertMatchesRegularExpression('/#mcp-admin \.mcp-drawer-head h2 \{[^}]*justify-content: flex-start;[^}]*text-align: start;/s', $css);
        $l10n = json_decode((string)file_get_contents($app . '/l10n/pt_BR.json'), true)['translations'];
        $this->assertSame('{module}: sem restrição', $l10n['{module}: all']);
        $this->assertSame('{module}: sin restricción', json_decode((string)file_get_contents($app . '/l10n/es.json'), true)['translations']['{module}: all']);
        $this->assertSame('{module}: uneingeschränkt', json_decode((string)file_get_contents($app . '/l10n/de.json'), true)['translations']['{module}: all']);
    }

    /**
     * Names, header rows and their corner have separate stacking layers; desktop legacy has no gap. The selection box sticks
     * at the start, the name right after its fixed width, and the "…" menu at the end, so a row scrolled sideways can
     * still be selected, named and acted on (S12).
     */
    public function testMatrixStickyLayersAndToggleClearanceAreScoped(): void {
        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/admin.css');
        $this->assertMatchesRegularExpression('/\.mcp-matrix \.mcp-select-col \{[^}]*position: sticky;[^}]*inset-inline-start: 0;[^}]*z-index: 1;[^}]*width: 44px;[^}]*min-width: 44px;[^}]*max-width: 44px;/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix \.mcp-user-col \{[^}]*position: sticky;[^}]*inset-inline-start: 44px;[^}]*z-index: 1;/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix \.mcp-actions-col \{[^}]*position: sticky;[^}]*inset-inline-end: 0;[^}]*z-index: 1;/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix thead \.mcp-select-col,\s*#mcp-admin \.mcp-matrix thead \.mcp-actions-col \{[^}]*z-index: 3;/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix-wrap \{[^}]*position: relative;[^}]*overflow: auto;[^}]*max-width: 100%;/s', $css, 'the matrix scrolls inside its block, never the page');
        // On a phone a long name wraps between words, never down to one letter per line, and a drawer that reaches the
        // start of the page leaves the corner of the Nextcloud navigation toggle free.
        $this->assertMatchesRegularExpression('/\.mcp-matrix \.mcp-user-col \{[^}]*min-width: 6\.5em;[^}]*max-width: 38vw;[^}]*white-space: normal;[^}]*overflow-wrap: break-word;/s', $css);
        $this->assertStringNotContainsString('overflow-wrap: anywhere', $css);
        // On a phone only the name stays fixed, so enough of the row is left to show the cell that has the focus.
        $this->assertMatchesRegularExpression('/@media \(max-width: 768px\) \{.*#mcp-admin \.mcp-matrix \.mcp-select-col,\s*#mcp-admin \.mcp-matrix \.mcp-actions-col \{\s*position: static;\s*\}\s*#mcp-admin \.mcp-matrix \.mcp-user-col \{\s*inset-inline-start: 0;\s*\}/s', $css);
        $this->assertMatchesRegularExpression('/\.mcp-matrix-block \.mcp-toolbar > \.mcp-legend,\s*#mcp-admin \.mcp-matrix-block \.mcp-toolbar > \.mcp-pager \{[^}]*flex-basis: 100%;/s', $css, 'the legend and the pager under the table take a row each');
        $this->assertMatchesRegularExpression('/\.mcp-matrix-block \.mcp-pager \{[^}]*flex-wrap: wrap;/s', $css, 'the pager wraps instead of pushing "Next" past the edge');
        $this->assertMatchesRegularExpression('/@media \(max-width: 560px\) \{\s*#mcp-admin \.mcp-drawer\.mcp-drawer-open \.mcp-drawer-head \{[^}]*padding-inline-start: calc\(var\(--default-clickable-area\) \+ 4px\);/s', $css);
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

    /**
     * The groups that may read the server log are saved one change at a time: the picker is locked
     * while a save runs, the save carries the list it changed, a 409 reloads the list and says so,
     * and a deleted group is shown so it can be removed.
     */
    /**
     * Every save names the revision the page was built from, as the string the server sent, and a 409/428 or an
     * uncertain 503 reloads the saved state instead of repeating the write (contracts §2.3, minimal page change).
     */
    public function testEverySaveCarriesTheRevisionAndAConflictReloadsInsteadOfRetrying(): void {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/js/admin-grants.js');
        $this->assertDoesNotMatchRegularExpression('/(Number|parseInt|parseFloat|BigInt)\([^)]*[rR]evision/', $script, 'kept as the server\'s string');
        foreach ([
            "{ ...payload, expectedRevision: sent }",
            "{ expectedRevision: sent, commands }",
            "{ enabled: value, expectedRevision: sent }",
            "version: version, expectedRevision: sent",
            "{ receiptId, expectedRevision: savedRevision }",
        ] as $body) {
            $this->assertStringContainsString($body, $script);
        }
        // The revision sent is the one on screen (T14 D1: kept apart only to announce the move the save made).
        $this->assertSame(3, substr_count($script, 'const sent = state.data.revision'), 'cell, bulk command and service');
        $this->assertStringContainsString('const sent = logsRevision', $script);
        $this->assertStringContainsString('const sent = oauthRevision', $script);
        $this->assertStringContainsString("if (e.message === '409' || e.message === '428') {", $script);
        $this->assertStringContainsString("e.message === '503' && e.data && e.data.uncertain", $script);
    }

    /**
     * Review F1, scenario 1: the matrix revision only comes from the matrix on screen. api() adopts nothing (no error
     * body, no logs-access answer, no load that lost the race); the matrix reads state.data.revision, set together
     * with the rendered page or by a matrix save that committed from it; the log block keeps its own.
     */
    public function testTheMatrixRevisionComesOnlyFromTheMatrixOnScreen(): void {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/js/admin-grants.js');
        $api = substr($script, (int)strpos($script, 'async function api('), 700);
        $this->assertStringNotContainsString('revision', $api, 'api() never adopts a revision, from success or error');
        $this->assertDoesNotMatchRegularExpression('/(?<![.\w])revision\s*=/', $script, 'no module-wide revision variable');
        $this->assertSame(1, preg_match_all('/state\.data = data\b/', $script), 'the page and its revision are set together, once');
        $this->assertMatchesRegularExpression('/if \(seq !== loadSeq\) \{\s+return\s+\}\s+state\.data = data/', $script, 'an older load is dropped');
        $this->assertSame(['state.data.revision = updated.revision', 'state.data.revision = result.revision'],
            array_values(array_unique(preg_match_all('/state\.data\.revision = \w+\.revision/', $script, $m) ? $m[0] : [])),
            'only a committed matrix save advances it');
        $logs = substr($script, (int)strpos($script, 'async function initLogsAccess'));
        $this->assertStringContainsString('logsRevision = data.revision', $logs);
        $this->assertStringNotContainsString('state.data.revision', $logs);
    }

    /**
     * Review F1, scenarios 2 and 3: after a conflict or an uncertain save the reload is awaited with matrix writes
     * blocked, so no checkbox, service switch or All menu can save the outdated page in between; the All menu plans
     * on the table on screen only.
     */
    public function testAConflictBlocksMatrixWritesUntilTheReloadedPageIsOnScreen(): void {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/js/admin-grants.js');
        $this->assertMatchesRegularExpression('/async function reloadLocked\(\) \{\s+matrixLocked = true\s+try \{\s+await load\(\)\s+\} finally \{\s+matrixLocked = false/', $script);
        $policyError = substr($script, (int)strpos($script, 'async function policyError(e)'), 900);
        $this->assertStringContainsString('await reloadLocked()', $policyError);
        $this->assertStringNotContainsString("\t\tload()", $policyError);
        $this->assertSame(3, substr_count($script, 'await policyError(e)'), 'cell, All menu and service wait for the reload');
        $undo = substr($script, (int)strpos($script, 'async function undoBulk('), 2000);
        $this->assertStringContainsString('await reloadLocked()', $undo, 'an undo, refused or not, reloads with writes blocked');
        $cell = substr($script, (int)strpos($script, "box.addEventListener('change'"), 600);
        $this->assertMatchesRegularExpression('/if \(matrixLocked\) \{\s+\/\/[^\n]*\n\s+box\.checked = !value\s+return/', $cell);
        $service = substr($script, (int)strpos($script, "serviceBox.addEventListener('change'"), 300);
        $this->assertStringContainsString('if (matrixLocked || state.data === null) {', $service);
        $bulk = substr($script, (int)strpos($script, 'async function bulk('));
        $this->assertLessThan(strpos($bulk, 'bulkPlan('), strpos($bulk, 'if (bulkBusy || matrixLocked) {'), 'the plan is made after the lock check');
    }

    public function testTheLogGroupsAreSavedOneAtATimeWithTheListTheyChanged(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $logs = substr($script, (int)strpos($script, 'async function initLogsAccess'));
        $this->assertStringContainsString('groups: nextIds, previous: previousIds, version: version, expectedRevision: sent', $logs);
        $this->assertStringContainsString("version = data.version", $logs);
        $this->assertStringContainsString("e.message === '409'", $logs);
        $this->assertStringContainsString("t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.')", $logs);
        $this->assertStringContainsString("t('mcp', '{group} (group removed)'", $logs);
        // One save at a time, with the control locked, is the picker's contract.
        $picker = (string)file_get_contents($app . '/js/admin-picker.js');
        $this->assertMatchesRegularExpression('/if \(busy \|\| \(fallbackText !== null && !force\)\) \{\s*return/', $picker);
        $this->assertStringContainsString("setAttribute('aria-busy', locked ? 'true' : 'false')", $picker);
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
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringContainsString("more.hidden = false", $script);
        $this->assertStringContainsString("more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')", $script);
    }

    /** The empty-tags repair bypasses only fallback, retaining confirmation, locking and saving. */
    public function testUnreadableTagsCanBeRepairedWithoutAnySystemTags(): void {
        $app = dirname(__DIR__, 3);
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $picker = (string)file_get_contents($app . '/js/admin-picker.js');
        $this->assertStringContainsString('await picker.commit({ force: true })', $script);
        $this->assertStringContainsString('commit({ force = false } = {})', $picker);
        $this->assertStringContainsString('return commit(new Set(selected), force)', $picker);
        $this->assertStringContainsString('if (busy || (fallbackText !== null && !force))', $picker);
        $this->assertMatchesRegularExpression('/if \(spec.confirmSave && !\(await spec.confirmSave\(Array.from\(next\)\)\)\) \{\s*return/', $picker);
        $this->assertStringContainsString("confirmSave: (nextIds) => nextIds.length > 0", $script);
        $this->assertStringContainsString("await api('PUT', '/api/admin/tags', { tagIds })", $script);
        $this->assertStringContainsString('setInvalidState(false)', $script);
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
            'mcp-panel-access' => ['mcp-group-editor', 'mcp-block-matrix', 'mcp-inspector'],
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
        $script = (string)file_get_contents($app . '/js/admin-grants.js');
        $this->assertStringContainsString("document.getElementById('mcp-tags-invalid-overview')", $script);
        $this->assertStringContainsString("document.getElementById('mcp-tab-protection-alert')", $script);
        $this->assertStringContainsString('overviewAlert.hidden = !isInvalid', $script);
        $this->assertStringContainsString('tabAlert.hidden = !isInvalid', $script);
    }
}