<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** JSON results are recursive language values, never user-defined classes with storage-writing magic hooks. */
final class CollectivesJsonTypesTest extends CallGraphTreeTestCase {
	/** @return void associative true and a literal JSON array flag exclude objects at every nesting level */
	public function testAssociativeResultsContainNoObjects(): void {
		foreach (['json_decode(\'{"a":{"b":1}}\', true)',
			'json_decode(\'{"a":{"b":1}}\', null, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR)'] as $expr) {
			$r = $this->reach(['Entry' => 'public function read(): void { $data = ' . $expr . '; $text = strval($data["a"]["b"]); }'], 'Entry::read');
			$this->assertSame([], $r['unknown']);
			$this->assertNotContains('php-core stdClass::__toString[absent]', $r['leaves']);
		}
	}

	/** @return void object mode and an unknown associative argument include the exact stdClass error outcome */
	public function testObjectAndUnknownModesUseTheVerifiedCoreLeaf(): void {
		foreach (['json_decode(\'{"x":1}\')', 'json_decode(\'{"x":1}\', $associative)'] as $expr) {
			$r = $this->reach(['Entry' => 'public function read(bool $associative): void { $value = ' . $expr . '; $text = strval($value); }'], 'Entry::read');
			$this->assertSame([], $r['unknown']);
			$this->assertContains('php-core stdClass::__toString[absent]', $r['leaves']);
		}
		require_once __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/CollectivesCoreProof.php';
		\CollectivesCoreProof::check('php-core stdClass::__toString[absent]');
		$this->assertFalse((new \ReflectionClass(\stdClass::class))->hasMethod('__toString'));
	}

	/** @return void explicit false overrides the JSON_OBJECT_AS_ARRAY flag, exactly as PHP does */
	public function testExplicitFalseOverridesTheFlag(): void {
		$value = json_decode('{"x":1}', false, 512, JSON_OBJECT_AS_ARRAY);
		$this->assertInstanceOf(\stdClass::class, $value);
		$r = $this->reach(['Entry' => 'public function read(): void {
			$value = json_decode(\'{"x":1}\', false, 512, JSON_OBJECT_AS_ARRAY); $text = (string)$value;
		}'], 'Entry::read');
		$this->assertContains('php-core stdClass::__toString[absent]', $r['leaves']);
	}

	/** @return void stdClass cannot dispatch a magic hook even when JSON contains a property with that spelling */
	public function testRuntimeStringConversionRaisesErrorWithoutDispatch(): void {
		$value = json_decode('{"__toString":"not a method"}');
		$this->assertSame(\stdClass::class, get_class($value));
		$this->expectException(\Error::class);
		(string)$value;
	}

	/** @return void unknown flag expressions preserve both JSON result modes */
	public function testUnknownFlagsPreserveBothModes(): void {
		$r = $this->reach(['Entry' => 'public function read(int $flags): void {
			$value = json_decode(\'{"x":1}\', null, 512, $flags); $text = (string)$value;
		}'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertContains('php-core stdClass::__toString[absent]', $r['leaves']);
	}
}
