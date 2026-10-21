<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * T12 on a real SQLite file in memory: codes, tokens and spent records written by the 1.0.8 store survive the B2b
 * ALTER untouched, take epoch 0 and generation 1, and the store still finds, rotates, consumes and replays them.
 */
final class LegacyCredentialsUpgradeTest extends TestCase {
    /** @return list<\OCP\Migration\SimpleMigrationStep> */
    private static function legacy(): array {
        return [new Version000500Date20260930000000(), new Version000700Date20261001000000(), new Version000800Date20261002000000(),
            new Version000800Date20261002010000(), new Version001002Date20261008000000()];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(SqliteDatabase $database, string $table): array {
        return $database->dbal->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'mcp_oauth_spent' ? 'refresh_hash' : 'id'));
    }

    private function schema(array $steps): Schema {
        $schema = new Schema();
        $wrapper = $this->createMock(ISchemaWrapper::class);
        $wrapper->method('hasTable')->willReturnCallback(fn (string $name): bool => $schema->hasTable($name));
        $wrapper->method('createTable')->willReturnCallback(fn (string $name) => $schema->createTable($name));
        $wrapper->method('getTable')->willReturnCallback(fn (string $name) => $schema->getTable($name));
        foreach ($steps as $step) {
            $step->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []);
        }
        return $schema;
    }

    public function testCredentialsOf108StayValidWithEpochZeroAndGenerationOne(): void {
        $database = new SqliteDatabase($this, self::legacy());
        $store = new OAuthStore($database->connection());
        $now = 1760000000;
        $store->insertCode(['code_hash' => str_repeat('c', 64), 'user_id' => 'alice', 'client_id' => 'client', 'redirect_uri' => 'https://c/cb',
            'code_challenge' => 'challenge', 'resource' => 'https://nc/mcp', 'scope' => 'mcp', 'expires_at' => $now + 300], $now);
        foreach ([1 => 'alice', 2 => 'bob'] as $n => $uid) {
            $store->insertToken(['user_id' => $uid, 'client_id' => 'client', 'resource' => 'https://nc/mcp', 'scope' => 'mcp',
                'access_hash' => str_repeat("a$n", 32), 'access_expires' => $now + 3600, 'refresh_hash' => str_repeat("r$n", 32),
                'refresh_expires' => $now + TokenService::REFRESH_TTL, 'created_at' => $now], $now);
        }
        // Grant 1 rotated once under 1.0.8, which left a spent record of its first refresh token (the 1.0.8 rows).
        $database->dbal->update('mcp_oauth_tokens', ['access_hash' => str_repeat('A1', 32), 'refresh_hash' => str_repeat('R1', 32)], ['id' => 1]);
        $database->dbal->insert('mcp_oauth_spent', ['refresh_hash' => str_repeat('r1', 32), 'user_id' => 'alice', 'client_id' => 'client',
            'grant_id' => 1, 'expires_at' => $now + TokenService::REFRESH_TTL]);
        $before = [];
        foreach (Version001100Date20261015010000::OAUTH_TABLES as $table) {
            $before[$table] = self::rows($database, $table);
        }
        $this->assertCount(1, $before['mcp_oauth_spent']);

        $from = $this->schema(self::legacy());
        $to = $this->schema([...self::legacy(), new Version001100Date20261015010000()]);
        $diff = $database->dbal->createSchemaManager()->createComparator()->compareSchemas($from, $to);
        foreach ((new SQLitePlatform())->getAlterSchemaSQL($diff) as $statement) {
            $database->dbal->executeStatement($statement);
        }

        foreach ($before as $table => $rows) {
            $after = self::rows($database, $table);
            $this->assertCount(count($rows), $after, $table);
            foreach ($rows as $i => $row) {
                $this->assertSame(['auth_global_epoch' => 0, 'auth_gen' => 1], array_diff_key($after[$i], $row), $table);
                $this->assertSame($row, array_intersect_key($after[$i], $row), $table);
            }
        }
        $this->assertSame('alice', $store->findByAccess(str_repeat('A1', 32))['user_id']);
        $this->assertSame('bob', $store->findByRefresh(str_repeat('r2', 32))['user_id']);
        $this->assertSame('alice', $store->consumeCode(str_repeat('c', 64))['user_id']);
        $connection = $database->connection();
        $connection->beginTransaction();
        $this->assertTrue($store->rotate(2, str_repeat('r2', 32), str_repeat('A2', 32), $now + 3600, str_repeat('R2', 32), $now + TokenService::REFRESH_TTL));
        $connection->commit();
        // The record spent under 1.0.8 is still a valid lineage (epoch 0, generation 1 against (1, 0)), so its replay
        // still revokes only that client and owner.
        $spent = $store->findSpent(str_repeat('r1', 32));
        $this->assertTrue(\OCA\Mcp\OAuth\Revocation\CredentialGeneration::live($spent, ['issue_seq' => '1', 'revoked_below' => '0'], '0'));
        $store->deleteForOwnerAndClient((string)$spent['user_id'], (string)$spent['client_id']);
        $this->assertSame(['bob'], array_column($database->rows('mcp_oauth_tokens'), 'user_id'));
    }
}
