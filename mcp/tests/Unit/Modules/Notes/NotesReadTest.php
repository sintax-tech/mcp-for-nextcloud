<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes;

use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Notes\NotesTestModule;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises issue #34 through tools/call, including JSON serialization of the result envelope. */
final class NotesReadTest extends TestCase {
	private FakeTree $tree;
	private EffectsGate $gate;

	/** @return void Fresh files and a real protocol/registry/grant path for every test. */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return NotesModule Notes tools over the stateful OCP file doubles. */
	private function module(): NotesModule {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = FakeUsers::manager($this, FakeUsers::DEFAULTS);
		$access = new NodeAccessInfo($users, $this->tree->shareManager());
		return NotesTestModule::build(new NotesRepository($root, $this->gate->config()), $apps, $users,
			new SharedWriteGuard($access), $access);
	}

	/** @return iterable<string, array{string}> Default and localized folder paths from the issue. */
	public static function folders(): iterable {
		yield 'default' => ['Notes'];
		yield 'German' => ['Notizen'];
	}

	/**
	 * The id from the listing reads the created bytes, and editing with its ETag reads back the changed bytes.
	 * @param string $folder Notes folder configured for the account.
	 * @return void
	 */
	#[DataProvider('folders')]
	public function testCreateListReadEditRead(string $folder): void {
		if ($folder !== 'Notes') {
			$this->gate->data()->user['alice']['notes']['settings'] = json_encode(['notesPath' => $folder]);
		}
		$created = $this->json('notes_create', ['title' => 'QA', 'content' => "# QA\nMilch und Brot\n", 'confirm' => true]);
		$listed = $this->json('notes_list', []);
		$this->assertCount(1, $listed);
		$this->assertSame($created['id'], $listed[0]['id']);
		$read = $this->json('notes_read', ['id' => $listed[0]['id']]);
		$this->assertSame("# QA\nMilch und Brot\n", $read['content']);
		$this->assertSame('/' . $folder . '/QA.md', $read['path']);
		$this->assertSame($listed[0]['etag'], $read['etag']);
		$edited = $this->json('notes_edit', ['id' => $read['id'], 'etag' => $read['etag'], 'content' => "Updated\n", 'confirm' => true]);
		$again = $this->json('notes_read', ['id' => $read['id']]);
		$this->assertSame("Updated\n", $again['content']);
		$this->assertSame($read['path'], $again['path']);
		$this->assertSame($edited['etag'], $again['etag']);
		$this->assertNotSame($read['etag'], $again['etag']);
		$this->assertSame([], $this->gate->logged());
	}

	/**
	 * @param string $tool Published tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string|int, mixed> JSON data in the actual tools/call response.
	 */
	private function json(string $tool, array $arguments): array {
		$reply = $this->gate->call($tool, $arguments);
		$this->assertSame(200, $reply['status']);
		$this->assertObjectNotHasProperty('error', $reply['body']);
		$result = $reply['body']->result;
		$this->assertFalse($result->isError ?? false, json_encode($result));
		return json_decode($result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
	}
}
