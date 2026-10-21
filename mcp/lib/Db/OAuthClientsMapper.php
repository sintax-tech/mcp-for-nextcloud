<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * The administrator's OAuth clients that decide admission: the native client's switch and the self-hosted clients,
 * one row per client. This table is the only source of those decisions. Every read goes to the database through the
 * server's connection, never through the AppConfig caches, whose loader can publish a snapshot older than a
 * withdrawal another worker committed: under the policy mutex (consent, exchange, refresh) the read sees the last
 * committed state, and the Bearer check reads the row of its client on every request.
 *
 * Rows are written only by OAuthClientsService inside PolicyTransaction::runAdmin, in the transaction that deletes the
 * credentials a withdrawal covers, and by the upgrade migration that imports the 1.0.8 switch. gen is a canonical
 * BIGINT that starts at 1 and advances on every withdrawal of the client; a row that does not validate (unknown id or
 * kind, a gen that is not a canonical positive counter, unreadable URIs) fails the read, so a caller refuses instead
 * of guessing.
 *
 * Absence means: the native client is off, a self-hosted client does not exist.
 */
class OAuthClientsMapper {
    public const TABLE = 'mcp_oauth_clients';
    public const KIND_NATIVE = 'native';
    public const KIND_CUSTOM = 'custom';

    public function __construct(private IDBConnection $db) {}

    /**
     * @param string $clientId client_id of a request or a stored grant
     * @return array{clientId:string, kind:string, name:string, enabled:bool, redirectUris:list<string>, gen:string, position:int}|null
     * @throws UnexpectedValueException for a stored row that does not validate
     * @throws \OCP\DB\Exception when the query fails
     */
    public function find(string $clientId): ?array {
        if ($clientId !== NativeClient::CLIENT_ID && !self::customId($clientId)) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
            ->setMaxResults(1)->executeQuery();
        try {
            $row = $result->fetch();
        } finally {
            $result->closeCursor();
        }
        return is_array($row) ? self::row($row) : null;
    }

    /**
     * @return list<array{clientId:string, kind:string, name:string, enabled:bool, redirectUris:list<string>, gen:string, position:int}>
     *     the self-hosted clients, in the administrator's order
     * @throws UnexpectedValueException for a stored row that does not validate; the whole read fails
     * @throws \OCP\DB\Exception when the query fails
     */
    public function custom(): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('kind', $qb->createNamedParameter(self::KIND_CUSTOM)))
            ->orderBy('position')->addOrderBy('client_id')->executeQuery();
        try {
            return array_map(self::row(...), $result->fetchAll());
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * Inserts or replaces the row of one client.
     *
     * @param array{clientId:string, kind:string, name:string, enabled:bool, redirectUris:list<string>, gen:string, position:int} $client
     * @throws UnexpectedValueException for a row that would not validate when read back
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function put(array $client): void {
        $client = self::row(self::stored($client));
        $values = self::stored($client);
        $qb = $this->db->getQueryBuilder();
        if ($this->find($client['clientId']) === null) {
            $qb->insert(self::TABLE);
            foreach ($values as $column => $value) {
                $qb->setValue($column, $qb->createNamedParameter($value, self::type($column)));
            }
        } else {
            $qb->update(self::TABLE);
            foreach ($values as $column => $value) {
                if ($column !== 'client_id') {
                    $qb->set($column, $qb->createNamedParameter($value, self::type($column)));
                }
            }
            $qb->where($qb->expr()->eq('client_id', $qb->createNamedParameter($client['clientId'])));
        }
        $qb->executeStatement();
    }

    /** @throws \OCP\DB\Exception when the statement fails */
    public function delete(string $clientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))->executeStatement();
    }

    private static function customId(string $clientId): bool {
        return preg_match('/^' . preg_quote(CustomClient::ID_PREFIX, '/') . '-[0-9a-f]{12}$/D', $clientId) === 1;
    }

    private static function type(string $column): mixed {
        return in_array($column, ['enabled', 'gen', 'position'], true) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
    }

    /** @return array<string, string|int|null> the columns of a validated client */
    private static function stored(array $client): array {
        return [
            'client_id' => $client['clientId'] ?? null,
            'kind' => $client['kind'] ?? null,
            'name' => $client['name'] ?? null,
            'enabled' => ($client['enabled'] ?? null) === true ? 1 : (($client['enabled'] ?? null) === false ? 0 : null),
            'redirect_uris' => is_array($client['redirectUris'] ?? null) ? json_encode($client['redirectUris'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'gen' => $client['gen'] ?? null,
            'position' => $client['position'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row a stored row
     * @return array{clientId:string, kind:string, name:string, enabled:bool, redirectUris:list<string>, gen:string, position:int}
     * @throws UnexpectedValueException when it does not validate
     */
    private static function row(array $row): array {
        $id = $row['client_id'] ?? null;
        $kind = $row['kind'] ?? null;
        $enabled = (string)($row['enabled'] ?? '');
        $uris = is_string($row['redirect_uris'] ?? null) ? json_decode($row['redirect_uris'], true) : null;
        $valid = is_string($id) && (($kind === self::KIND_NATIVE && $id === NativeClient::CLIENT_ID) || ($kind === self::KIND_CUSTOM && self::customId($id)))
            && in_array($enabled, ['0', '1'], true) && is_string($row['name'] ?? null)
            && is_array($uris) && array_is_list($uris) && array_filter($uris, 'is_string') === $uris
            && is_numeric($row['position'] ?? null);
        try {
            $gen = $valid ? DecimalCounter::validate((string)$row['gen'], DecimalCounter::MAX) : '0';
        } catch (\InvalidArgumentException $e) {
            throw new UnexpectedValueException('Invalid stored OAuth client', 0, $e);
        }
        if (!$valid || $gen === '0') {
            throw new UnexpectedValueException('Invalid stored OAuth client');
        }
        return ['clientId' => $id, 'kind' => $kind, 'name' => $row['name'], 'enabled' => $enabled === '1',
            'redirectUris' => $uris, 'gen' => $gen, 'position' => (int)$row['position']];
    }
}
