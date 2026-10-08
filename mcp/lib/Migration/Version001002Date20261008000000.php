<?php
declare(strict_types=1);
namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Native, packaged storage for the versioned logs role gate. Legacy groups are imported on first read. */
class Version001002Date20261008000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('mcp_logs_groups')) {
            return null;
        }
        $table = $schema->createTable('mcp_logs_groups');
        $table->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('groups', Types::TEXT, ['notnull' => true]);
        $table->addColumn('version', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
        $table->setPrimaryKey(['id']);
        return $schema;
    }
}
