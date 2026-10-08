<?php
declare(strict_types=1);
namespace OCA\Mcp\Db;

use OCA\Mcp\Service\LogsGroupsConflict;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/** @extends QBMapper<Entity> Single state row, always read from the database, without appconfig caches. */
class LogsGroupsMapper extends QBMapper {
    public const TABLE = 'mcp_logs_groups';
    public const LEGACY_KEY = 'logs_groups';

    public function __construct(IDBConnection $db, private IAppConfig $legacy) {
        parent::__construct($db, self::TABLE, Entity::class);
    }

    /** @return array{groups:list<string>, version:int} */
    public function state(): array {
        $row = $this->read();
        if ($row === false) {
            $groups = self::decode($this->legacy->getValueString('mcp', self::LEGACY_KEY, '[]'));
            $qb = $this->db->getQueryBuilder();
            try {
                $qb->insert(self::TABLE)
                    ->setValue('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
                    ->setValue('groups', $qb->createNamedParameter(json_encode($groups, JSON_THROW_ON_ERROR)))
                    ->setValue('version', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->executeStatement();
            } catch (Exception $e) {
                // Another worker initialized first. Never overwrite it with our legacy snapshot.
                if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
            }
            $row = $this->read();
            if ($row === false) {
                throw new \RuntimeException('Missing logs groups state');
            }
            $this->legacy->deleteKey('mcp', self::LEGACY_KEY);
        }
        return ['groups' => self::decode((string)$row['groups']), 'version' => (int)$row['version']];
    }

    /** The database, rather than the optional lock or a compared list, decides whether this version can write. */
    public function compareAndSet(array $groups, int $version): void {
        $qb = $this->db->getQueryBuilder();
        $changed = $qb->update(self::TABLE)
            ->set('groups', $qb->createNamedParameter(json_encode(self::canonical($groups), JSON_THROW_ON_ERROR)))
            ->set('version', $qb->createFunction('version + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        if ($changed !== 1) {
            throw new LogsGroupsConflict('The groups changed since they were read');
        }
    }

    /** @return array<string,mixed>|false */
    private function read(): array|false {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('groups', 'version')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        try {
            return $result->fetch();
        } finally {
            $result->closeCursor();
        }
    }

    /** @return list<string> */
    private static function decode(string $json): array {
        $decoded = json_decode($json, true);
        return self::canonical(is_array($decoded) ? array_filter($decoded, static fn ($gid): bool => is_string($gid) && $gid !== '') : []);
    }

    /** @param array<string> $groups @return list<string> */
    public static function canonical(array $groups): array {
        $groups = array_values(array_unique($groups, SORT_STRING));
        sort($groups, SORT_STRING);
        return $groups;
    }
}
