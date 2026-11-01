<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Schema;

use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use PHPUnit\Framework\TestCase;

/** Shared schema extraction preserves published key order, strictness, encoding and facade behavior. */
final class SchemaBuilderTest extends TestCase {
	/** @return void DAV and Calendar keep the released definition envelope and empty-object properties. */
	public function testDefinitionOrderAndStrictEmptyObjectArePreserved(): void {
		$definition = SchemaBuilder::definition('tasks', 'tasks', 'task_read', 'Read', 'read', []);
		self::assertSame('{"name":"task_read","description":"Read","inputSchema":{"type":"object","properties":{},"additionalProperties":false},"module":"tasks","operation":"read","app":"tasks"}', json_encode($definition));
		self::assertEquals($definition, DavCollectionSchema::definition('tasks', 'tasks', 'task_read', 'Read', 'read', []));
		self::assertEquals(SchemaBuilder::definition('calendar', 'calendar', 'event', 'Read', 'read', []), ToolSchema::definition('event', 'Read', 'read', []));
	}

	/** @return void Invalid UTF-8 substitution, pretty output and block ordering stay byte-identical. */
	public function testResultsAndErrorsKeepTheirEncoding(): void {
		$blocks = [['label' => "a/é", 'invalid' => "\xff"], ['second' => true]];
		self::assertSame(ToolSchema::result(...$blocks), SchemaBuilder::result(...$blocks));
		self::assertSame("{\n    \"label\": \"a/é\",\n    \"invalid\": \"�\"\n}", SchemaBuilder::result(...$blocks)['content'][0]['text']);
		self::assertSame(['content' => [['type' => 'text', 'text' => 'failure']], 'isError' => true], SchemaBuilder::error('failure'));
	}

	/** @return void Contacts and Tasks share the exact released DAV fragments with the calendar facade. */
	public function testSharedDavFragmentsStayIdentical(): void {
		self::assertSame(ToolSchema::calendar(), DavCollectionSchema::calendar());
		self::assertSame(ToolSchema::uid(), DavCollectionSchema::uid());
		self::assertSame(ToolSchema::etag(), DavCollectionSchema::etag());
		self::assertSame('event UID', DavCollectionSchema::uid()['description']);
	}
}
