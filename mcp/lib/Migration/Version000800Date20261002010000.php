<?php
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Consumed refresh hashes retained until expiry to detect rotation replay. */
class Version000800Date20261002010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('mcp_oauth_spent')) {
            return null;
        }
        $table = $schema->createTable('mcp_oauth_spent');
        $table->addColumn('refresh_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('client_id', Types::STRING, ['notnull' => true, 'length' => 512]);
        $table->addColumn('grant_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->setPrimaryKey(['refresh_hash']);
        $table->addIndex(['grant_id'], 'mcp_oauth_spent_grant');
        $table->addIndex(['user_id'], 'mcp_oauth_spent_uid');
        $table->addIndex(['expires_at'], 'mcp_oauth_spent_exp');
        return $schema;
    }
}
