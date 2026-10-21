<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Access\Policy\DecimalCounter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persistence of authorization codes and token grants (tables mcp_oauth_codes / mcp_oauth_tokens). Only keyed
 * hashes are stored. Single use is enforced by conditional DELETE/UPDATE and their affected-row count.
 *
 * Every code, grant and spent record carries (auth_global_epoch, auth_gen) (contracts §3.1): a grant inherits the
 * generation of its code, and rotation and spent records keep it. Writes that issue, rotate or consume run inside the
 * policy writer's transaction, under its mutex. Revocation is logical (the epoch or the user's cut); the deletes here
 * are the physical cleanup after it, always qualified by generation or epoch, so a late or repeated cleanup never
 * removes a credential issued after the revocation it belongs to.
 */
class OAuthStore {
    private const CODES = 'mcp_oauth_codes';
    private const TOKENS = 'mcp_oauth_tokens';
    private const SPENT = 'mcp_oauth_spent';
    /** BIGINT counters every credential row carries (contracts §1). */
    public const COUNTERS = ['auth_global_epoch', 'auth_gen'];
    /** Columns the connection lists may read; never the token hashes. */
    private const LISTED = ['id', 'user_id', 'client_id', 'scope', 'created_at', 'access_expires', 'refresh_expires'];

    public function __construct(private IDBConnection $db) {}

