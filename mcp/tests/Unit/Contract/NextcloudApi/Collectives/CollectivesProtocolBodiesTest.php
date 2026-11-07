<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Protocol calls cannot be erased by absent bodies or by losing values supplied to their implicit parameters. */
final class CollectivesProtocolBodiesTest extends RuntimeTreeTestCase {
	/** @return void a PHP interface states dispatch but supplies no method body */
	public function testProtocolWithoutBodyIsUnknown(): void {
		foreach (['public function read(\\Countable $x): void { count($x); }' => 'count',
			'public function read(\\Iterator $x): void { foreach ($x as $item) {} }' => 'current',
			'public function read(\\ArrayAccess $x): void { $y = $x["k"]; }' => 'offsetGet'] as $body => $method) {
			$r = $this->reach(['Entry' => $body], 'Entry::read');
			$this->assertUnknown($r['unknown'], 'Entry', '::' . $method . ' has no pinned body');
		}
	}

	/** @return void offsetSet receives the actual value argument and its conversion cannot be discarded */
	public function testImplicitOffsetArgumentsKeepObjectEvidence(): void {
		$r = $this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {}
				public function __toString(): string { $this->f->newFile("arg"); return "a"; }',
			'Bag implements \\ArrayAccess' => 'public function offsetGet(mixed $key): mixed { return null; }
				public function offsetExists(mixed $key): bool { return false; }
				public function offsetUnset(mixed $key): void {}
				public function offsetSet(mixed $key, mixed $value): void { strval($value); }',
			'Entry' => 'public function read(Folder $f): void { $b = new Bag(); $b["k"] = new Text($f); }',
		]);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Bag::offsetSet', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
	}

	/** @return void every builtin is individually audited, and comparing/converting functions cannot regress to none */
	public function testEveryListedFunctionHasAnEffectAudit(): void {
		$functions = json_decode(file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/collectives/collectives-php-functions.json'), true);
		foreach ($functions as $name => $model) {
			$this->assertNotEmpty($model['audit'], $name);
		}
		foreach (['array_column', 'array_combine', 'array_keys', 'array_search', 'array_unique', 'count', 'in_array',
			'max', 'min', 'sort', 'property_exists', 'range', 'settype'] as $name) {
			$this->assertNotSame('none', $functions[$name]['effect'], $name);
		}
	}
}
