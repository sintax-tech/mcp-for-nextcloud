<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Notes;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\Reading\NotesFolderNotice;
use OCA\Mcp\Modules\Notes\Locating\NotesFolderResolver;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\L10N\ILanguageIterator;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23: the notes folder is the one the Notes app uses, including its translated default name.
 *
 * The account speaks German unless a test says otherwise, so the Notes app's default folder is `Notizen` when the
 * untranslated `Notes` does not exist.
 */
final class NotesFolderTest extends TestCase {
	private FakeTree $tree;
	private InMemoryConfig $config;
	/** @var list<string> languages of the account, in the order the factory offers them */
	private array $languages = ['de', 'en'];
	/** Whether the translation factory fails, as it may on a broken install. */
	private bool $factoryFails = false;
	/** Administrator exclusions of the module and repository, null when none apply. */
	private ?VisibilityGuard $guard = null;

	/**
	 * An empty user folder and empty settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->config = new InMemoryConfig();
	}

	/**
	 * The path the Notes app stored in its settings JSON is the notes folder.
	 *
	 * @return void
	 */
	public function testStoredNotesPathIsUsed(): void {
		$this->config->user['alice']['notes']['settings'] = json_encode(['notesPath' => 'Equipe/Notas', 'fileSuffix' => '.md']);
		$this->tree->addFile('/alice/files/Equipe/Notas/Plano.md', 'plano');
		$this->tree->addFile('/alice/files/Notizen/Outra.md', 'outra');

		$this->assertSame('Equipe/Notas', $this->resolver()->path('alice'));
		$this->assertSame(['Plano'], array_column($this->json('notes_list'), 'title'));
	}

	/**
	 * The older standalone `notesPath` value still works when the settings JSON has none.
	 *
	 * @return void
	 */
	public function testStandaloneNotesPathStillWorks(): void {
		$this->config->user['alice']['notes']['settings'] = json_encode(['fileSuffix' => '.md']);
		$this->config->user['alice']['notes']['notesPath'] = 'Equipe';

		$this->assertSame('Equipe', $this->resolver()->path('alice'));
	}

	/**
	 * An empty stored path is the root of the user's files, as in the Notes app ("/" in its settings), never the
	 * default folder: list and search reach notes anywhere in the user's files except what the administrator hides,
	 * and a creation lands in the root without making `Notizen` or `Notes`.
	 *
	 * @return void
	 */
	public function testEmptyStoredNotesPathIsTheRootOfTheUserFiles(): void {
		$this->config->user['alice']['notes']['settings'] = json_encode(['notesPath' => '']);
		$this->tree->addFile('/alice/files/Raiz.md', 'termo na raiz');
		$this->tree->addFile('/alice/files/Docs/Plano.md', 'plano');
		$secret = $this->tree->addFolder('/alice/files/Segredo');
		$this->tree->addFile('/alice/files/Segredo/Oculto.md', 'termo escondido');
		$this->hide([$secret]);

		$this->assertSame('', $this->resolver()->path('alice'));
		$listed = $this->json('notes_list');
		$this->assertEqualsCanonicalizing(['Raiz', 'Plano'], array_column($listed, 'title'));
		$this->assertSame(['Raiz'], array_column($this->json('notes_search', ['query' => 'termo']), 'title'));
		$this->assertSame(['Plano'], array_column($this->json('notes_search', ['query' => 'plano', 'category' => 'Docs']), 'title'));
		$this->assertCount(1, $this->call('notes_list')['content']);

		$created = $this->json('notes_create', ['title' => 'Nova', 'content' => 'x', 'confirm' => true]);
		$this->assertSame(['Nova', ''], [$created['title'], $created['category']]);
		$this->assertArrayHasKey('/alice/files/Nova.md', $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notizen', $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notes', $this->tree->nodes);
	}

