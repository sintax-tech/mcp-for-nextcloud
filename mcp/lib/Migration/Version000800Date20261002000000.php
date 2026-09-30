<?php
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the record of the executed reorganization batches (table mcp_file_batches).
 *
 * One row per batch, with the moves and the folders it created as JSON: a batch is undone as a unit, so
 * there is nothing to gain from a row per item, and one text column keeps the whole thing in a single
 * transaction with the move that produced it.
 */
class Version000800Date20261002000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output migration output
     * @param Closure(): ISchemaWrapper $schemaClosure current schema
     * @param array $options migration options
     * @return ISchemaWrapper|null the changed schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('mcp_file_batches')) {
            return null;
        }
        $table = $schema->createTable('mcp_file_batches');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        // [{from, to, toId}] — toId is what makes the undo safe: it only puts back the node it moved.
        $table->addColumn('moves_json', Types::TEXT, ['notnull' => true]);
        // The folders the batch itself created, which the undo may remove again while they are empty.
        $table->addColumn('dirs_json', Types::TEXT, ['notnull' => true]);
        $table->addColumn('undone_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->setPrimaryKey(['id']);
        // The undo always looks a batch up by owner, and the retention purge walks by age.
        $table->addIndex(['user_id'], 'mcp_file_batch_uid');
        $table->addIndex(['created_at'], 'mcp_file_batch_created');
        return $schema;
    }
}
