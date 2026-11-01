<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Notes;

use OCA\Mcp\Service\FileWritePolicy;
use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Tools\Common\FileTypeMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tools\Notes\NoteContentSink;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * File-name policy on notes_create, notes_edit (whole and partial) and notes_move.
 *
 * A null guard is the unit double that omits the container. These tests inject a guard: mode off is the real off
 * switch, and an allowlist that excludes the note extension refuses before any folder or byte is written.
 */
final class NoteTypePolicyTest extends TestCase {
	private const ATA = '/alice/files/Notes/Reuniões/Ata.md';
	private const IDEIA = '/alice/files/Notes/Ideia.txt';

	private const OK = '/alice/files/Notes/Ok.md';

	private FakeTree $tree;
	private InMemoryConfig $config;
	private NotesModule $module;
	private FileTypeGuard $types;
	private string $policyJson = '';
	private ?string $afterPreflight = null;
	private int $policyReads = 0;
	private int $ata = 0;
	private int $ideia = 0;

	/** A notes folder with one Markdown note and one text note, and a plan-state secret. */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->ata = $this->tree->addFile(self::ATA, 'decisões...', 'text/markdown');
		$this->ideia = $this->tree->addFile(self::IDEIA, 'ideia');
		$this->config = new InMemoryConfig();
		$this->config->system['secret'] = 'unit-secret';
	}

	/**
	 * Builds the module around one guard. The stored JSON can change later; each snapshot reads it again.
	 *
	 * @param string $mode off or allowlist.
	 * @param list<string> $extensions Canonical extension list.
	 * @return void
	 */
	private function load(string $mode, array $extensions): void {
		$this->policyReads = 0;
		$this->afterPreflight = null;
		$this->policyJson = $this->encode($mode, $extensions);
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('clearCache');
		$config->method('getValueString')->willReturnCallback(function (string $app = '', string $key = '', string $default = '', bool $lazy = false): string {
			$this->policyReads++;
			if ($this->afterPreflight !== null && $this->policyReads > 1) {
				return $this->afterPreflight;
			}
			return $this->policyJson;
		});
		$this->types = new FileTypeGuard(new FileWritePolicy($config, $this->createMock(IDBConnection::class)));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			static fn (string $app): bool => in_array($app, ['notes', 'files_trashbin'], true),
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		$this->module = new NotesModule(
			new NotesRepository($root, $this->config->mock($this)),
			$apps,
			$users,
			new SharedWriteGuard($access),
			$access,
			null,
			null,
			new PlanState($this->config->mock($this)),
			$this->types,
		);
	}

	/**
	 * @param string $mode off or allowlist.
	 * @param list<string> $extensions Canonical extension list.
	 * @return string Stored policy document.
	 */
	private function encode(string $mode, array $extensions): string {
		return json_encode([
			'version' => 1,
			'mode' => $mode,
			'extensions' => $extensions,
			'allowExtensionless' => false,
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * @param string $name Tool name.
	 * @param array<string, mixed> $arguments Tool arguments, including confirm when the call is a write.
	 * @return array<string, mixed> Decoded tool answer.
	 */
	private function tool(string $name, array $arguments): array {
		$definition = array_column($this->module->definitions(), null, 'name')[$name];
		$published = WriteGate::publish($definition);
		$result = $this->module->call($name, ArgumentValidator::validate($published['inputSchema'], $arguments), 'alice');
		$decoded = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @param callable(): mixed $call Plan or write expected to be refused by the policy.
	 * @return FileTypeDenied The refusal, already checked to hide the allowlist.
	 */
	private function denied(callable $call): FileTypeDenied {
		try {
			$call();
		} catch (FileTypeDenied $e) {
			$this->assertSame(FileTypeMessages::denied(), $e->getMessage());
			$this->assertStringNotContainsString('md', $e->getMessage());
			$this->assertStringNotContainsString('txt', $e->getMessage());
			return $e;
		}
		$this->fail('the call was not denied by the file type policy');
	}

	/**
	 * Keeps the loaded policy for the first snapshot of the next call and serves another policy after that.
	 *
	 * @param string $mode Policy mode read on the second and later snapshots.
	 * @param list<string> $extensions Canonical extension list of that later policy.
	 * @return void
	 */
	private function flipAfterPreflight(string $mode, array $extensions): void {
		$this->policyReads = 0;
		$this->afterPreflight = $this->encode($mode, $extensions);
	}

	/** @param list<string> $forbidden Prefixes that mean a file or folder was created or changed. */
	private function assertNoWrite(array $forbidden = ['mkdir ', 'create ', 'write ', 'move ']): void {
		foreach ($this->tree->ops as $op) {
			foreach ($forbidden as $prefix) {
				$this->assertFalse(str_starts_with($op, $prefix), $op);
			}
		}
	}

	/** An allowlist without md refuses the plan and the write before the category exists. */
	public function testCreateRefusesBeforeCreatingACategory(): void {
		$this->load('allowlist', ['txt']);
		$arguments = ['title' => 'Ata', 'content' => 'nova', 'category' => 'Nova'];
		$this->denied(fn () => $this->module->preview('notes_create', $arguments, 'alice'));
		$this->denied(fn () => $this->tool('notes_create', $arguments + ['confirm' => true]));
		$this->assertArrayNotHasKey('/alice/files/Notes/Nova', $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notes/Nova/Ata.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** md on the allowlist creates the note. */
	public function testCreateAllowsMdAndWritesTheNote(): void {
		$this->load('allowlist', ['md']);
		$created = $this->tool('notes_create', ['title' => 'Pessoal', 'content' => 'x', 'confirm' => true]);
		$this->assertSame('Pessoal', $created['title']);
		$this->assertSame('x', $this->tree->nodes['/alice/files/Notes/Pessoal.md']['content']);
	}

	/** Mode off keeps the previous create behavior. */
	public function testCreateWithPolicyOffIsUnchanged(): void {
		$this->load('off', []);
		$created = $this->tool('notes_create', ['title' => 'Livre', 'content' => 'ok', 'confirm' => true]);
		$this->assertSame('Livre', $created['title']);
		$this->assertSame('ok', $this->tree->nodes['/alice/files/Notes/Livre.md']['content']);
	}

	/** A colon survives title sanitizing, so an allowed md extension still refuses the final basename. */
	public function testCreateRefusesAColonInTheTitleBeforeAnyFile(): void {
		$this->load('allowlist', ['md']);
		$this->denied(fn () => $this->tool('notes_create', ['title' => 'a:b', 'content' => 'x', 'confirm' => true]));
		$this->assertArrayNotHasKey('/alice/files/Notes/a:b.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/**
	 * confirm and confirm_shared do not bypass the policy, and the type check happens before the shared-folder question.
	 */
	public function testConfirmTrueDoesNotBypassThePolicy(): void {
		$this->load('allowlist', ['txt']);
		$this->config->user['alice']['notes']['notesPath'] = 'Equipe';
		$this->tree->addFolder('/alice/files/Equipe', ['scope' => 'team']);
		$this->tree->mountPath = '/alice/files/Equipe';
		$this->denied(fn () => $this->tool('notes_create', [
			'title' => 'Nova',
			'content' => 'x',
			'confirm' => true,
			'confirm_shared' => true,
		]));
		$this->assertArrayNotHasKey('/alice/files/Equipe/Nova.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** Content whose own name is allowed is not saved when the new title is refused. */
	public function testWholeEditRefusesADeniedTitleBeforeWritingContent(): void {
		$this->load('allowlist', ['md']);
		$this->denied(fn () => $this->module->preview('notes_edit', [
			'id' => $this->ata,
			'content' => 'depois',
			'title' => 'bad:name',
		], 'alice'));
		$this->denied(fn () => $this->tool('notes_edit', [
			'id' => $this->ata,
			'content' => 'depois',
			'title' => 'bad:name',
			'confirm' => true,
		]));
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertArrayNotHasKey('/alice/files/Notes/Reuniões/bad:name.md', $this->tree->nodes);
		$this->assertNoWrite();

		$this->policyJson = $this->encode('allowlist', ['txt']);
		$note = $this->tree->node(self::ATA);
		$this->assertInstanceOf(File::class, $note);
		$write = (new NoteContentSink($this->types))->whole($note, 'depois', [$note->getName()]);
		$this->denied($write);
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertNoWrite();
	}

	/** The partial writer runs the approved-plan recheck and still refuses before the bytes are stored. */
	public function testPartialWriterRefusesBeforePutContent(): void {
		$this->load('allowlist', ['txt']);
		$note = $this->tree->node(self::ATA);
		$this->assertInstanceOf(File::class, $note);
		$rechecked = false;
		$write = (new NoteContentSink($this->types))->partial($note, 'depois', function () use (&$rechecked): void {
			$rechecked = true;
		}, [$note->getName()]);
		$this->denied($write);
		$this->assertTrue($rechecked);
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertNoWrite();
	}

	/** A plan approved while md was allowed is refused when the confirm reads a txt-only policy. */
	public function testPartialConfirmRefusesAfterThePolicyFlips(): void {
		$this->load('allowlist', ['md']);
		$plan = $this->module->preview('notes_edit', ['id' => $this->ata, 'append' => 'linha'], 'alice');
		$this->policyJson = $this->encode('allowlist', ['txt']);
		$this->denied(fn () => $this->tool('notes_edit', [
			'id' => $this->ata,
			'append' => 'linha',
			'etag' => $plan['etag'],
			'plan_state' => $plan['plan_state'],
			'confirm' => true,
		]));
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertNoWrite();
	}

	/** A replace plan of a denied note is refused and writes nothing. */
	public function testReplacePreviewRefusesADeniedNote(): void {
		$this->load('allowlist', ['txt']);
		$this->denied(fn () => $this->module->preview('notes_edit', [
			'id' => $this->ata,
			'old' => 'decisões',
			'new' => 'outro',
		], 'alice'));
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertNoWrite();
	}

	/** A text note can be rewritten when txt is the allowed extension. */
	public function testTxtNoteEditSucceedsWhenTxtIsAllowed(): void {
		$this->load('allowlist', ['txt']);
		$edited = $this->tool('notes_edit', ['id' => $this->ideia, 'content' => 'nova ideia', 'confirm' => true]);
		$this->assertSame('Ideia', $edited['title']);
		$this->assertSame('nova ideia', $this->tree->nodes[self::IDEIA]['content']);
	}

	/** notes_move refuses before the target category is created. */
	public function testMoveRefusesBeforeCreatingACategory(): void {
		$this->load('allowlist', ['txt']);
		$arguments = ['id' => $this->ata, 'category' => 'Arquivo'];
		$this->denied(fn () => $this->module->preview('notes_move', $arguments, 'alice'));
		$this->denied(fn () => $this->tool('notes_move', $arguments + ['confirm' => true]));
		$this->assertArrayHasKey(self::ATA, $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notes/Arquivo', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** The module entry point is guarded too, including a move that would otherwise change nothing. */
	public function testMoveDelegationIsGuarded(): void {
		$this->load('allowlist', ['txt']);
		$this->denied(fn () => $this->tool('notes_move', [
			'id' => $this->ata,
			'category' => 'Reuniões',
			'confirm' => true,
		]));
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertNoWrite();
	}

	/**
	 * A policy that becomes allowlist(md) after the preflight still refuses the final title before any byte is stored.
	 */
	public function testWholeEditRevalidatesTheFinalTitleBeforePutContent(): void {
		$id = $this->tree->addFile(self::OK, 'antes', 'text/markdown');
		$this->load('off', []);
		$this->flipAfterPreflight('allowlist', ['md']);
		$this->denied(fn () => $this->tool('notes_edit', [
			'id' => $id,
			'content' => 'depois',
			'title' => 'bad:name',
			'confirm' => true,
		]));
		$this->assertSame(2, $this->policyReads);
		$this->assertSame('antes', $this->tree->nodes[self::OK]['content']);
		$this->assertArrayNotHasKey('/alice/files/Notes/bad:name.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/**
	 * The same flip refuses an approved append before putContent when the new title is not allowed.
	 */
	public function testPartialEditRevalidatesTheFinalTitleBeforePutContent(): void {
		$id = $this->tree->addFile(self::OK, 'antes', 'text/markdown');
		$this->load('off', []);
		$plan = $this->module->preview('notes_edit', [
			'id' => $id,
			'append' => ' mais',
			'title' => 'bad:name',
		], 'alice');
		$this->flipAfterPreflight('allowlist', ['md']);
		$this->denied(fn () => $this->tool('notes_edit', [
			'id' => $id,
			'append' => ' mais',
			'title' => 'bad:name',
			'etag' => $plan['etag'],
			'plan_state' => $plan['plan_state'],
			'confirm' => true,
		]));
		$this->assertSame(2, $this->policyReads);
		$this->assertSame('antes', $this->tree->nodes[self::OK]['content']);
		$this->assertArrayNotHasKey('/alice/files/Notes/bad:name.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** The create sink refuses the real basename when the policy changes after the preflight allowed it. */
	public function testCreateSinkRefusesWhenThePolicyFlipsAfterThePreflight(): void {
		$this->load('off', []);
		$this->flipAfterPreflight('allowlist', ['txt']);
		$this->denied(fn () => $this->tool('notes_create', ['title' => 'Pessoal', 'content' => 'x', 'confirm' => true]));
		$this->assertSame(2, $this->policyReads);
		$this->assertArrayNotHasKey('/alice/files/Notes/Pessoal.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** A title-only rename reaches the rename sink, which refuses the destination after the preflight allowed it. */
	public function testRenameSinkRefusesWhenThePolicyFlipsAfterThePreflight(): void {
		$this->load('off', []);
		$this->flipAfterPreflight('allowlist', ['md']);
		$this->denied(fn () => $this->tool('notes_edit', [
			'id' => $this->ata,
			'title' => 'bad:name',
			'confirm' => true,
		]));
		$this->assertSame(2, $this->policyReads);
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		$this->assertArrayNotHasKey('/alice/files/Notes/Reuniões/bad:name.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** A category move reaches the rename sink after the target folder exists, and the note stays where it was. */
	public function testMoveSinkRefusesWhenThePolicyFlipsAfterThePreflight(): void {
		$this->load('off', []);
		$this->flipAfterPreflight('allowlist', ['txt']);
		$this->denied(fn () => $this->tool('notes_move', [
			'id' => $this->ata,
			'category' => 'Arquivo',
			'confirm' => true,
		]));
		$this->assertSame(2, $this->policyReads);
		$this->assertArrayHasKey('/alice/files/Notes/Arquivo', $this->tree->nodes);
		$this->assertArrayHasKey(self::ATA, $this->tree->nodes);
		$this->assertArrayNotHasKey('/alice/files/Notes/Arquivo/Ata.md', $this->tree->nodes);
		$this->assertSame('decisões...', $this->tree->nodes[self::ATA]['content']);
		foreach ($this->tree->ops as $op) {
			$this->assertFalse(str_starts_with($op, 'move '), $op);
		}
	}

	/** notes_delete keeps the trash rule and does not consult the extension allowlist. */
	public function testDeleteStillTrashesAnMdNoteWhenMdIsDenied(): void {
		$this->load('allowlist', ['txt']);
		$deleted = $this->tool('notes_delete', ['id' => $this->ata, 'confirm' => true]);
		$this->assertSame(['id' => $this->ata, 'deleted' => true, 'trash' => true], $deleted);
		$this->assertArrayNotHasKey(self::ATA, $this->tree->nodes);
	}

	/** An unreadable policy refuses the note write and is not treated as off. */
	public function testUnavailablePolicyRefusesTheWriteAndCreatesNothing(): void {
		$this->load('allowlist', ['md']);
		$this->policyJson = 'not-json';
		try {
			$this->tool('notes_create', ['title' => 'Quebrada', 'content' => 'x', 'confirm' => true]);
			$this->fail('an unreadable policy was treated as allowed');
		} catch (FileWritePolicyUnavailable $e) {
			$this->assertSame(FileTypeMessages::unavailable(), $e->getMessage());
		}
		$this->assertArrayNotHasKey('/alice/files/Notes/Quebrada.md', $this->tree->nodes);
		$this->assertNoWrite();
	}

	/** ContactBackup is exempt by service: its source never reads the file-name policy. */
	public function testContactBackupNeverReadsTheFileTypePolicy(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Tools/Contacts/ContactBackup.php');
		$this->assertNotSame('', $source);
		$this->assertDoesNotMatchRegularExpression('/FileTypeGuard|FileWritePolicy|file_write_policy/', $source);
	}
}
