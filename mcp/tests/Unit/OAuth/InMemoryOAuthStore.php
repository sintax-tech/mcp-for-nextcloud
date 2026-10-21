<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\OAuthStore;

/**
 * OAuthStore double keeping rows in arrays, with the same single-use and generation semantics as the SQL
 * implementation. A row without auth_gen/auth_global_epoch has the column defaults, generation 1 and epoch 0.
 */
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

    public function liveHolders(int $now, string $epoch): array {
        $holders = [];
        foreach ([[$this->tokens, 'refresh_expires'], [$this->codes, 'expires_at']] as [$rows, $expiry]) {
            foreach ($rows as $row) {
                if (($row[$expiry] ?? PHP_INT_MAX) < $now || (string)($row['auth_global_epoch'] ?? '0') !== $epoch) {
                    continue;
                }
                $key = hash('sha256', $row['user_id']);
                if (!isset($holders[$key]) || (int)$row['auth_gen'] > (int)$holders[$key]['gen']) {
                    $holders[$key] = ['uid' => $row['user_id'], 'gen' => (string)$row['auth_gen']];
                }
            }
        }
        return $holders;
    }

    public function hasCredentials(string $uid): bool {
        return in_array($uid, [...array_column($this->tokens, 'user_id'), ...array_column($this->codes, 'user_id')], true);
    }

    public function findCode(string $codeHash): ?array {
        foreach ($this->codes as $row) {
            if ($row['code_hash'] === $codeHash) { return $row; }
        }
        return null;
    }

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
        $this->spent[$oldRefreshHash] = ['refresh_hash' => $oldRefreshHash, 'user_id' => $this->tokens[$id]['user_id'],
            'client_id' => $this->tokens[$id]['client_id'], 'grant_id' => $id, 'expires_at' => $this->tokens[$id]['refresh_expires'],
            'auth_global_epoch' => self::epoch($this->tokens[$id]), 'auth_gen' => self::gen($this->tokens[$id])];
        $this->tokens[$id] = array_merge($this->tokens[$id], ['access_hash' => $accessHash, 'access_expires' => $accessExpires,
            'refresh_hash' => $refreshHash, 'refresh_expires' => $refreshExpires]);
        return !$this->loseRotationRace;
    }

    public function findSpent(string $refreshHash): ?array { return $this->spent[$refreshHash] ?? null; }

    public function deleteForOwnerAndClient(string $uid, string $clientId): void {
        $keep = fn (array $r): bool => $r['user_id'] !== $uid || $r['client_id'] !== $clientId;
        $this->tokens = array_filter($this->tokens, $keep);
        $this->spent = array_filter($this->spent, $keep);
    }

    public function deleteToken(int $id): void {
        unset($this->tokens[$id]);
        $this->spent = array_filter($this->spent, fn (array $r) => $r['grant_id'] !== $id);
    }

    public function listGrants(?string $uid, string $search, int $limit, int $offset, int $now): array {
        $rows = array_filter($this->tokens, fn (array $r) => $r['refresh_expires'] >= $now && ($uid === null || $r['user_id'] === $uid)
            && ($search === '' || stripos($r['user_id'], $search) !== false || stripos($r['client_id'], $search) !== false));
        usort($rows, fn (array $a, array $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        return array_map(fn (array $r) => ['id' => $r['id'], 'user_id' => $r['user_id'], 'client_id' => $r['client_id'], 'scope' => $r['scope'],
            'created_at' => $r['created_at'], 'access_expires' => $r['access_expires'], 'refresh_expires' => $r['refresh_expires']],
            array_slice($rows, $offset, $limit));
    }

    public function countGrants(int $now): int {
        return count(array_filter($this->tokens, fn (array $r) => $r['refresh_expires'] >= $now));
    }

    public function deleteGrant(int $id, ?string $uid): bool {
        if (!isset($this->tokens[$id]) || ($uid !== null && $this->tokens[$id]['user_id'] !== $uid)) { return false; }
        $this->deleteToken($id);
        return true;
    }

    public function deleteUpToGeneration(string $uid, string $cut): void {
        $keep = fn (array $r): bool => $r['user_id'] !== $uid || (int)self::gen($r) > (int)$cut;
        [$this->tokens, $this->codes, $this->spent] = [array_filter($this->tokens, $keep), array_filter($this->codes, $keep), array_filter($this->spent, $keep)];
    }

    public function deleteBelowEpoch(string $epoch): void {
        $keep = fn (array $r): bool => (int)self::epoch($r) >= (int)$epoch;
        [$this->tokens, $this->codes, $this->spent] = [array_filter($this->tokens, $keep), array_filter($this->codes, $keep), array_filter($this->spent, $keep)];
    }

    public function purgeExpired(int $now): void {
        $this->codes = array_filter($this->codes, fn (array $r) => $r['expires_at'] >= $now);
        $this->tokens = array_filter($this->tokens, fn (array $r) => $r['refresh_expires'] >= $now);
        $this->spent = array_filter($this->spent, fn (array $r) => $r['expires_at'] >= $now);
    }

    public function deleteForClient(string $clientId): void {
        $this->tokens = array_filter($this->tokens, fn (array $r) => ($r['client_id'] ?? null) !== $clientId);
        $this->codes = array_filter($this->codes, fn (array $r) => ($r['client_id'] ?? null) !== $clientId);
        $this->spent = array_filter($this->spent, fn (array $r) => ($r['client_id'] ?? null) !== $clientId);
    }

    private static function gen(array $row): string { return (string)($row['auth_gen'] ?? '1'); }

    private static function epoch(array $row): string { return (string)($row['auth_global_epoch'] ?? '0'); }

    private function find(string $column, string $value): ?array {
        foreach ($this->tokens as $row) {
            if (($row[$column] ?? null) === $value) { return $row; }
        }
        return null;
    }

}
