<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V5 (real, mandatory): groupfolders and local external storage provisioned in the cell. A tag on the root of a mount
 * or on a subfolder of it hides the mounted path; another alias of the same resource (a second mount of the same
 * directory, or a share of the groupfolder) does not get around it. What Nextcloud itself keeps apart, a narrower
 * mount of a subdirectory (a separate storage with its own file ids), is asserted where the tag is and recorded
 * where it is not.
 */
final class MountsTest extends ExclusionTestCase {
    public function testATagOnTheRootOfTheGroupfolderHidesEverythingInIt(): void {
        $this->makeFile('groupfolder', 'x.txt', 'in the groupfolder');
        $root = $this->userFolder(self::ALICE)->get($this->groupfolder());
        $this->assertReadable($this->mcp(), $this->path('groupfolder', 'x.txt'), 'in the groupfolder', 'before the tag');

        $this->tagUntilTheEnd($root);
        $this->exclude();
        $alice = $this->mcp();
        $this->assertHiddenFromRead($alice, $this->path('groupfolder', 'x.txt'), 'file in a groupfolder whose root is tagged');
        $this->assertNotContains($this->groupfolder(), $this->listNames($alice, '/') ?? [$this->groupfolder()], 'the groupfolder is not listed');
    }

    public function testATagOnASubfolderOfTheGroupfolderHidesOnlyThatSubtree(): void {
        $this->makeFile('groupfolder', 'locked/deep/x.txt', 'locked');
        $this->makeFile('groupfolder', 'open/y.txt', 'open');
        $this->tag($this->node(self::ALICE, $this->path('groupfolder', 'locked')));
        $this->exclude();
        $alice = $this->mcp();

        $this->assertHiddenFromRead($alice, $this->path('groupfolder', 'locked', 'deep', 'x.txt'), 'file under a tagged groupfolder subfolder');
        $this->assertReadable($alice, $this->path('groupfolder', 'open', 'y.txt'), 'open', 'sibling subfolder of the groupfolder');
    }

    public function testASubfolderOfTheGroupfolderSharedToAnotherUserKeepsTheProtectionOfItsAncestors(): void {
        $this->makeFile('groupfolder', 'open/inner/s.txt', 'shared out of the groupfolder');
        $shared = $this->node(self::ALICE, $this->path('groupfolder', 'open', 'inner'));
        $this->shareToUser($shared, self::ALICE, self::BOB);
        $this->exclude();
        $bob = $this->mcp(self::BOB);
        $this->assertReadable($bob, '/inner/s.txt', 'shared out of the groupfolder', 'before the tag');

        $this->tag($this->node(self::ALICE, $this->path('groupfolder', 'open')));
        $this->assertHiddenFromRead($bob, '/inner/s.txt', 'bob, through a share of a groupfolder subfolder whose ancestor is tagged');
    }

    public function testATagInsideAnExternalStorageHidesItThroughEveryMountOfTheSameDirectory(): void {
        $this->makeFile('external', 'open/ok.txt', 'visible');
        $this->makeFile('external', 'locked/secret.txt', 'secret');
        $this->tag($this->node(self::ALICE, $this->path('external', 'locked')));
        $this->exclude();
        $alice = $this->mcp();

        foreach (['external', 'alias'] as $arena) {
            $this->assertReadable($alice, $this->path($arena, 'open', 'ok.txt'), 'visible', "$arena, untagged sibling");
            $this->assertHiddenFromRead($alice, $this->path($arena, 'locked', 'secret.txt'), "$arena, file under the tagged folder");
            $this->assertNotContains('locked', $this->listNames($alice, $this->path($arena)) ?? ['locked'], "$arena does not list the tagged folder");
        }
    }

    public function testATagOnTheRootOfAnExternalMountHidesTheMountAndItsAlias(): void {
        $this->makeFile('external', 'f.txt', 'at the top of the external storage');
        $this->tagUntilTheEnd($this->userFolder(self::ALICE)->get(self::EXTERNAL));
        $this->exclude();
        $alice = $this->mcp();

        $this->assertHiddenFromRead($alice, $this->path('external', 'f.txt'), 'file under a tagged mount root');
        $this->assertHiddenFromRead($alice, $this->path('alias', 'f.txt'), 'the same file through the alias of the mount');
        $listing = $this->listNames($alice, '/') ?? [];
        $this->assertNotContains(self::EXTERNAL, $listing);
        $this->assertNotContains(self::ALIAS, $listing, 'the alias is the same storage root, so the same tag');
    }

