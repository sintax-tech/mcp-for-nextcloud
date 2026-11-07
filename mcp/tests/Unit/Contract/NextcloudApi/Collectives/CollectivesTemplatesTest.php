<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Inherited return templates require both the ancestor declaration and explicit specialization. */
final class CollectivesTemplatesTest extends CallGraphTreeTestCase {
	/**
	 * @param bool $template whether the parent declares T
	 * @param bool $extends whether the child supplies T
	 * @return array the reached graph of a generic mapper
	 */
	private function generic(bool $template, bool $extends): array {
		file_put_contents($this->lib . '/Service/Model.php', '<?php
namespace OCA\\Collectives\\Service;
class Entity { public function __call($name, array $args) { $field = lcfirst(substr($name,3)); return $this->$field; } }
class Word extends Entity { protected string $term; }
' . ($template ? '/** @template T of Entity */' : '') . '
class Mapper {
/** @return list<Entity>
 * @psalm-return list<T> */
public function many(): array { return []; }
}
' . ($extends ? '/** @template-extends Mapper<Word> */' : '') . '
class Words extends Mapper {}
class Entry { public function read(Words $mapper): void {
foreach ($mapper->many() as $word) { strval($word->getTerm()); }
} }');
		return (new \CollectivesCallGraph($this->lib))->reach(['OCA\\Collectives\\Service\\Entry::read']);
	}

	/** @return void a declared template and its specialization retain the concrete field contract */
	public function testSpecializationProvesConcreteElements(): void {
		$this->assertSame([], $this->generic(true, true)['unknown']);
	}

	/** @return void an unspecialized mapper cannot borrow any subclass's field type */
	public function testMissingTemplateExtendsRemainsUnknown(): void {
		$this->assertNotSame([], $this->generic(true, false)['unknown']);
	}

	/** @return void a generic-looking annotation without a declared template does not prove the result */
	public function testMissingTemplateDeclarationRemainsUnknown(): void {
		$this->assertNotSame([], $this->generic(false, true)['unknown']);
	}
}
