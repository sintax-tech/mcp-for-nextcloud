<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Notes;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * notes_list and notes_search through their handlers: order, snippets, limit and category, exactly as before the
 * handlers were extracted (issue #23). The expected snippets were taken from the module before the extraction.
 */
final class NotesListSearchHandlerTest extends TestCase {
	private FakeTree $tree;
	private InMemoryConfig $config;

	/**
	 * A notes folder with a long note, a short one, a large one and one in a category.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile('/alice/files/Notes/Longa.md', str_repeat("linha\n", 20) . 'ALVO aqui' . str_repeat(' fim', 60));
		$this->tree->addFile('/alice/files/Notes/Curta.md', "Alvo no título?\n\ncorpo   curto");
		$this->tree->addFile('/alice/files/Notes/Grande alvo.md', 'alvo', 'text/plain', ['size' => NotesRepository::MAX_BYTES + 1]);
		$this->tree->addFile('/alice/files/Notes/Projetos/Plano.md', 'sem o termo');
		$this->config = new InMemoryConfig();
	}

	/**
	 * notes_list returns every note, newest first, with one content block.
	 *
	 * @return void
	 */
	public function testListIsNewestFirst(): void {
		$result = $this->call('notes_list');

		$this->assertCount(1, $result['content']);
		$titles = array_column(json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR), 'title');
		$this->assertSame(['Plano', 'Grande alvo', 'Curta', 'Longa'], $titles);
	}

	/**
	 * A content match is cut around the match, a title-only match shows the start of the note, and a note over the
	 * read limit matches by title with an empty snippet.
	 *
	 * @return void
	 */
	public function testSearchSnippets(): void {
		$hits = $this->json('notes_search', ['query' => 'alvo']);

		$this->assertSame(['Grande alvo', 'Curta', 'Longa'], array_column($hits, 'title'));
		$this->assertSame(['', 'Alvo no título? corpo curto', '...a linha linha linha linha linha linha linha linha ALVO aqui fim fim fim fim fim fim fim fim fim fim fim fim fim fim fim fim...'], array_column($hits, 'snippet'));
		$this->assertArrayHasKey('access', $hits[0]);
	}

	/**
	 * The limit stops the search and the category narrows it; an unknown category finds nothing.
	 *
	 * @return void
	 */
	public function testSearchLimitAndCategory(): void {
		$this->assertSame(['Grande alvo'], array_column($this->json('notes_search', ['query' => 'alvo', 'limit' => 1]), 'title'));
		$this->assertSame(['Plano'], array_column($this->json('notes_search', ['query' => 'termo', 'category' => 'Projetos']), 'title'));
		$this->assertSame([], $this->json('notes_search', ['query' => 'alvo', 'category' => 'Projetos']));
		$this->assertSame([], $this->json('notes_search', ['query' => 'alvo', 'category' => 'Nada']));
		$this->assertCount(1, $this->call('notes_search', ['query' => 'alvo', 'category' => 'Nada'])['content']);
	}

	/**
	 * Runs a Notes tool for alice.
	 *
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Raw tool arguments.
	 * @return array{content: list<array{type:string, text:string}>, isError?: bool}
	 */
	private function call(string $name, array $arguments = []): array {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		$module = new NotesModule(new NotesRepository($root, $this->config->mock($this)), $apps, $users,
			new SharedWriteGuard($access), $access);
		$published = WriteGate::publish(array_column($module->definitions(), null, 'name')[$name]);
		return $module->call($name, ArgumentValidator::validate($published['inputSchema'], $arguments), 'alice');
	}

	/**
	 * The first content block of a tool answer, decoded.
	 *
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Raw tool arguments.
	 * @return array<mixed> Decoded JSON.
	 */
	private function json(string $name, array $arguments = []): array {
		return json_decode($this->call($name, $arguments)['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