	/**
	 * With the root as notes folder, a category below a hidden folder is refused like one that does not exist, and
	 * nothing is created in it.
	 *
	 * @return void
	 */
	public function testRootNotesPathKeepsHiddenFoldersOutOfReach(): void {
		$this->config->user['alice']['notes']['settings'] = json_encode(['notesPath' => '']);
		$secret = $this->tree->addFolder('/alice/files/Segredo');
		$this->hide([$secret]);

		try {
			$this->call('notes_create', ['title' => 'Vaza', 'category' => 'Segredo', 'confirm' => true]);
			$this->fail('notes_create wrote below a hidden folder');
		} catch (ToolFailure $e) {
			$this->assertSame(CommonMessages::notFound(), $e->getMessage());
		}
		$this->assertArrayNotHasKey('/alice/files/Segredo/Vaza.md', $this->tree->nodes);
		$this->assertSame([], $this->json('notes_search', ['query' => 'x', 'category' => 'Segredo']));
	}

	/**
	 * German account, no stored path: the notes in `Notizen` are listed and searched.
	 *
	 * @return void
	 */
	public function testGermanDefaultFolderIsFound(): void {
		$this->tree->addFile('/alice/files/Notizen/Einkauf.md', 'Milch und Brot');

		$this->assertSame('Notizen', $this->resolver()->path('alice'));
		$this->assertSame(['Einkauf'], array_column($this->json('notes_list'), 'title'));
		$this->assertSame(['Einkauf'], array_column($this->json('notes_search', ['query' => 'Brot']), 'title'));
		$this->assertCount(1, $this->call('notes_list')['content']);
	}

	/**
	 * A literal `Notes` folder (made by an earlier MCP version) keeps being used, as the Notes app does.
	 *
	 * @return void
	 */
	public function testLiteralNotesFolderIsKept(): void {
		$this->tree->addFile('/alice/files/Notes/Alt.md', 'alt');

		$this->assertSame('Notes', $this->resolver()->path('alice'));
		$this->assertSame(['Alt'], array_column($this->json('notes_list'), 'title'));
	}

	/**
	 * The administrator's `defaultFolder` of the Notes app replaces `Notes`, translated like it.
	 *
	 * @return void
	 */
	public function testAdministratorDefaultFolderIsHonoured(): void {
		$this->config->app['notes']['defaultFolder'] = 'Memos';
		$this->tree->addFile('/alice/files/Memos/A.md', 'a');

		$this->assertSame('Memos', $this->resolver()->path('alice'));
	}

