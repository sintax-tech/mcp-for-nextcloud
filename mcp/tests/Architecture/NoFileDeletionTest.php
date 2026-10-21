<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;

use OCA\Mcp\Controller\CheckoutController;
use PHPUnit\Framework\TestCase;

/**
 * The rule the user set: the MCP deletes no file, by any route. The only removal it may ever do is of an
 * EMPTY FOLDER it created itself, inside files_undo_batch.
 *
 * Two ways to break that are checked here: a handler calling Node::delete() on something the user owns,
 * and a "cleanup" that quietly prunes the /MCP backups folder to save space. Both are cheap to write and
 * expensive to notice, so they are assertions rather than conventions.
 */
final class NoFileDeletionTest extends TestCase {
    /**
     * The one allowance the reorganization has, named explicitly so it cannot spread: files_undo_batch may
     * remove an empty folder the batch itself created, and nothing else. Until that tool lands there is no
     * allowance at all, so the Files module currently has to contain no removal of any kind.
     */
    private const ALLOWED_EMPTY_FOLDER_REMOVAL = 'lib/Modules/Files/Reorganization/BatchUndo.php';
    private const BATCH_UNDO = 'undoBatch';

    /**
     * @return list<string> every source file of the Files module
     */
    private function filesSources(): array {
        $found = [];
        foreach (['Modules/Files', 'Platform/FileAccess'] as $root) {
            $directory = new \RecursiveDirectoryIterator(__DIR__ . '/../../lib/' . $root, \FilesystemIterator::SKIP_DOTS);
            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $found[] = $file->getPathname();
                }
            }
        }
        $found[] = (string)(new \ReflectionClass(CheckoutController::class))->getFileName();
        $this->assertNotSame([], $found);
        return $found;
    }

    public function testFilesSourcesAreIncludedRecursively(): void {
        $sources = array_map('realpath', $this->filesSources());
        foreach (['Tools/ReadFile.php', 'Reading/FileReader.php', 'Tools/EditFile.php', 'Editing/TextFileEditor.php'] as $path) {
            self::assertContains(realpath(__DIR__ . '/../../lib/Modules/Files/' . $path), $sources);
        }
    }

    /**
     * A Files handler must never call Node::delete(). The single allowance is a call inside the batch undo,
     * and it has to sit there and nowhere else: a removal anywhere else in the module fails the test.
     */
    public function testNoFilesHandlerDeletesANode(): void {
        foreach ($this->filesSources() as $file) {
            $source = (string)file_get_contents($file);
            $allowed = realpath($file) === realpath(__DIR__ . '/../../' . self::ALLOWED_EMPTY_FOLDER_REMOVAL)
                && $this->undoHasTheRemoval($source);
            if ($allowed) {
                $this->assertSame(1, preg_match_all('/->\s*delete\s*\(\s*\)/', $source),
                    'a remoção do lote é uma só, dentro do desfazer');
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/->\s*delete\s*\(\s*\)/', $source,
                basename($file) . ' calls Node::delete(); the MCP removes no file and no folder');
        }
    }

    /** True only when every node deletion is inside the one permitted method body. */
    private function undoHasTheRemoval(string $source): bool {
        $tokens = \PhpToken::tokenize($source);
        foreach ($tokens as $i => $token) {
            if ($token->id !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && $tokens[$j]->isIgnorable()) {
                $j++;
            }
            if (($tokens[$j]->text ?? '') !== self::BATCH_UNDO) {
                continue;
            }
            while (isset($tokens[$j]) && $tokens[$j]->text !== '{') {
                $j++;
            }
            $start = $tokens[$j]->pos;
            $depth = 1;
            while (isset($tokens[++$j]) && $depth > 0) {
                $depth += $tokens[$j]->text === '{' ? 1 : ($tokens[$j]->text === '}' ? -1 : 0);
            }
            $end = $tokens[$j - 1]->pos;
            preg_match_all('/->\s*delete\s*\(\s*\)/', $source, $matches, PREG_OFFSET_CAPTURE);
            return count($matches[0]) === 1 && $matches[0][0][1] > $start && $matches[0][0][1] < $end;
        }
        return false;
    }

    public function testDeletionOutsideUndoIsRejected(): void {
        self::assertTrue($this->undoHasTheRemoval('<?php class Example { function undoBatch() { $folder->delete(); } }'));
        self::assertFalse($this->undoHasTheRemoval('<?php class Example { function before() { $folder->delete(); } function undoBatch() {} }'));
        self::assertFalse($this->undoHasTheRemoval('<?php class Example { function undoBatch() {} function after() { $folder->delete(); } }'));
        self::assertFalse($this->undoHasTheRemoval('<?php class Example { function undoBatch() { $folder->delete(); } function after() { $folder->delete(); } }'));
    }


    /**
     * unlink() is allowed only over a PHP temporary path, never over a Nextcloud node. Each occurrence has
     * to sit in a file whose temporary path comes from ITempManager.
     */
    public function testEveryUnlinkIsOverAPhpTemporaryPath(): void {
        $sources = $this->filesSources();
        $sources[] = (string)(new \ReflectionClass(CheckoutController::class))->getFileName();
        foreach (array_unique($sources) as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }
            $source = (string)file_get_contents($file);
            if (!str_contains($source, 'unlink(')) {
                continue;
            }
            $this->assertStringContainsString('getTemporaryFile(', $source,
                basename($file) . ' unlinks something but never creates a temporary file');
        }
    }

    /**
     * The /MCP backups folder only ever grows. A purge, a rotation or a retention window would delete
     * something the user may be relying on to recover an edit, so no source of Files may even name one.
     *
     * The rule is deliberately blunt and applies to every file in the namespace, with no exception for the
     * batch store: a row of the app's own table that expires is written with different words, so that this
     * ban keeps catching a future cleanup that would touch a file of the user.
     */
    public function testBackupsAreNeverPurgedNorRotated(): void {
        foreach ($this->filesSources() as $file) {
            $source = (string)file_get_contents($file);
            foreach (['purge', 'rotate', 'retention', 'prune', 'cleanup', 'cleanupBackup'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $source,
                    basename($file) . " mentions '$word'; backups are never cleaned up");
            }
        }
        $this->assertTrue(class_exists(\OCA\Mcp\Modules\Files\Editing\FileBackup::class));
    }

    /** The behaviour side: every write path of Files leaves the tree without a single deletion recorded. */
    public function testNoWritePathDeletesAnything(): void {
        $tree = new FakeTree($this);
        $tree->addFolder('/alice/files/Documentos');
        $tree->addFile('/alice/files/Documentos/ata.md', "# Ata\nolá", 'text/markdown');
        $tree->addFile('/alice/files/Documentos/pessoas.csv', "a;b\nJo\xE3o;S\xE3o\n", 'text/csv');
        $tree->addFile('/alice/files/Documentos/copia.csv', "a;b\n", 'text/csv');

        $backup = '/MCP backups/Documentos';
        foreach ([
            'edit' => static function () use ($tree): void {
                $tree->node('/alice/files/Documentos/ata.md')->putContent('novo');
            },
            'replace over bytes' => static function () use ($tree): void {
                $file = $tree->node('/alice/files/Documentos/pessoas.csv');
                $file->putContent(str_replace('Jo', 'José', (string)$file->getContent()));
            },
            'backup copy' => static function () use ($tree, $backup): void {
                $tree->node('/alice/files/Documentos/ata.md')->copy('/alice/files' . $backup . '/ata.bak');
            },
        ] as $label => $write) {
            $tree->ops = [];
            $write();
            $this->assertSame([], array_values(array_filter($tree->ops, static fn (string $op) => str_starts_with($op, 'delete'))),
                $label . ' deleted something');
        }
    }

    /** FakeTree itself records deletes, so an accidental one in any Files test shows up instead of passing silently. */
    public function testTheFakeTreeActuallyRecordsDeletions(): void {
        $tree = new FakeTree($this);
        $tree->addFile('/alice/files/x.md', 'x', 'text/markdown');
        $tree->node('/alice/files/x.md')->delete();
        $this->assertSame(['delete /alice/files/x.md'], $tree->ops);
    }
}
