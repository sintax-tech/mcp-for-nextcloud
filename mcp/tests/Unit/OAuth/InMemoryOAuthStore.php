<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\OAuthStore;

/** OAuthStore double keeping rows in arrays, with the same single-use semantics as the SQL implementation. */
final class InMemoryOAuthStore extends OAuthStore {
    /** @var array<int,array<string,mixed>> */
    public array $codes = [];
    /** @var array<int,array<string,mixed>> */
    public array $tokens = [];
    private int $nextId = 1;

    public function __construct() {}

    public function insertCode(array $row, int $now): void { $this->codes[$this->nextId] = $row + ['id' => $this->nextId++]; }

    public function consumeCode(string $codeHash): ?array {
        foreach ($this->codes as $id => $row) {
            if ($row['code_hash'] === $codeHash) { unset($this->codes[$id]); return $row; }
        }
        return null;
    }

    public function insertToken(array $row, int $now): void { $this->tokens[$this->nextId] = $row + ['id' => $this->nextId++]; }

    public function findByAccess(string $accessHash): ?array { return $this->find('access_hash', $accessHash); }

    public function findByRefresh(string $refreshHash): ?array { return $this->find('refresh_hash', $refreshHash); }

    public function rotate(int $id, string $oldRefreshHash, string $accessHash, int $accessExpires, string $refreshHash, int $refreshExpires): bool {
        if (($this->tokens[$id]['refresh_hash'] ?? null) !== $oldRefreshHash) { return false; }
        $this->tokens[$id] = array_merge($this->tokens[$id], ['access_hash' => $accessHash, 'access_expires' => $accessExpires,
            'refresh_hash' => $refreshHash, 'refresh_expires' => $refreshExpires]);
        return true;
    }

    public function deleteToken(int $id): void { unset($this->tokens[$id]); }

    public function deleteForUser(string $uid): void {
        $this->tokens = array_filter($this->tokens, fn (array $r) => $r['user_id'] !== $uid);
        $this->codes = array_filter($this->codes, fn (array $r) => $r['user_id'] !== $uid);
    }

    private function find(string $column, string $value): ?array {
        foreach ($this->tokens as $row) {
            if ($row[$column] === $value) { return $row; }
        }
        return null;
    }
}