    public function testANarrowMountOfASubdirectoryHonorsTagsInsideItAndOnItsRoot(): void {
        $this->makeFile('narrow', 'open/ok.txt', 'visible in the narrow mount');
        $this->makeFile('narrow', 'locked/secret.txt', 'secret in the narrow mount');
        $this->assertReadable($this->mcp(), $this->path('narrow', 'open', 'ok.txt'), 'visible in the narrow mount', 'narrow mount control');

        $this->tag($this->node(self::ALICE, $this->path('narrow', 'locked')));
        $this->exclude();
        $alice = $this->mcp();
        $this->assertHiddenFromRead($alice, $this->path('narrow', 'locked', 'secret.txt'), 'file under a tag inside the narrow mount');
        $this->assertReadable($alice, $this->path('narrow', 'open', 'ok.txt'), 'visible in the narrow mount', 'untagged sibling in the narrow mount');

        $this->tagUntilTheEnd($this->userFolder(self::ALICE)->get(self::NARROW));
        $this->assertHiddenFromRead($alice, $this->path('narrow', 'open', 'ok.txt'), 'file under the tagged root of the narrow mount');
        $this->assertNotContains(self::NARROW, $this->listNames($alice, '/') ?? [self::NARROW]);
    }

    /**
     * A narrower mount of a subdirectory is a separate storage in Nextcloud, so its files have file ids of their own
     * and a tag placed through the wide mount names other ids. This is what Nextcloud does, not something a tag
     * check can see across; the observation is recorded for the cell and nothing here asserts a bypass as expected.
     */
    public function testRecordsWhatHappensAcrossTheWideAndTheNarrowMountOfTheSameDirectory(): void {
        $this->makeFile('narrow', 'doc.txt', 'one file, two storages');
        $wide = self::EXTERNAL . '/Open/' . $this->run . '/doc.txt';
        try {
            $wideNode = $this->userFolder(self::ALICE)->get($wide);
        } catch (NotFoundException) {
            $this->observe('V5 narrow/wide: the wide mount does not list the file written through the narrow mount');
            $this->addToAssertionCount(1);
            return;
        }
        $narrowNode = $this->node(self::ALICE, $this->path('narrow', 'doc.txt'));
        $this->observe('V5 narrow/wide: file ids wide=' . $wideNode->getId() . ' narrow=' . $narrowNode->getId()
            . ($wideNode->getId() === $narrowNode->getId() ? ' (one storage)' : ' (separate storages)'));

        $this->tag($wideNode);
        $this->exclude();
        $alice = $this->mcp();
        $this->assertHiddenFromRead($alice, '/' . $wide, 'the file where the tag was placed (wide mount)');
        $viaNarrow = $alice->tool('files_read', ['path' => $this->path('narrow', 'doc.txt')]);
        $this->observe('V5 narrow/wide: tag placed through the wide mount, read through the narrow mount -> '
            . ($viaNarrow->ok() ? 'READABLE (separate storage, tag not applied)' : 'refused'));
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function writableMounts(): array {
        return ['groupfolder' => ['groupfolder'], 'external storage' => ['external'], 'external alias' => ['alias'], 'narrow mount' => ['narrow']];
    }

    /**
     * Overwriting a file copies it to the backup folder first, and that copy walks the ancestors of the file. With an
     * exclusion configured and nothing tagged, edit and replace must work in a groupfolder and in every external mount
     * and leave the backup of the previous content; a version of a groupfolder file can be restored.
     */
    #[DataProvider('writableMounts')]
    public function testEditAndReplaceWorkInAMountWhileAnExclusionIsConfiguredAndNothingIsTagged(string $arena): void {
        $this->makeFile($arena, 'doc.txt', 'original text');
        $this->makeFile('home', 'tagged-elsewhere.txt', 'x');
        $this->tag($this->node(self::ALICE, $this->path('home', 'tagged-elsewhere.txt')));
        $this->exclude();
        $alice = $this->mcp();
        $path = $this->path($arena, 'doc.txt');

        $edit = $alice->write('files_edit', ['path' => $path, 'content' => 'edited text']);
        $this->assertTrue($edit->ok(), "files_edit in $arena: " . $edit->describe());
        $this->assertReadable($alice, $path, 'edited text', "$arena after files_edit");
        $replace = $alice->write('files_replace', ['path' => $path, 'old' => 'edited', 'new' => 'replaced']);
        $this->assertTrue($replace->ok(), "files_replace in $arena: " . $replace->describe());
        $this->assertReadable($alice, $path, 'replaced text', "$arena after files_replace");
        $this->assertTrue($this->backupHolds('original text'), "$arena: the backup of the previous content exists");
    }

    public function testAVersionOfAGroupfolderFileCanBeRestoredWhileAnExclusionIsConfigured(): void {
        $this->makeFile('groupfolder', 'versioned.txt', 'first');
        $this->makeFile('home', 'tagged-elsewhere.txt', 'x');
        $this->tag($this->node(self::ALICE, $this->path('home', 'tagged-elsewhere.txt')));
        $this->exclude();
        $alice = $this->mcp();
        $path = $this->path('groupfolder', 'versioned.txt');
        // The versions come from writes made through the server's web endpoint, so no lock of this CLI process is
        // left on the file when it is restored.
        foreach (['second', 'third'] as $content) {
            // Versions are named by the second: writes in the same second would share one.
            sleep(2);
            $edit = $alice->write('files_edit', ['path' => $path, 'content' => $content]);
            $this->assertTrue($edit->ok(), "files_edit to $content in the groupfolder: " . $edit->describe());
        }

        $versions = $alice->tool('files_versions_list', ['path' => $path]);
        $this->assertTrue($versions->ok(), 'files_versions_list: ' . $versions->describe());
        // A version that differs from the current content, so the restore changes something observable.
        $revision = null;
        $expected = null;
        foreach ($versions->json()['versions'] ?? [] as $entry) {
            $stored = $alice->tool('files_version_read', ['path' => $path, 'version' => (string)$entry['revision']]);
            $text = $stored->ok() ? ($stored->json()['text'] ?? null) : null;
            $this->observe('groupfolder version ' . $entry['revision'] . ' reads as ' . json_encode($text));
            if (is_string($text) && $text !== 'third') {
                $revision = (string)$entry['revision'];
                $expected = $text;
                break;
            }
        }
        $this->assertIsString($revision, 'the groupfolder file has a version other than its current content: ' . $versions->describe());

        // Regression of the restore refused with "Resource locked by another operation" where files_lock is installed
        // (NC 34 and 35): the tool wrote the version over the file while the version's own stream was still open. It
        // must work at the first attempt, with no retry and no skip, on every cell.
        $restore = $alice->write('files_version_restore', ['path' => $path, 'version' => $revision]);
        $this->assertTrue($restore->ok(), 'files_version_restore in the groupfolder: ' . $restore->describe());
        $this->assertReadable($alice, $path, $expected, 'the file holds the restored version');
    }

    public function testRecordsWhetherAnExternalStorageKeepsVersions(): void {
        $file = $this->makeFile('external', 'versioned.txt', 'first');
        $file->putContent('second');
        $versions = $this->mcp()->tool('files_versions_list', ['path' => $this->path('external', 'versioned.txt')]);
        $this->observe('V7 restore: files_versions_list of a local external storage file after two writes -> '
            . substr(preg_replace('/\s+/', ' ', $versions->raw), 0, 240));
        $this->addToAssertionCount(1);
    }

    /** @return bool whether a backup file of the user's backup folder holds exactly this content */
    private function backupHolds(string $content): bool {
        $home = $this->userFolder(self::ALICE);
        if (!$home->nodeExists(BackupLocation::FOLDER)) {
            return false;
        }
        $pending = [$home->get(BackupLocation::FOLDER)];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof Folder) {
                array_push($pending, ...$node->getDirectoryListing());
            } elseif (str_ends_with($node->getName(), '.bak') && $node->getContent() === $content) {
                return true;
            }
        }
        return false;
    }

    private function tagUntilTheEnd(Node $node): void {
        $this->tag($node);
        $this->later(fn () => $this->untag($node));
    }
}
