<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Creates the OAuth tables: one-time authorization codes and token grants. Only keyed hashes are stored. */
class Version000500Date20260930000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output migration output
     * @param Closure(): ISchemaWrapper $schemaClosure current schema
     * @param array<string, mixed> $options migration options
     * @return ISchemaWrapper|null the changed schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('mcp_oauth_codes')) {
            $table = $schema->createTable('mcp_oauth_codes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('code_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('client_id', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('redirect_uri', Types::STRING, ['notnull' => true, 'length' => 2000]);
            $table->addColumn('code_challenge', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('resource', Types::STRING, ['notnull' => true, 'length' => 2000]);
            $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code_hash'], 'mcp_oauth_code_hash');
            $table->addIndex(['user_id'], 'mcp_oauth_code_uid');
        }
        if (!$schema->hasTable('mcp_oauth_tokens')) {
            $table = $schema->createTable('mcp_oauth_tokens');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('client_id', Types::STRING, ['notnull' => true, 'length' => 512]);
            $table->addColumn('resource', Types::STRING, ['notnull' => true, 'length' => 2000]);
            $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('access_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('access_expires', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('refresh_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('refresh_expires', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['access_hash'], 'mcp_oauth_access_hash');
            $table->addUniqueIndex(['refresh_hash'], 'mcp_oauth_refresh_hash');
            $table->addIndex(['user_id'], 'mcp_oauth_token_uid');
        }
        return $schema;
    }
}
