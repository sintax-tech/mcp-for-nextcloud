<?php
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Creates the single-use checkout tokens of the local editing flow. Only keyed hashes are stored. */
class Version000700Date20261001000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output migration output
     * @param Closure(): ISchemaWrapper $schemaClosure current schema
     * @param array<string, mixed> $options migration options
     * @return ISchemaWrapper|null the changed schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('mcp_checkout_tokens')) {
            return null;
        }
        $table = $schema->createTable('mcp_checkout_tokens');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        // The 32-byte token itself is never stored; the row is found by its keyed HMAC.
        $table->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 2000]);
        $table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64]);
        // Ownership scope at checkout time, so the upload can tell whether a confirmation was required.
        $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('shared_confirmed', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('used_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['token_hash'], 'mcp_checkout_token_hash');
        $table->addIndex(['user_id'], 'mcp_checkout_token_uid');
        $table->addIndex(['expires_at'], 'mcp_checkout_token_exp');
        return $schema;
    }
}
