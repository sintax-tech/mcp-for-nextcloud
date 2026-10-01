<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class OAuthSpentSchemaTest extends TestCase {
    public function testCleanInstallAndUpgradeProduceTheSameIdempotentSchema(): void {
        $schemas = [];
        foreach ([false, true] as $upgrade) {
            $schema = new Schema();
            $wrapper = $this->createMock(ISchemaWrapper::class);
            $wrapper->method('hasTable')->willReturnCallback(fn ($name) => $schema->hasTable($name));
            $wrapper->method('createTable')->willReturnCallback(fn ($name) => $schema->createTable($name));
            $output = $this->createMock(IOutput::class);
            $closure = fn () => $wrapper;
            foreach ([new Version000500Date20260930000000(), new Version000700Date20261001000000(),
                new Version000800Date20261002000000()] as $migration) {
                $migration->changeSchema($output, $closure, []);
            }
            if ($upgrade) {
                $this->assertFalse($schema->hasTable('mcp_oauth_spent'));
                $this->assertTrue($schema->hasTable('mcp_oauth_tokens'));
            }
            $migration = new Version000800Date20261002010000();
            $this->assertSame($wrapper, $migration->changeSchema($output, $closure, []));
            $this->assertNull($migration->changeSchema($output, $closure, []));
            $table = $schema->getTable('mcp_oauth_spent');
            $this->assertSame(['refresh_hash'], $table->getPrimaryKey()->getColumns());
            $this->assertSame(64, $table->getColumn('refresh_hash')->getLength());
            $this->assertSame(512, $table->getColumn('client_id')->getLength());
            $this->assertTrue($table->hasIndex('mcp_oauth_spent_exp'));
            $this->assertTrue($table->hasIndex('mcp_oauth_spent_uid'));
            foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform()] as $platform) {
                $this->assertNotEmpty($schema->toSql($platform));
            }
            $schemas[] = $schema->toSql(new PostgreSQLPlatform());
        }
        $this->assertSame($schemas[0], $schemas[1]);
    }
}
