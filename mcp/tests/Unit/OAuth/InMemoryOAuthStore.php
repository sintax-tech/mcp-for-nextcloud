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
    public array $spent = [];
    public bool $loseRotationRace = false;
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
        $this->spent[$oldRefreshHash] = $this->tokens[$id];
        $this->tokens[$id] = array_merge($this->tokens[$id], ['access_hash' => $accessHash, 'access_expires' => $accessExpires,
            'refresh_hash' => $refreshHash, 'refresh_expires' => $refreshExpires]);
        return !$this->loseRotationRace;
    }

    public function deleteToken(int $id): void {
        unset($this->tokens[$id]);
        $this->spent = array_filter($this->spent, fn (array $r) => $r['id'] !== $id);
    }

    public function revokeReusedRefresh(string $hash, string $clientId, int $now): void {
        $row = $this->spent[$hash] ?? null;
        if ($row !== null && $row['client_id'] === $clientId && $row['refresh_expires'] >= $now) {
            $this->tokens = array_filter($this->tokens, fn (array $r) => $r['user_id'] !== $row['user_id'] || $r['client_id'] !== $clientId);
            $this->spent = array_filter($this->spent, fn (array $r) => $r['user_id'] !== $row['user_id'] || $r['client_id'] !== $clientId);
        }
    }

    public function deleteAll(): void { $this->tokens = []; $this->codes = []; $this->spent = []; }

    public function deleteForUser(string $uid): void {
        $this->tokens = array_filter($this->tokens, fn (array $r) => $r['user_id'] !== $uid);
        $this->codes = array_filter($this->codes, fn (array $r) => $r['user_id'] !== $uid);
        $this->spent = array_filter($this->spent, fn (array $r) => $r['user_id'] !== $uid);
    }

    private function find(string $column, string $value): ?array {
        foreach ($this->tokens as $row) {
            if (($row[$column] ?? null) === $value) { return $row; }
        }
        return null;
    }
}
