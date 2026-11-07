<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaServices, SchemaPreparation, SchemaTargetReader, SchemaColumnInput, SchemaSharePolicy};
use OCA\Mcp\Modules\Tables\Sharing\ShareChanges;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCP\Share\IManager;
use OCP\{IUserManager, IGroupManager};
use PHPUnit\Framework\TestCase;

/** Actual authorization/preparation collaborators over explicit native state and services. */
final class SchemaPreparationScene {
	/** @var array<string,array<int,SchemaNativeEntity>> Native mapper contents. */
	public array $entities = [];
	/** @var array<string,bool> Per-resource read. */
	public array $read = [];
	/** @var array<string,bool> Per-resource manage. */
	public array $manage = [];
	/** @var bool Native sharing switch. */
	public bool $disabled = false;
	/** @var SchemaServices Native service boundary. */
	public SchemaServices $services;
	/** @var SchemaPreparation Real preparation under test. */
	public SchemaPreparation $preparation;
	/**
	 * @param TestCase $test Mock owner.
	 */
	public function __construct(private TestCase $test) {
		$mock = new \ReflectionMethod($test, 'createMock');
		$fixture = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fixture['factorySources']['2.3.2'] as $source) {
			// This test process only loads the current family; the other families run isolated.
			if (preg_match('/namespace ([^;]+);.*?(?:class|enum) (\w+)/s', $source, $match) && class_exists($match[1] . '\\' . $match[2], false)) continue;
			eval(substr($source, 5));
		}
		$this->entities = [
			'table' => [1 => new SchemaNativeEntity(['id' => 1, 'title' => 'Table', 'ownership' => 'bob'])],
			'column' => [2 => new SchemaNativeEntity(['id' => 2, 'tableId' => 1, 'title' => 'Column', 'type' => 'text', 'subtype' => 'line'])],
			'view' => [3 => new SchemaNativeEntity(['id' => 3, 'tableId' => 1, 'title' => 'View', 'columnSettings' => [], 'filter' => [], 'sort' => []]),
				4 => new SchemaNativeEntity(['id' => 4, 'tableId' => 99, 'title' => 'Foreign'])],
		];
		$this->services = $mock->invoke($test, SchemaServices::class);
		$this->services->method('version')->willReturn('2.3.2');
		$this->services->method('get')->willReturnCallback(function (string $name): object {
			if ($name === 'permissions') return $this;
			if ($name === 'validator') { $class = 'OCA\\Tables\\Validation\\ColumnDtoValidator'; return new $class(); }
			if ($name === 'shares') return new class {
				/** @return array Native authorized shares. */
				public function findAll(): array { return [new SchemaNativeEntity(['id' => 9, 'nodeId' => 1, 'nodeType' => 'table', 'receiver' => 'bob', 'receiverType' => 'user', 'permissionRead' => true])]; }
			};
			return new class($this, $name === 'columns' ? 'column' : str_replace('Mapper', '', $name)) {
				/** @param SchemaPreparationScene $scene State. @param string $kind Entity kind. */
				public function __construct(private SchemaPreparationScene $scene, private string $kind) {}
				/** @param int $id Identifier. @return object Native entity or the common absent response. */
				public function find(int $id): object { return $this->scene->entities[$this->kind][$id] ?? throw new ToolFailure(CommonMessages::notFound()); }
				/** @return array Native columns available through the authorized view. */
				public function findAllByManagedView(): array { return array_values($this->scene->entities['column']); }
				/** @return array Native columns. */
				public function findAllByTable(): array { return array_values($this->scene->entities['column']); }
			};
		});
		$shares = $mock->invoke($test, IManager::class);
		$shares->method('sharingDisabledForUser')->willReturnCallback(fn (): bool => $this->disabled);
		$shares->method('allowGroupSharing')->willReturn(true);
		$users = $mock->invoke($test, IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$groups = $mock->invoke($test, IGroupManager::class);
		$columns = new SchemaColumnInput($this->services, $mock->invoke($test, UsergroupWhitelist::class));
		$this->preparation = new SchemaPreparation($this->services, new SchemaTargetReader($this->services), $columns, new SchemaSharePolicy($shares, $users, $groups), new ShareChanges());
	}
	/** @param string $action Tool suffix. @param array $args Intent. @param string $uid Caller. @param string $profile API. @return array Real preparation through the typed action. */
	public function prepare(string $action, array $args, string $uid, string $profile): array {
		return SchemaActionFixture::of($this->test, $action, $this->preparation)->prepare($args, $uid, $profile);
	}

	/** @param string $uid Caller. @param bool $cli Native flag. @return void */
	public function preCheckUserId(string $uid, bool $cli): void {}
	/** @param int $id Parent. @param string $uid Caller. @return bool */
	public function canReadColumnsByTableId(int $id, string $uid): bool { return $this->read['table:' . $id] ?? true; }
	/** @param int $id Parent. @param string $uid Caller. @return bool */
	public function canManageTableById(int $id, string $uid): bool { return $this->manage['table:' . $id] ?? true; }
	/** @param object $view Node. @param string $uid Caller. @return bool */
	public function canAccessView(object $view, string $uid): bool { return $this->read['view:' . $view->getId()] ?? true; }
	/** @param object $view Node. @param string $uid Caller. @return bool */
	public function canManageView(object $view, string $uid): bool { return $this->manage['view:' . $view->getId()] ?? true; }
}
