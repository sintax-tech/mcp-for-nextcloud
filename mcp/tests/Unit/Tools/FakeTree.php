<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

/**
 * In-memory file tree whose nodes are PHPUnit mocks of the OCP File/Folder interfaces.
 * Every mutating call is recorded in $ops so tests can prove what was (not) written.
 */
final class FakeTree {
    /** @var array<string, array{type:string, content?:string, mime?:string, id:int, mtime:int, etag:string, readable:bool, updateable:bool, deletable:bool}> */
    public array $nodes = [];
    /** @var list<string> */
    public array $ops = [];
    /** Paths whose copy() must fail, or produce a truncated copy. */
    public array $failCopy = [];
    public array $shortCopy = [];
    public bool $failWrite = false;
    /** @var list<\OCP\Files\Search\ISearchQuery> queries received by Folder::search */
    public array $searches = [];
    private int $nextId = 100;

    public function __construct(private TestCase $test, public string $root = '/alice/files') {
        $this->addFolder($root);
    }

    public function addFolder(string $path, array $flags = []): void {
        $this->nodes[$path] = ['type' => 'dir', 'id' => $this->nextId++, 'mtime' => 1759200000, 'etag' => 'e' . $this->nextId] + $flags + self::defaults();
    }

    public function addFile(string $path, string $content, string $mime = 'text/plain', array $flags = []): int {
        $parent = dirname($path);
        if (!isset($this->nodes[$parent])) {
            $this->addFolder($parent);
        }
        $id = $this->nextId++;
        $this->nodes[$path] = ['type' => 'file', 'content' => $content, 'mime' => $mime, 'id' => $id, 'mtime' => 1759200000 + $id, 'etag' => 'e' . $id] + $flags + self::defaults();
        return $id;
    }

    public function rootFolder(): Folder {
        return $this->node($this->root);
    }

    public function node(string $path): Node {
        if (!isset($this->nodes[$path])) {
            throw new NotFoundException();
        }
        return $this->nodes[$path]['type'] === 'dir' ? $this->folder($path) : $this->file($path);
    }

    private static function defaults(): array {
        return ['readable' => true, 'updateable' => true, 'deletable' => true];
    }

