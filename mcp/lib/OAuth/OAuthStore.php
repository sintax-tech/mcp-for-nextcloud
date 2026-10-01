<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persistence of authorization codes and token grants (tables mcp_oauth_codes / mcp_oauth_tokens). Only keyed
 * hashes are stored. Single use is enforced by conditional DELETE/UPDATE and their affected-row count.
 */
class OAuthStore {
    private const CODES = 'mcp_oauth_codes';
    private const TOKENS = 'mcp_oauth_tokens';
    private const SPENT = 'mcp_oauth_spent';
    /** Columns the connection lists may read; never the token hashes. */
    private const LISTED = ['id', 'user_id', 'client_id', 'scope', 'created_at', 'access_expires', 'refresh_expires'];

    public function __construct(private IDBConnection $db) {}

    /**
     * @param array{code_hash:string,user_id:string,client_id:string,redirect_uri:string,code_challenge:string,resource:string,scope:string,expires_at:int} $row
     * @param int $now current time; expired codes are purged on insert
     */
    public function insertCode(array $row, int $now): void {
        $purge = $this->db->getQueryBuilder();
        $purge->delete(self::CODES)->where($purge->expr()->lt('expires_at', $purge->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeStatement();
        $this->insert(self::CODES, $row);
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
     * @param array{user_id:string,client_id:string,resource:string,scope:string,access_hash:string,access_expires:int,refresh_hash:string,refresh_expires:int,created_at:int} $row
     * @param int $now current time; grants whose refresh token expired are purged on insert
     */
    public function insertToken(array $row, int $now): void {
        $purge = $this->db->getQueryBuilder();
        $purge->delete(self::TOKENS)->where($purge->expr()->lt('refresh_expires', $purge->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeStatement();
        $this->purgeSpent($now);
        $this->insert(self::TOKENS, $row);
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
     * Replaces both tokens of a grant only if it still holds $oldRefreshHash (refresh rotation, single use).
     *
     * @return bool true when exactly this call rotated the grant
     */
    public function rotate(int $id, string $oldRefreshHash, string $accessHash, int $accessExpires, string $refreshHash, int $refreshExpires): bool {
        $this->purgeSpent($refreshExpires - TokenService::REFRESH_TTL);
        $this->db->beginTransaction();
        try {
            $row = $this->findByRefresh($oldRefreshHash);
            if ($row === null || (int)$row['id'] !== $id) {
                $this->db->rollBack();
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
                $this->db->rollBack();
                return false;
            }
            // Commit the consumed hash together with its replacement, so a racing loser or later replay
            // can identify the client/owner. Never retain the plaintext refresh token.
            $this->insert(self::SPENT, ['refresh_hash' => $oldRefreshHash, 'user_id' => $row['user_id'],
                'client_id' => $row['client_id'], 'grant_id' => $id, 'expires_at' => (int)$row['refresh_expires']]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Revokes all grants of the matching client/owner if this is an unexpired consumed refresh hash. */
    public function revokeReusedRefresh(string $hash, string $clientId, int $now): void {
        $this->purgeSpent($now);
        $row = $this->findOne(self::SPENT, 'refresh_hash', $hash);
        if ($row !== null && $row['client_id'] === $clientId && (int)$row['expires_at'] >= $now) {
            foreach ([self::TOKENS, self::SPENT] as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($row['user_id'])))
                    ->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
                    ->executeStatement();
            }
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

    /** Deletes all credentials immediately when the service is disabled. */
    public function deleteAll(): void {
        foreach ([self::TOKENS, self::CODES, self::SPENT] as $table) {
            $this->db->getQueryBuilder()->delete($table)->executeStatement();
        }
    }

    /** Deletes every grant and pending code of a user. */
    public function deleteForUser(string $uid): void {
        foreach ([self::TOKENS, self::CODES, self::SPENT] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
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
            $qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
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
