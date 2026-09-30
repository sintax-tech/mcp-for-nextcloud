<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

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
    /** The only place a node may be removed, once the reorganization lands. */
    private const ALLOWED_EMPTY_FOLDER_REMOVAL = 'lib/Tools/Files/Reorganization.php';

    /**
     * @return list<string> every source file of the Files module
     */
    private function filesSources(): array {
        $found = glob(__DIR__ . '/../../../lib/Tools/Files/*.php') ?: [];
        $this->assertNotSame([], $found);
        return $found;
    }

    /** A Files handler must never call Node::delete(): there is no tool in this module that removes a node. */
    public function testNoFilesHandlerDeletesANode(): void {
        foreach ($this->filesSources() as $file) {
            $source = (string)file_get_contents($file);
            if (basename($file) === basename(self::ALLOWED_EMPTY_FOLDER_REMOVAL)) {
                // The reorganization is allowed to remove an empty folder it created; nothing else is.
                $this->assertDoesNotMatchRegularExpression('/->delete\(\)/', $source, basename($file));
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/->delete\(\)/', $source,
                basename($file) . ' calls Node::delete(); the MCP removes no file and no folder');
        }
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
     * something the user may be relying on to recover an edit, so no source may even name one.
     */
    public function testBackupsAreNeverPurgedNorRotated(): void {
        foreach ($this->filesSources() as $file) {
            $source = (string)file_get_contents($file);
            foreach (['purge', 'rotate', 'retention', 'prune', 'cleanup', 'cleanupBackup'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $source,
                    basename($file) . " mentions '$word'; backups are never cleaned up");
            }
        }
        $this->assertTrue(class_exists(\OCA\Mcp\Tools\Files\FileBackup::class));
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
