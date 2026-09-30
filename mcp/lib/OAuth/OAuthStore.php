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
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TOKENS)
            ->set('access_hash', $qb->createNamedParameter($accessHash))
            ->set('access_expires', $qb->createNamedParameter($accessExpires, IQueryBuilder::PARAM_INT))
            ->set('refresh_hash', $qb->createNamedParameter($refreshHash))
            ->set('refresh_expires', $qb->createNamedParameter($refreshExpires, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('refresh_hash', $qb->createNamedParameter($oldRefreshHash)));
        return $qb->executeStatement() === 1;
    }

    /** Deletes one grant (access and refresh token together). */
    public function deleteToken(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TOKENS)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    /** Deletes every grant and pending code of a user. */
    public function deleteForUser(string $uid): void {
        foreach ([self::TOKENS, self::CODES] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
        }
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