	/**
	 * Creating in a German account whose `Notizen` exists writes there and creates no `Notes` folder.
	 *
	 * @return void
	 */
	public function testCreateUsesExistingTranslatedFolder(): void {
		$this->tree->addFolder('/alice/files/Notizen');

		$created = $this->json('notes_create', ['title' => 'Neu', 'content' => 'x', 'confirm' => true]);

		$this->assertSame('Neu', $created['title']);
		$this->assertArrayHasKey('/alice/files/Notizen/Neu.md', $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notes', $this->tree->nodes);
	}

	/**
	 * The first creation in a German account without any notes folder uses the name the Notes app would create.
	 *
	 * @return void
	 */
	public function testFirstCreateUsesTheNotesAppName(): void {
		$this->json('notes_create', ['title' => 'Erste', 'confirm' => true]);

		$this->assertArrayHasKey('/alice/files/Notizen/Erste.md', $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notes', $this->tree->nodes);
	}

	/**
	 * Without the Notes translation (English account or failing factory) the default stays `Notes`.
	 *
	 * @return void
	 */
	public function testUntranslatedDefaultWhenNoTranslation(): void {
		$this->languages = ['en'];
		$this->assertSame('Notes', $this->resolver()->path('alice'));

		$this->languages = ['de', 'en'];
		$this->factoryFails = true;
		$this->assertSame('Notes', $this->resolver()->path('alice'));
	}

	/**
	 * No notes folder at all: list and search still answer an empty JSON list, followed by a notice block.
	 *
	 * @return void
	 */
	public function testMissingFolderIsReported(): void {
		foreach (['notes_list' => [], 'notes_search' => ['query' => 'x']] as $tool => $arguments) {
			$result = $this->call($tool, $arguments);

			$this->assertArrayNotHasKey('isError', $result);
			$this->assertSame([], json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
			$this->assertCount(2, $result['content']);
			$notice = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame(['notesFolderFound' => false, 'notice' => NotesFolderNotice::NOTICE], $notice);
		}
		$this->assertArrayNotHasKey('/alice/files/Notizen', $this->tree->nodes);
	}

	/**
	 * The resolver under test, with the German catalogue of the Notes app.
	 *
	 * @return NotesFolderResolver
	 */
	private function resolver(): NotesFolderResolver {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text === 'Notes' ? 'Notizen' : $text);
		$english = $this->createMock(IL10N::class);
		$english->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('getLanguageIterator')->willReturnCallback(function (): ILanguageIterator {
			if ($this->factoryFails) {
				throw new \RuntimeException('broken');
			}
			return self::languages($this->languages);
		});
		$factory->method('languageExists')->willReturnCallback(static fn (string $app, string $lang): bool => $app === 'notes' && $lang === 'de');
		$factory->method('get')->willReturnCallback(static fn (string $app, string $lang): IL10N => $lang === 'de' ? $l10n : $english);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		return new NotesFolderResolver($this->root(), $this->config->mock($this), $factory, $users);
	}

	/**
	 * Root folder double serving the fake tree.
	 *
	 * @return IRootFolder
	 */
	private function root(): IRootFolder {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		return $root;
	}

	/**
	 * Runs a Notes tool for alice through the module, with the resolver wired in.
	 *
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Raw tool arguments.
	 * @return array{content: list<array{type:string, text:string}>, isError?: bool}
	 */
	private function call(string $name, array $arguments = []): array {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		$config = $this->config->mock($this);
		$module = NotesTestModule::build(new NotesRepository($this->root(), $config, $this->guard, $this->resolver()), $apps, $users,
			new SharedWriteGuard($access), $access, $this->guard);
		$definition = array_column($module->definitions(), null, 'name')[$name];
		$published = WriteGate::publish($definition);
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

	/**
	 * Hides the given file ids, and everything below them, with one administrator tag.
	 *
	 * @param list<int> $ids File ids to hide.
	 * @return void
	 */
	private function hide(array $ids): void {
		$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
		$tags = $this->createMock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(static function (array $objects) use ($ids): array {
			$out = [];
			foreach ($objects as $id) {
				$out[(string)$id] = in_array((int)$id, $ids, true) ? ['999'] : [];
			}
			return $out;
		});
		$this->guard = new VisibilityGuard($this->config->mock($this), $tags);
	}

	/**
	 * A language iterator over a fixed list.
	 *
	 * @param list<string> $languages Languages in order.
	 * @return ILanguageIterator
	 */
	private static function languages(array $languages): ILanguageIterator {
		/**
		 * Iterates a fixed list of languages, standing in for the iterator of the translation factory.
		 */
		return new class($languages) implements ILanguageIterator {
			/** Position in the list. */
			private int $i = 0;

			/**
			 * Receives the languages to iterate.
			 *
			 * @param list<string> $languages Languages in order.
			 * @return void
			 */
			public function __construct(private array $languages) {
			}

			/**
			 * The language at the current position.
			 *
			 * @return string Language code.
			 */
			public function current(): string {
				return $this->languages[$this->i];
			}

			/**
			 * Moves to the next language.
			 *
			 * @return void
			 */
			public function next(): void {
				$this->i++;
			}

			/**
			 * The current position.
			 *
			 * @return int Zero-based index.
			 */
			public function key(): int {
				return $this->i;
			}

			/**
			 * Whether the current position holds a language.
			 *
			 * @return bool True while languages remain.
			 */
			public function valid(): bool {
				return isset($this->languages[$this->i]);
			}

			/**
			 * Goes back to the first language.
			 *
			 * @return void
			 */
			public function rewind(): void {
				$this->i = 0;
			}
		};
	}
}