    private function mock(string $interface): object {
        return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $interface);
    }

    private function common(object $mock, string $path): void {
        $mock->method('getPath')->willReturnCallback(fn () => $path);
        $mock->method('getName')->willReturn(basename($path));
        $mock->method('getId')->willReturnCallback(fn () => $this->nodes[$path]['id']);
        $mock->method('getMTime')->willReturnCallback(fn () => $this->nodes[$path]['mtime']);
        $mock->method('getEtag')->willReturnCallback(fn () => $this->nodes[$path]['etag'] ?? '');
        $mock->method('isReadable')->willReturnCallback(fn () => $this->nodes[$path]['readable']);
        $mock->method('isUpdateable')->willReturnCallback(fn () => $this->nodes[$path]['updateable']);
        $mock->method('isDeletable')->willReturnCallback(fn () => $this->nodes[$path]['deletable']);
        $mock->method('getStorage')->willReturnCallback(function () use ($path) {
            $storage = $this->mock(\OCP\Files\Storage\IStorage::class);
            $storage->method('instanceOfStorage')->willReturnCallback(fn (string $class) => $class === 'OCA\Files_Trashbin\Storage' && ($this->nodes[$path]['trash'] ?? true));
            return $storage;
        });
        $mock->method('getParent')->willReturnCallback(fn () => $this->node(dirname($path)));
        $mock->method('delete')->willReturnCallback(function () use ($path): void {
            $this->ops[] = "delete $path";
            unset($this->nodes[$path]);
        });
        $mock->method('move')->willReturnCallback(function (string $target) use ($path): Node {
            $this->ops[] = "move $path $target";
            $this->nodes[$target] = $this->nodes[$path];
            unset($this->nodes[$path]);
            return $this->node($target);
        });
        $mock->method('copy')->willReturnCallback(function (string $target) use ($path): Node {
            $this->ops[] = "copy $path $target";
            if (in_array($path, $this->failCopy, true)) {
                throw new NotPermittedException();
            }
            $this->nodes[$target] = ['id' => $this->nextId++] + $this->nodes[$path];
            if (in_array($path, $this->shortCopy, true)) {
                $this->nodes[$target]['content'] = '';
            }
            return $this->node($target);
        });
    }

    private function file(string $path): File {
        $file = $this->mock(File::class);
        $this->common($file, $path);
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getMimetype')->willReturnCallback(fn () => $this->nodes[$path]['mime']);
        $file->method('getSize')->willReturnCallback(fn () => $this->nodes[$path]['size'] ?? strlen($this->nodes[$path]['content']));
        $file->method('getContent')->willReturnCallback(fn () => $this->nodes[$path]['content']);
        $file->method('fopen')->willReturnCallback(function () use ($path) {
            $handle = fopen('php://memory', 'w+');
            fwrite($handle, $this->nodes[$path]['content']);
            rewind($handle);
            return $handle;
        });
        $file->method('putContent')->willReturnCallback(function (string $data) use ($path): void {
            $this->ops[] = "write $path";
            if ($this->failWrite) {
                throw new NotPermittedException();
            }
            $this->nodes[$path]['content'] = $data;
            $this->nodes[$path]['etag'] .= '+';
        });
        return $file;
    }

    private function folder(string $path): Folder {
        $folder = $this->mock(Folder::class);
        $this->common($folder, $path);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getMimetype')->willReturn('httpd/unix-directory');
        $folder->method('getSize')->willReturn(0);
        $folder->method('get')->willReturnCallback(fn (string $rel) => $this->node($path . '/' . ltrim($rel, '/')));
        $folder->method('nodeExists')->willReturnCallback(fn (string $rel) => isset($this->nodes[$path . '/' . ltrim($rel, '/')]));
        $folder->method('getDirectoryListing')->willReturnCallback(fn () => array_map(fn ($p) => $this->node($p), $this->children($path)));
        $folder->method('getRelativePath')->willReturnCallback(fn (string $abs) => $abs === $path ? '/' : (str_starts_with($abs, $path . '/') ? substr($abs, strlen($path)) : null));
        $folder->method('newFolder')->willReturnCallback(function (string $name) use ($path): Folder {
            $this->ops[] = "mkdir $path/$name";
            $this->addFolder("$path/$name");
            return $this->folder("$path/$name");
        });
        $folder->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($path): File {
            $this->ops[] = "create $path/$name";
            $this->addFile("$path/$name", (string)$content, 'text/markdown');
            return $this->file("$path/$name");
        });
        $folder->method('search')->willReturnCallback(function ($query) use ($path): array {
            $this->searches[] = $query;
            // Evaluates the escaped LIKE pattern like the database would, then applies the query limit.
            $regex = '/^' . preg_replace_callback('/\\\\(.)|%|_|[^%_\\\\]+/', static fn ($m) => match (true) {
                isset($m[1]) => preg_quote($m[1], '/'), $m[0] === '%' => '.*', $m[0] === '_' => '.', default => preg_quote($m[0], '/'),
            }, $query->getSearchOperation()->getValue()) . '$/iu';
            $matches = array_filter(array_keys($this->nodes), fn ($p) => str_starts_with($p, $path . '/') && preg_match($regex, basename($p)) === 1);
            return array_slice(array_values(array_map(fn ($p) => $this->node($p), $matches)), 0, $query->getLimit());
        });
        $folder->method('getById')->willReturnCallback(fn (int $id) => array_values(array_map(fn ($p) => $this->node($p),
            array_filter(array_keys($this->nodes), fn ($p) => str_starts_with($p, $path . '/') && $this->nodes[$p]['id'] === $id))));
        return $folder;
    }

    /** @return list<string> */
    private function children(string $path): array {
        return array_values(array_filter(array_keys($this->nodes), fn ($p) => $p !== $path && dirname($p) === $path));
    }
}