    /**
     * @param array{code_hash:string,user_id:string,client_id:string,redirect_uri:string,code_challenge:string,resource:string,scope:string,expires_at:int,auth_global_epoch:string,auth_gen:string} $row
     * @param int $now current time; expired codes are purged on insert
     */
    public function insertCode(array $row, int $now): void {
        $purge = $this->db->getQueryBuilder();
        $purge->delete(self::CODES)->where($purge->expr()->lt('expires_at', $purge->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeStatement();
        $this->insert(self::CODES, $row);
    }

    /** @return array<string,mixed>|null the code with this hash, without consuming it */
    public function findCode(string $codeHash): ?array {
        return $this->findOne(self::CODES, 'code_hash', $codeHash);
    }

    /**
     * Deletes the code and returns it; a second call with the same hash returns null.
     *
     * @return array<string,mixed>|null the code row, or null when unknown or already consumed
     */
    public function consumeCode(string $codeHash): ?array {
        $row = $this->findOne(self::CODES, 'code_hash', $codeHash);
        if ($row === null) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $deleted = $qb->delete(self::CODES)->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
        return $deleted === 1 ? $row : null;
    }

    /**
     * @param array{user_id:string,client_id:string,resource:string,scope:string,access_hash:string,access_expires:int,refresh_hash:string,refresh_expires:int,created_at:int,auth_global_epoch:string,auth_gen:string} $row
     * @param int $now current time; grants whose refresh token expired are purged on insert
     */
    public function insertToken(array $row, int $now): void {
        $purge = $this->db->getQueryBuilder();
        $purge->delete(self::TOKENS)->where($purge->expr()->lt('refresh_expires', $purge->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeStatement();
        $this->purgeSpent($now);
        $this->insert(self::TOKENS, $row);
    }

    /**
     * Whether the user holds any code or grant row, valid or not: the relevance test of an account deletion
     * (contracts §3.3.2), which may over-report but never misses a credential that could still authenticate.
     *
     * @param string $uid owner
     * @return bool true when at least one code or grant row of $uid exists
     */
    public function hasCredentials(string $uid): bool {
        foreach ([self::TOKENS, self::CODES] as $table) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')->from($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->setMaxResults(1)->executeQuery();
            try {
                if ($result->fetch() !== false) {
                    return true;
                }
            } finally {
                $result->closeCursor();
            }
        }
        return false;
    }

    /**
     * Owners of a live code or grant (not expired, of the current global epoch), each with the highest generation
     * among those rows: the credential holders a group deletion must re-check (contracts §3.3.2). The caller compares
     * that generation with the owner's revoked_below. Expired or epoch-revoked rows can never authenticate again, so
     * leaving them out misses no credential; the list may still over-report (a cut not compared yet).
     *
     * @param int $now current time
     * @param string $epoch canonical global auth epoch
     * @return array<string, array{uid:string, gen:string}> keyed by a digest of the uid, never by the uid itself
     */
    public function liveHolders(int $now, string $epoch): array {
        $holders = [];
        foreach ([[self::TOKENS, 'refresh_expires'], [self::CODES, 'expires_at']] as [$table, $expiry]) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('user_id', 'auth_gen')->from($table)
                ->where($qb->expr()->gte($expiry, $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('auth_global_epoch', $qb->createNamedParameter($epoch, IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            try {
                while (($row = $result->fetch()) !== false) {
                    $uid = $row['user_id'] ?? null;
                    if (!is_string($uid) || $uid === '') {
                        continue;
                    }
                    $gen = (string)($row['auth_gen'] ?? '');
                    $key = hash('sha256', $uid);
                    $known = $holders[$key]['gen'] ?? null;
                    // A non-canonical generation is kept as the owner's (it cannot be compared): over-report, never omit.
                    if ($known === null || !self::canonical($gen) || (self::canonical($known) && DecimalCounter::compare($gen, $known) > 0)) {
                        $holders[$key] = ['uid' => $uid, 'gen' => $gen];
                    }
                }
            } finally {
                $result->closeCursor();
            }
        }
        return $holders;
    }

    private static function canonical(string $value): bool {
        try {
            DecimalCounter::validate($value, DecimalCounter::MAX);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<string,mixed>|null the grant owning this access token hash */
    public function findByAccess(string $accessHash): ?array {
        return $this->findOne(self::TOKENS, 'access_hash', $accessHash);
    }

    /** @return array<string,mixed>|null the grant owning this refresh token hash */
    public function findByRefresh(string $refreshHash): ?array {
        return $this->findOne(self::TOKENS, 'refresh_hash', $refreshHash);
    }

    /**
     * Replaces both tokens of a grant only if it still holds $oldRefreshHash (refresh rotation, single use). Runs in
     * the caller's transaction (the policy writer's), so the consumed hash commits together with its replacement and
     * a racing loser or later replay can identify the client, owner and generation.
     *
     * @return bool true when exactly this call rotated the grant
     * @throws \LogicException outside a transaction
     */
    public function rotate(int $id, string $oldRefreshHash, string $accessHash, int $accessExpires, string $refreshHash, int $refreshExpires): bool {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Refresh rotation runs only inside the policy writer transaction');
        }
        $this->purgeSpent($refreshExpires - TokenService::REFRESH_TTL);
        $row = $this->findByRefresh($oldRefreshHash);
        if ($row === null || (int)$row['id'] !== $id) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $updated = $qb->update(self::TOKENS)
            ->set('access_hash', $qb->createNamedParameter($accessHash))
            ->set('access_expires', $qb->createNamedParameter($accessExpires, IQueryBuilder::PARAM_INT))
            ->set('refresh_hash', $qb->createNamedParameter($refreshHash))
            ->set('refresh_expires', $qb->createNamedParameter($refreshExpires, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('refresh_hash', $qb->createNamedParameter($oldRefreshHash)))
            ->executeStatement();
        if ($updated !== 1) {
            return false;
        }
        // Never retain the plaintext refresh token; the spent record keeps the lineage's epoch and generation.
        $this->insert(self::SPENT, ['refresh_hash' => $oldRefreshHash, 'user_id' => $row['user_id'],
            'client_id' => $row['client_id'], 'grant_id' => $id, 'expires_at' => (int)$row['refresh_expires'],
            'auth_global_epoch' => (string)$row['auth_global_epoch'], 'auth_gen' => (string)$row['auth_gen']]);
        return true;
    }

    /** @return array<string,mixed>|null the spent record of a consumed refresh hash */
    public function findSpent(string $refreshHash): ?array {
        return $this->findOne(self::SPENT, 'refresh_hash', $refreshHash);
    }

    /**
     * Anti-replay: revokes every grant and spent record of one owner and client. The caller first checks that the
     * replayed spent record is still valid (generation and epoch), so a record from before a revocation never reaches
     * this and cannot remove the session of a later consent.
     *
     * @param string $uid owner of the replayed refresh token
     * @param string $clientId client of the replayed refresh token
     */
    public function deleteForOwnerAndClient(string $uid, string $clientId): void {
        foreach ([self::TOKENS, self::SPENT] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
                ->executeStatement();
        }
    }

    /** Deletes one grant (access and refresh token together). */
    public function deleteToken(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TOKENS)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::SPENT)->where($qb->expr()->eq('grant_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    /**
     * Live grants for the connection lists, newest first. Only the columns below are read: the token hashes
     * never leave this class through this method.
     *
     * @param string|null $uid owner to restrict to, null for every user (admin list)
     * @param string $search case-insensitive part of the user id or client_id, '' for none
     * @param int $limit rows to return
     * @param int $offset rows to skip
     * @param int $now current time; grants whose refresh token expired are not live
     * @return list<array{id:int, user_id:string, client_id:string, scope:string, created_at:int, access_expires:int, refresh_expires:int}>
     */
    public function listGrants(?string $uid, string $search, int $limit, int $offset, int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(...self::LISTED)->from(self::TOKENS)
            ->where($qb->expr()->gte('refresh_expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        if ($uid !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        }
        if ($search !== '') {
            $like = '%' . $this->db->escapeLikeParameter($search) . '%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('user_id', $qb->createNamedParameter($like)),
                $qb->expr()->iLike('client_id', $qb->createNamedParameter($like)),
            ));
        }
        $result = $qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)->setFirstResult($offset)->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'user_id' => (string)$row['user_id'],
            'client_id' => (string)$row['client_id'],
            'scope' => (string)$row['scope'],
            'created_at' => (int)$row['created_at'],
            'access_expires' => (int)$row['access_expires'],
            'refresh_expires' => (int)$row['refresh_expires'],
        ], $rows);
    }

    /**
     * @param int $now current time; grants whose refresh token expired are not counted
     * @return int number of live grants of every user
     */
    public function countGrants(int $now): int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('id', 'grants'))->from(self::TOKENS)
            ->where($qb->expr()->gte('refresh_expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $count = $result->fetchOne();
        $result->closeCursor();
        return (int)$count;
    }

    /**
     * Deletes one grant, optionally only when it belongs to $uid, so a user can never revoke someone else's.
     *
     * @param int $id grant id
     * @param string|null $uid required owner, null for an administrator
     * @return bool true when this call deleted the grant
     */
    public function deleteGrant(int $id, ?string $uid): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TOKENS)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        if ($uid !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        }
        if ($qb->executeStatement() !== 1) {
            return false;
        }
        $spent = $this->db->getQueryBuilder();
        $spent->delete(self::SPENT)->where($spent->expr()->eq('grant_id', $spent->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
        return true;
    }

    /**
     * Physical cleanup of a user cut: every code, grant and spent record of $uid with auth_gen <= $cut. Idempotent,
     * and a credential issued after the cut (higher generation) is never touched.
     *
     * @param string $uid owner
     * @param string $cut canonical generation of the cut
     */
    public function deleteUpToGeneration(string $uid, string $cut): void {
        foreach ([self::TOKENS, self::CODES, self::SPENT] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->lte('auth_gen', $qb->createNamedParameter($cut, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
    }

    /**
     * Physical cleanup of a global revocation: every code, grant and spent record below the auth epoch $epoch.
     *
     * @param string $epoch canonical auth epoch that the revocation committed
     */
    public function deleteBelowEpoch(string $epoch): void {
        foreach ([self::TOKENS, self::CODES, self::SPENT] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->lt('auth_global_epoch', $qb->createNamedParameter($epoch, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
    }

    /**
     * Expired codes, grants whose refresh token expired and expired spent records: housekeeping of the cleanup job,
     * which issuance also does on insert.
     *
     * @param int $now current time
     */
    public function purgeExpired(int $now): void {
        foreach ([[self::CODES, 'expires_at'], [self::TOKENS, 'refresh_expires'], [self::SPENT, 'expires_at']] as [$table, $column]) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->lt($column, $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
    }

    /** Deletes every grant, pending code and spent-refresh record of one client, for all users. */
    public function deleteForClient(string $clientId): void {
        foreach ([self::TOKENS, self::CODES, self::SPENT] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))->executeStatement();
        }
    }

    /** Normal token issuance, rotation and replay checks bound history retention without an occ step. */
    private function purgeSpent(int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::SPENT)->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    /** @param array<string,string|int> $row */
    private function insert(string $table, array $row): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        foreach ($row as $column => $value) {
            // Generations and epochs travel as canonical decimal strings but are BIGINT columns.
            $int = is_int($value) || in_array($column, self::COUNTERS, true);
            $qb->setValue($column, $qb->createNamedParameter($value, $int ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
        }
        $qb->executeStatement();
    }

    /** @return array<string,mixed>|null */
    private function findOne(string $table, string $column, string $value): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value)))
            ->setMaxResults(1)->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

}
