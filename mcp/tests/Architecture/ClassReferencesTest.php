<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Architecture\Dependencies\ClassReferences;
use OCA\Mcp\Tests\Architecture\Inventory\AppTree;
use RuntimeException;

/** Collector syntax controls and mapped physical destination controls, independent of boundary exemptions. */
final class ClassReferencesTest extends ArchitectureCase {
	public function testAliasesSignaturesHeritageAttributesAndStringsAreCollected(): void {
		$source = <<<'PHP'
<?php
namespace Example;
use OCA\Deck\{Db\Card as Item, Service\CardService};
use function Example\f;
#[\OCA\Forms\Attribute]
final class Work extends CardService implements \OCA\Forms\Port {
	use \OCA\Forms\SomeTrait;
	public function run(Item|\OCA\Forms\Form $card): \OCA\Forms\Result {
		try { $x = new Item(); $yes = $x instanceof Item; $type = Item::class; }
		catch (\OCA\Forms\Error $e) {}
		$single = 'OCA\Guests\GuestManager';
		$double = 'OCA\\Guests\\AppWhitelist';
	}
}
PHP;
		$names = array_column(ClassReferences::read($source), 'target');
		foreach (['OCA\\Deck\\Db\\Card', 'OCA\\Deck\\Service\\CardService', 'OCA\\Forms\\Attribute', 'OCA\\Forms\\Port', 'OCA\\Forms\\SomeTrait', 'OCA\\Forms\\Form', 'OCA\\Forms\\Result', 'OCA\\Forms\\Error', 'OCA\\Guests\\GuestManager', 'OCA\\Guests\\AppWhitelist'] as $name) { self::assertContains($name, $names); }
		self::assertNotContains('Example\\f', $names);
		self::assertSame([], ClassReferences::read('<?php $x = json_encode([], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); $x = $a ? trim($x) : implode($x);'));
	}
	public function testMovingNativeSourceIntoIntegrationWithoutUpdatingItsNamespaceAndMapFails(): void {
		$source = \OCA\Mcp\Tests\Architecture\Inventory\CodeFixture::php('<?php namespace OCA~Mcp~Tools~Deck; class DeckCapabilities {}');
		$original = \OCA\Mcp\Tests\Architecture\Inventory\CodeFixture::path(['lib', 'Tools', 'Deck', 'DeckCapabilities']);
		$destination = \OCA\Mcp\Tests\Architecture\Inventory\CodeFixture::path(['lib', 'Modules', 'Deck', 'Integration', 'DeckCapabilities']);
		$identity = implode('\\', ['OCA', 'Mcp', 'Tools', 'Deck', 'DeckCapabilities']);
		try { AppTree::declarations([$destination => $source], self::tree()->names); self::fail('Wrong physical destination accepted'); }
		catch (RuntimeException $error) { self::assertStringContainsString('Namespace/path mismatch', $error->getMessage()); }
		self::assertSame([$identity => $original], AppTree::declarations([$original => $source], self::tree()->names));
		$this->expectException(RuntimeException::class);
		AppTree::declarations(['lib/Platform/Unmapped.php' => '<?php namespace OCA\\Mcp\\Platform; class Unmapped {}'], self::tree()->names);
	}
}
