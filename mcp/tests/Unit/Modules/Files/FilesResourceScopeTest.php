<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages as CommonMessages;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages as FilesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * S1, S2, S3, S5, S6 and S11 on the Files tools (contract B.5 and the write-destination preflight F5): the tools of
 * alice through the Files gate, over the real FilesScope with a scope the test sets. Without a scope every tool answers
 * as before; with one, what lies outside is "not found" (D6), "/" lists the selected folders at their real paths,
 * searches filter before their results, and a write destination may only create folders at or below a selected
 * folder, deciding that before creating anything.
 *
 * Tree of alice: Documentos/ata.md (from the base), Projects/{plan.md, photo.png}, Other/{secret plan.md, other.png},
 * A/B/C, Archive/old.md.
 */
final class FilesResourceScopeTest extends FilesToolsTestCase {
    private const HIDDEN_TAG = '999';

    /** @var list<string>|null|false selected folders; null unrestricted; false unreadable */
    private array|null|false $items = null;
    /** Whether the Files gate has a scope at all: false is the guard of a user before B5. */
    private bool $withScope = true;
    /** @var array<string, list<string>> tags by file id */
    private array $tagged = [];

    protected function setUp(): void {
        parent::setUp();
        $this->rebuild();
    }

    /** The module again, with the Files gate over the current scope settings, on a fresh tree. */
    private function rebuild(): void {
        $tags = $this->createMock(ISystemTagObjectMapper::class);
        $tags->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
            $result = [];
            foreach ($ids as $id) {
                $result[(string)$id] = $this->tagged[(string)$id] ?? [];
            }
            return $result;
        });
        $config = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
        $plain = new VisibilityGuard($config->mock($this), $tags, $this->visibilityRoot());
        $scope = FilesScopes::over($this, $plain, $this->visibilityRoot(), fn (): array|null|false => $this->withScope ? $this->items : null);
        $this->visibilityGuard = new FilesAccessGuard($scope, $config->mock($this), $tags, $this->visibilityRoot());
        parent::setUp();
        $this->tree->addFile('/alice/files/Projects/plan.md', "# Plan\nscope", 'text/markdown');
        $this->tree->addFile('/alice/files/Projects/photo.png', 'png', 'image/png');
        $this->tree->addFile('/alice/files/Other/secret plan.md', "# Secret plan\nscope", 'text/markdown');
        $this->tree->addFile('/alice/files/Other/other.png', 'png', 'image/png');
        $this->tree->addFolder('/alice/files/A/B/C');
        $this->tree->addFile('/alice/files/Archive/old.md', 'old', 'text/markdown');
        $this->tree->ops = [];
    }

    private function id(string $path): string {
        return (string)$this->tree->nodes['/alice/files' . $path]['id'];
    }

    private function restrict(string ...$paths): void {
        $this->items = array_map(fn (string $path): string => $this->id($path), $paths);
    }

    /** @return list<string> paths of a list or search result */
    private static function paths(array $entries): array {
        return array_map(static fn (array $entry): string => $entry['path'], $entries);
    }

    private function planFailure(string $name, array $arguments): string {
        try {
            $this->plan($name, $arguments);
        } catch (ToolFailure $e) {
            return $e->getMessage();
        }
        $this->fail("$name planned");
    }

    /** @return array<string, mixed> what a battery of read tools and plans answers, for the snapshot comparison */
    private function answers(): array {
        $out = [];
        foreach ([['files_list', ['path' => '/']], ['files_list', ['path' => '/Projects']], ['files_tree', ['path' => '/']],
            ['files_search', ['query' => 'plan', 'mode' => 'name']], ['files_read', ['path' => '/Other/secret plan.md']],
            ['files_image_search', ['query' => 'png']], ['files_list_shares', []]] as [$name, $arguments]) {
            $out[] = $this->tool($name, $arguments);
        }
        foreach ([['files_mkdir', ['path' => '/Other/new']], ['files_copy', ['from' => '/Projects/plan.md', 'to' => '/Archive/plan.md']],
            ['files_move', ['from' => '/Other/other.png', 'to' => '/Projects/other.png']], ['files_create', ['path' => '/Other/n.md', 'content' => 'x']]] as [$name, $arguments]) {
            $out[] = $this->plan($name, $arguments);
        }
        return $out;
    }

    /** S1: an unrestricted user answers exactly as a user before the Files scope existed. */
    public function testS1WithoutAScopeEveryToolAnswersAsBefore(): void {
        $this->withScope = false;
        $this->rebuild();
        $before = $this->answers();
        $this->withScope = true;
        $this->items = null;
        $this->rebuild();
        $this->assertSame($before, $this->answers());
        $this->assertSame([], $this->tree->ops);
    }

    /** S2: restricted to /Projects, only /Projects is reachable, and "/" lists it at its real path. */
    public function testS2OnlyTheSelectedFolderIsReachable(): void {
        $this->restrict('/Projects');
        $this->assertSame(['/Projects'], self::paths($this->json('files_list', ['path' => '/'])));
        $this->assertSame(['/Projects/photo.png', '/Projects/plan.md'], self::paths($this->json('files_list', ['path' => '/Projects'])));
        $tree = $this->json('files_tree', ['path' => '/']);
        $this->assertSame(['/Projects', '/Projects/photo.png', '/Projects/plan.md'], self::paths($tree['entries']));
        $this->assertFalse($tree['truncated']);
        $this->assertStringContainsString('scope', $this->tool('files_read', ['path' => '/Projects/plan.md'])['content'][0]['text']);
        foreach ([['files_read', ['path' => '/Other/secret plan.md']], ['files_list', ['path' => '/Other']], ['files_list', ['path' => '/A']],
            ['files_tree', ['path' => '/Archive']], ['files_image_view', ['path' => '/Other/other.png']],
            ['files_versions_list', ['path' => '/Other/secret plan.md']], ['files_checkout', ['path' => '/Other/secret plan.md']]] as [$name, $arguments]) {
            $this->assertSame(CommonMessages::notFound(), $this->failure($name, $arguments), $name);
        }
        // Searches filter before the results: nothing from outside, and the limit counts only what is inside.
        $found = $this->json('files_search', ['query' => 'plan', 'mode' => 'name', 'limit' => 1]);
        $this->assertSame(['/Projects/plan.md'], self::paths($found['files']));
        $images = $this->json('files_image_search', ['query' => 'png']);
        $this->assertSame(['/Projects/photo.png'], array_map(static fn (array $image): string => $image['path'], $images));
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_image_search', ['folder' => '/Other']));
        $this->assertSame([], $this->tree->ops);
    }

    /** S3: a node inside the selected folder excluded by tag stays hidden: the exclusion wins. */
    public function testS3ExclusionWinsInsideTheScope(): void {
        $this->restrict('/Projects');
        $this->tagged[$this->id('/Projects/plan.md')] = [self::HIDDEN_TAG];
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_read', ['path' => '/Projects/plan.md']));
        $this->assertSame(['/Projects/photo.png'], self::paths($this->json('files_list', ['path' => '/Projects'])));
        $this->tagged = [$this->id('/Projects') => [self::HIDDEN_TAG]];
        $this->visibilityGuard->clearCache(); // as the tag assignment event does
        $this->assertSame([], $this->json('files_list', ['path' => '/']));
    }

    /** S5 positives: writes inside a selected folder, simple and nested, work without the ancestors becoming reachable. */
    public function testS5WritesAtOrBelowASelectedFolderWork(): void {
        $this->restrict('/Projects', '/A/B/C');
        $this->assertTrue($this->json('files_mkdir', ['path' => '/A/B/C/new/deeper'])['created']);
        $this->assertTrue($this->json('files_mkdir', ['path' => '/Projects/2026'])['created']);
        $batch = $this->plan('files_move_batch', ['moves' => [['from' => '/Projects/plan.md', 'to' => '/A/B/C/later/plan.md']], 'mkdirs' => ['/A/B/C/later']]);
        $this->assertSame([['path' => '/A/B/C/later', 'exists' => false, 'willCreate' => true]], $batch['mkdirs']);
        $this->json('files_copy', ['from' => '/Projects/plan.md', 'to' => '/A/B/C/plan.md']);
        $this->json('files_move', ['from' => '/Projects/photo.png', 'to' => '/Projects/2026/photo.png']);
        $this->json('files_create', ['path' => '/A/B/C/new/note.md', 'content' => 'hi']);
        $this->assertSame(['mkdir /alice/files/A/B/C/new', 'mkdir /alice/files/A/B/C/new/deeper', 'mkdir /alice/files/Projects/2026',
            'copy /alice/files/Projects/plan.md /alice/files/A/B/C/plan.md', 'move /alice/files/Projects/photo.png /alice/files/Projects/2026/photo.png',
            'create /alice/files/A/B/C/new/note.md'], array_values(array_filter($this->tree->ops, static fn (string $op): bool => !str_starts_with($op, 'write '))));
        // The ancestors stay out of reach: "/" lists the selected folders, /A and /A/B answer like missing ones.
        $this->assertSame(['/A/B/C', '/Projects'], self::paths($this->json('files_list', ['path' => '/'])));
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_list', ['path' => '/A']));
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_list', ['path' => '/A/B']));
    }

    /** S5 negatives: a destination outside the scope is refused like a missing one, before anything is created. */
    public function testS5DestinationsOutsideAreRefusedWithoutPartialEffect(): void {
        $this->restrict('/Projects', '/A/B/C');
        foreach ([['files_mkdir', ['path' => '/Other/x/y']], ['files_mkdir', ['path' => '/A/new']], ['files_mkdir', ['path' => '/Fresh/x']],
            ['files_copy', ['from' => '/Projects/plan.md', 'to' => '/Other/plan.md']], ['files_copy', ['from' => '/Projects/plan.md', 'to' => '/plan.md']],
            ['files_move', ['from' => '/Projects/plan.md', 'to' => '/Other/x/plan.md']], ['files_move', ['from' => '/Projects/plan.md', 'to' => '/A/plan.md']],
            ['files_move', ['from' => '/Other/other.png', 'to' => '/Projects/other.png']]] as [$name, $arguments]) {
            $this->assertSame(CommonMessages::notFound(), $this->planFailure($name, $arguments), "plan $name " . json_encode($arguments));
            $this->assertSame(CommonMessages::notFound(), $this->failure($name, $arguments + ['confirm_shared' => true]), "$name " . json_encode($arguments));
        }
        foreach (['/Other/new.md', '/Fresh/new.md', '/A/new.md'] as $path) {
            $this->assertSame(\OCA\Mcp\Modules\Files\Creation\CreationMessages::createFolderMissing(), $this->failure('files_create', ['path' => $path, 'content' => 'x']), $path);
            $this->assertSame(\OCA\Mcp\Modules\Files\Creation\CreationMessages::createFolderMissing(), $this->failure('files_upload', ['path' => $path]), $path);
        }
        $batch = ['moves' => [['from' => '/Projects/plan.md', 'to' => '/Other/z/plan.md']], 'mkdirs' => ['/Other/z']];
        $this->assertNotSame([], array_filter($this->plan('files_move_batch', $batch)['mkdirs'], static fn (array $dir): bool => isset($dir['reason'])));
        $this->failure('files_move_batch', $batch + ['confirm_shared' => true]);
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_version_restore', ['path' => '/Other/secret plan.md', 'version' => '1']));
        $this->assertSame([], $this->tree->ops, 'no folder, file or move on the way to a refusal');
        $this->assertArrayNotHasKey('/alice/files/Fresh', $this->tree->nodes);
    }

    /** S5: a file moved into the scope outside MCP is reachable from the next call on, by its id's new chain. */
    public function testS5AMoveIntoTheScopeOutsideMcpApplies(): void {
        $this->restrict('/Projects');
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_read', ['path' => '/Other/secret plan.md']));
        $this->tree->node('/alice/files/Other/secret plan.md')->move('/alice/files/Projects/secret plan.md');
        $this->visibilityGuard->clearCache();
        $this->assertStringContainsString('Secret', $this->tool('files_read', ['path' => '/Projects/secret plan.md'])['content'][0]['text']);
    }

    /** S6: restricted to nothing, no Files node is reachable and "/" lists nothing. */
    public function testS6RestrictedToNothingReachesNothing(): void {
        $this->items = [];
        $this->assertSame([], $this->json('files_list', ['path' => '/']));
        $this->assertSame([], $this->json('files_tree', ['path' => '/'])['entries']);
        $this->assertSame([], $this->json('files_search', ['query' => 'plan', 'mode' => 'name'])['files']);
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_read', ['path' => '/Projects/plan.md']));
        $this->assertSame(CommonMessages::notFound(), $this->failure('files_mkdir', ['path' => '/Projects/x']));
        $this->assertSame([], $this->tree->ops);
    }

    /** S11: a scope that cannot be read fails every Files tool and plan with 503, never a full or a narrowed view. */
    public function testS11AnUnreadableScopeFailsBeforeTheToolRuns(): void {
        $this->items = false;
        foreach (['call' => fn () => $this->tool('files_list', ['path' => '/']), 'search' => fn () => $this->tool('files_search', ['query' => 'plan']),
            'plan' => fn () => $this->plan('files_mkdir', ['path' => '/Projects/x']),
            // Answers without reaching a node, so only the read before the tool can tell it is unavailable.
            'undo of an unknown batch' => fn () => $this->tool('files_undo_batch', ['batch_id' => 424242])] as $label => $run) {
            try {
                $run();
                $this->fail("$label answered");
            } catch (PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->tree->ops);
    }

    /**
     * A1 of the B5 review (D6): a file or a folder outside the scope on the path of a batch folder answers exactly like
     * a missing path, in the plan and in the confirmed call; inside the scope the file in the way is still named.
     */
    public function testA1ABatchFolderPathNeverTellsWhatExistsOutsideTheScope(): void {
        $this->restrict('/Projects');
        $this->tree->addFolder('/alice/files/Other/sub');
        $answer = function (string $dir): array {
            $planned = $this->plan('files_move_batch', ['moves' => [['from' => '/Projects/plan.md', 'to' => '/Projects/kept.md']], 'mkdirs' => [$dir]]);
            $entry = $planned['mkdirs'][0];
            unset($entry['path']);
            $planned['mkdirs'] = [$entry];
            $planned['order'] = [];
            return ['plan' => json_encode($planned), 'confirm' => $this->failure('files_move_batch',
                ['moves' => [['from' => '/Projects/plan.md', 'to' => '/Projects/kept.md']], 'mkdirs' => [$dir], 'confirm_shared' => true])];
        };
        $missing = $answer('/Other/missing.md/x');
        $this->assertStringContainsString(json_encode(CommonMessages::notFound()), $missing['plan']);
        foreach (['/Other/secret plan.md/x', '/Other/secret plan.md', '/Other/sub/x', '/Other/sub', '/Other/other.png/x/y'] as $dir) {
            $this->assertSame($missing, $answer($dir), $dir);
        }
        $this->assertSame([], $this->tree->ops);
        // Inside the scope a file in the way is reachable, so it is named as before.
        $this->assertSame(FilesMessages::destinationExists(), $this->plan('files_move_batch',
            ['moves' => [['from' => '/Projects/plan.md', 'to' => '/Projects/kept.md']], 'mkdirs' => ['/Projects/plan.md/x']])['mkdirs'][0]['reason']);
    }

    /** A1: the folder helper answers a file outside the scope on the path like a missing level, and creates nothing. */
    public function testA1EnsureFolderNeverNamesAFileOutsideTheScope(): void {
        $this->restrict('/Projects');
        $root = $this->tree->rootFolder();
        $refusal = function (string $path) use ($root): string {
            try {
                \OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess::ensureFolder($root, $path, $this->visibilityGuard);
            } catch (ToolFailure $e) {
                return $e->getMessage();
            }
            return 'created';
        };
        $this->assertSame(CommonMessages::notFound(), $refusal('/Other/missing.md/x'));
        $this->assertSame($refusal('/Other/missing.md/x'), $refusal('/Other/secret plan.md/x'));
        $this->assertSame(CommonMessages::fileInTargetFolderPath(), $refusal('/Projects/plan.md/x'), 'inside the scope it is named');
        $this->assertSame([], $this->tree->ops);
    }

    /** A1 sweep: every other place that could say "it exists" checks the scope first (mkdir, copy, move, create, upload). */
    public function testA1NoOtherDestinationTellsWhatExistsOutsideTheScope(): void {
        $this->restrict('/Projects');
        foreach ([['files_mkdir', ['path' => '/Other/secret plan.md'], ['path' => '/Other/missing.md']],
            ['files_mkdir', ['path' => '/Other/secret plan.md/x'], ['path' => '/Other/missing.md/x']],
            ['files_copy', ['from' => '/Projects/plan.md', 'to' => '/Other/secret plan.md'], ['from' => '/Projects/plan.md', 'to' => '/Other/missing.md']],
            ['files_move', ['from' => '/Projects/plan.md', 'to' => '/Other/secret plan.md'], ['from' => '/Projects/plan.md', 'to' => '/Other/missing.md']],
            ['files_move', ['from' => '/Projects/plan.md', 'to' => '/Other/secret plan.md/x'], ['from' => '/Projects/plan.md', 'to' => '/Other/missing.md/x']],
            ['files_create', ['path' => '/Other/secret plan.md', 'content' => 'x'], ['path' => '/Other/missing.md', 'content' => 'x']],
            ['files_create', ['path' => '/Other/secret plan.md/x.md', 'content' => 'x'], ['path' => '/Other/missing.md/x.md', 'content' => 'x']],
            ['files_upload', ['path' => '/Other/secret plan.md'], ['path' => '/Other/missing.md']]] as [$name, $existing, $missing]) {
            $this->assertSame($this->planFailure($name, $missing), $this->planFailure($name, $existing), "plan $name " . json_encode($existing));
            $this->assertSame($this->failure($name, $missing + ['confirm_shared' => true]), $this->failure($name, $existing + ['confirm_shared' => true]), "$name " . json_encode($existing));
        }
        $this->assertSame([], $this->tree->ops);
    }
}
