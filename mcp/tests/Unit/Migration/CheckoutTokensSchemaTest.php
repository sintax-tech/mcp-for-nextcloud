<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** The shape the single-use checkout tokens depend on: a nullable `used_at`, a unique hash and a short `kind`. */
final class CheckoutTokensSchemaTest extends TestCase {
    private function schema(): Schema {
        $schema = new Schema();
        $wrapper = $this->createMock(ISchemaWrapper::class);
        $wrapper->method('hasTable')->willReturnCallback(fn ($name) => $schema->hasTable($name));
        $wrapper->method('createTable')->willReturnCallback(fn ($name) => $schema->createTable($name));
        (new Version000700Date20261001000000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []);
        return $schema;
    }

    public function testUsedAtIsNullableSoANewTokenIsUnspent(): void {
        $table = $this->schema()->getTable('mcp_checkout_tokens');

        $this->assertFalse($table->getColumn('used_at')->getNotnull());
        foreach (['token_hash', 'kind', 'user_id', 'file_id', 'path', 'etag', 'scope', 'created_at', 'expires_at'] as $column) {
            $this->assertTrue($table->getColumn($column)->getNotnull(), $column);
        }
    }

    public function testTheTokenHashIsUniqueAndIndexedForTheLookup(): void {
        $table = $this->schema()->getTable('mcp_checkout_tokens');
        $unique = array_values(array_filter($table->getIndexes(), static fn ($index) => $index->isUnique() && !$index->isPrimary()));

        $this->assertCount(1, $unique);
        $this->assertSame(['token_hash'], $unique[0]->getColumns());
        $this->assertSame(64, $table->getColumn('token_hash')->getLength());
        $this->assertTrue($table->hasIndex('mcp_checkout_token_uid'), 'the disconnect deletes by user');
        $this->assertTrue($table->hasIndex('mcp_checkout_token_exp'), 'the purge deletes by expiry');
    }

    public function testKindFitsTheLongestKindAndSharedConfirmedDefaultsToNo(): void {
        $table = $this->schema()->getTable('mcp_checkout_tokens');

        $this->assertSame(['id'], $table->getPrimaryKey()->getColumns());
        $this->assertGreaterThanOrEqual(strlen('download'), $table->getColumn('kind')->getLength());
        $this->assertSame(0, $table->getColumn('shared_confirmed')->getDefault());
        $this->assertTrue($table->getColumn('id')->getAutoincrement());
    }

    public function testRunningTheMigrationAgainChangesNothing(): void {
        $schema = new Schema();
        $wrapper = $this->createMock(ISchemaWrapper::class);
        $wrapper->method('hasTable')->willReturnCallback(fn ($name) => $schema->hasTable($name));
        $wrapper->method('createTable')->willReturnCallback(fn ($name) => $schema->createTable($name));
        $migration = new Version000700Date20261001000000();
        $output = $this->createMock(IOutput::class);

        $this->assertSame($wrapper, $migration->changeSchema($output, fn () => $wrapper, []));
        $this->assertNull($migration->changeSchema($output, fn () => $wrapper, []));
    }
}
