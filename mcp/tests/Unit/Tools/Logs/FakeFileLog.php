<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCP\Log\IFileBased;
use OCP\Log\IWriter;

/**
 * The file writer of the core as getEntries() behaves in stable33 (lib/private/Log/File.php): lines read from the
 * end, each decoded with json_decode, kept when its level is at or above the `loglevel` setting, `offset` of those
 * skipped and at most `limit` returned. A malformed line decodes to null, and null passes a loglevel of 0.
 */
final class FakeFileLog implements IWriter, IFileBased {
    /** @var list<array{int, int}> every getEntries call: limit, offset */
    public array $calls = [];
    /** Lines appended to the file after each getEntries call, as a busy server keeps writing. */
    public int $growth = 0;

    /** @param list<string> $lines the file, oldest line first */
    public function __construct(public array $lines, private int $minLevel = 0) {}

    public function write(string $app, $message, int $level) {
    }

    public function getLogFilePath(): string {
        return '/var/www/data/nextcloud.log';
    }

    public function getEntries(int $limit = 50, int $offset = 0): array {
        $this->calls[] = [$limit, $offset];
        $entries = [];
        $kept = 0;
        foreach (array_reverse($this->lines) as $line) {
            if (count($entries) >= $limit) {
                break;
            }
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line);
            $level = is_object($entry) ? ($entry->level ?? null) : null;
            if ($level >= $this->minLevel) {
                $kept++;
                if ($kept > $offset) {
                    $entries[] = $entry;
                }
            }
        }
        if ($this->growth > 0) {
            $this->lines = [...$this->lines, ...self::lines($this->growth, static fn (int $i): array => ['reqId' => 'new' . bin2hex(random_bytes(4))])];
        }
        return $entries;
    }

    /**
     * @param int $count entries to build
     * @param callable(int):array<string, mixed> $fields fields of entry $i, oldest first
     * @return list<string> JSON lines
     */
    public static function lines(int $count, callable $fields): array {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = json_encode($fields($i) + [
                'reqId' => 'req' . $i, 'level' => 2, 'time' => gmdate(DATE_ATOM, 1791400000 + $i * 60), 'remoteAddr' => '203.0.113.' . ($i % 250),
                'user' => 'alice', 'app' => 'core', 'method' => 'GET', 'url' => '/index.php/apps/files/', 'message' => 'entry ' . $i,
                'userAgent' => 'Mozilla/5.0', 'version' => '31.0.9.1',
            ], JSON_UNESCAPED_SLASHES);
        }
        return $lines;
    }
}
