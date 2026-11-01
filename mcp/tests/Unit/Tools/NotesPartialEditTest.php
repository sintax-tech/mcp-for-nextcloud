<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use InvalidArgumentException;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLock;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLockManager;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\UnifiedDiff;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tools\Notes\NoteChangePlan;
use OCA\Mcp\Tools\Notes\NotesMessages;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NT-1: notes_edit changes a note with a small call. `append` adds at the end and `old`/`new` replaces one unique
 * passage, both applied to the note as it is when the call arrives and tied to the plan by the etag and the
 * plan_state of the content and the arguments; `content` still rewrites the whole note. The plan carries a bounded
 * diff, the etag and the plan_state, never the note's content.
 */
final class NotesPartialEditTest extends TestCase {
	private const PATH = '/alice/files/Notes/Chamados/Outubro.md';

	private FakeTree $tree;
	private InMemoryConfig $config;
	private FakeLockManager $locks;
	private NotesModule $module;
	private int $id;

	/** A note in a category, the lock manager and the plan states the module needs. */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->id = $this->tree->addFile(self::PATH, "# Chamados\n- 101 aberto\n", 'text/markdown');
		$this->config = new InMemoryConfig();
		$this->config->system['secret'] = 'unit-secret';
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		$this->locks = new FakeLockManager();
		$this->module = new NotesModule(new NotesRepository($root, $this->config->mock($this)), $apps, $users,
			new SharedWriteGuard($access), $access, null, $this->locks->service($this), new PlanState($this->config->mock($this)));
	}

	/**
	 * @param array<string, mixed> $arguments notes_edit arguments as a client sends them
	 * @return array<string, mixed> the published-schema-validated arguments
	 */
	private function validated(array $arguments): array {
		$definition = array_column($this->module->definitions(), null, 'name')['notes_edit'];
		return ArgumentValidator::validate(WriteGate::publish($definition)['inputSchema'], $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments notes_edit arguments, without confirm
	 * @return array<string, mixed> the decoded answer of a confirmed notes_edit
	 */
	private function write(array $arguments): array {
		$result = $this->module->call('notes_edit', $this->validated($arguments + ['confirm' => true]), 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array<string, mixed> $arguments notes_edit arguments
	 * @return array<string, mixed> the plan of notes_edit
	 */
	private function plan(array $arguments): array {
		return $this->module->preview('notes_edit', $this->validated($arguments), 'alice');
	}

	/**
	 * @param array<string, mixed> $arguments notes_edit arguments, without etag and plan_state
	 * @return array<string, mixed> the answer of the call confirmed the way a client does: plan first, then its etag and plan_state
	 */
	private function approve(array $arguments): array {
		$plan = $this->plan($arguments);
		return $this->write($arguments + ['etag' => $plan['etag'], PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]]);
	}

	/**
	 * @param callable(): mixed $call a confirmed call
	 * @return array<string, mixed> the new plan it answered with instead of writing
	 */
	private function changedPlan(callable $call): array {
		try {
			$call();
		} catch (PlanChanged $e) {
			return $e->plan;
		}
		$this->fail('the confirmed call did not answer with a new plan');
	}

	/**
	 * @param string $path absolute path in the fake tree
	 * @return string the etag stored there
	 */
	private function etag(string $path = self::PATH): string {
		return $this->tree->nodes[$path]['etag'];
	}

	/**
	 * @param string $path absolute path in the fake tree
	 * @return string the content stored there
	 */
	private function content(string $path = self::PATH): string {
		return $this->tree->nodes[$path]['content'];
	}

	/**
	 * @param callable(): mixed $call a plan or a write expected to be refused
	 * @return string the message of the ToolFailure the call raised
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('the call was not refused');
	}

	/** @param string $before the note's content before the call; it must still be there, with no write or move */
	private function assertNothingWritten(string $before): void {
		$this->assertSame($before, $this->content());
		$this->assertSame([], array_filter($this->tree->ops, static fn (string $op): bool => str_starts_with($op, 'write') || str_starts_with($op, 'move')));
	}

	/** The same seven tools; notes_edit only gains parameters. */
	public function testTheEditSchemaGainsAppendOldNewAndPlanStateWithoutANewTool(): void {
		$definitions = array_column($this->module->definitions(), null, 'name');
		$this->assertCount(7, $definitions);
		$edit = $definitions['notes_edit']['inputSchema'];
		$this->assertSame(['id', 'append', 'old', 'new', 'content', 'title', 'etag', 'plan_state', 'confirm_shared'], array_keys($edit['properties']));
		$this->assertSame(['id'], $edit['required']);
		$this->assertSame(1, $edit['properties']['append']['minLength']);
		$this->assertSame(1, $edit['properties']['old']['minLength']);
		$this->assertArrayNotHasKey('minLength', $edit['properties']['new']);
	}

	/** @return array<string, array{array<string, string>}> */
	public static function mixedModes(): array {
		return [
			'content and append' => [['content' => 'x', 'append' => 'y']],
			'content and old/new' => [['content' => 'x', 'old' => '101', 'new' => '102']],
			'append and old/new' => [['append' => 'y', 'old' => '101', 'new' => '102']],
			'content and only new' => [['content' => 'x', 'new' => '102']],
		];
	}

	#[DataProvider('mixedModes')]
	/** @param array<string, string> $modes two modes sent together */
	public function testTheModesExcludeEachOtherInThePlanAndTheWrite(array $modes): void {
		$before = $this->content();
		$arguments = ['id' => $this->id, 'etag' => $this->etag()] + $modes;
		$this->assertSame(NotesMessages::editModesExclusive(), $this->refusal(fn () => $this->plan($arguments)));
		$this->assertSame(NotesMessages::editModesExclusive(), $this->refusal(fn () => $this->write($arguments)));
		$this->assertNothingWritten($before);
	}

	/** A replace needs both halves. */
	public function testOldNeedsNewAndTheOtherWayRound(): void {
		foreach ([['old' => '101', 'missing' => 'new'], ['new' => '102', 'missing' => 'old']] as $case) {
			$missing = $case['missing'];
			unset($case['missing']);
			try {
				$this->write(['id' => $this->id, 'etag' => $this->etag()] + $case);
				$this->fail('a replace without ' . $missing . ' was accepted');
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Missing argument: ' . $missing, $e->getMessage());
			}
		}
		$this->assertSame("# Chamados\n- 101 aberto\n", $this->content());
	}

	/** Without content, append, old/new or title there is nothing to do. */
	public function testAnEditWithNothingToChangeIsStillAnArgumentError(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing argument: content');
		$this->write(['id' => $this->id, 'etag' => $this->etag()]);
	}

	/** The answer is the note's metadata and its new etag, never its content. */
	public function testReplaceSwapsTheOnlyOccurrenceAndAnswersWithTheNewEtagButNotTheContent(): void {
		$etag = $this->etag();
		$out = $this->approve(['id' => $this->id, 'old' => '101 aberto', 'new' => '101 fechado']);

		$this->assertSame("# Chamados\n- 101 fechado\n", $this->content());
		$this->assertSame($this->etag(), $out['etag']);
		$this->assertNotSame($etag, $out['etag']);
		$this->assertSame(['id', 'title', 'category', 'modified', 'etag', 'access'], array_keys($out));
	}

	/** Absent or repeated passages are refused in the plan and in the write. */
	public function testReplaceRefusesAMissingOrRepeatedPassageAndPointsToALongerOne(): void {
		$this->tree->nodes[self::PATH]['content'] = "- 101 aberto\n- 102 aberto\n";
		$before = $this->content();
		// A confirmed call whose plan_state does not match builds the new plan, which refuses the same way.
		$missing = ['id' => $this->id, 'old' => '103', 'new' => 'x', 'etag' => $this->etag(), PlanState::ARGUMENT => 'x'];
		$repeated = ['id' => $this->id, 'old' => 'aberto', 'new' => 'fechado', 'etag' => $this->etag(), PlanState::ARGUMENT => 'x'];

		$this->assertSame(FilesMessages::snippetMissing(), $this->refusal(fn () => $this->plan($missing)));
		$this->assertSame(FilesMessages::snippetMissing(), $this->refusal(fn () => $this->write($missing)));
		$this->assertSame(FilesMessages::snippetAmbiguous(), $this->refusal(fn () => $this->plan($repeated)));
		$this->assertSame(FilesMessages::snippetAmbiguous(), $this->refusal(fn () => $this->write($repeated)));
		$this->assertNothingWritten($before);
	}

	/** NT-1 F1: "ana" sits at offsets 1 and 3 of "banana": overlapping matches are two, in the plan and in the write. */
	public function testReplaceRefusesAnOverlappingPassageInThePlanAndTheWrite(): void {
		$this->tree->nodes[self::PATH]['content'] = 'banana';
		$arguments = ['id' => $this->id, 'old' => 'ana', 'new' => 'X'];
		$this->assertSame(FilesMessages::snippetAmbiguous(), $this->refusal(fn () => $this->plan($arguments)));
		$confirmed = $arguments + ['etag' => $this->etag(), PlanState::ARGUMENT => 'x'];
		$this->assertSame(FilesMessages::snippetAmbiguous(), $this->refusal(fn () => $this->write($confirmed)));
		$this->assertNothingWritten('banana');
	}

	/** NT-1 F6: an ambiguous 64 KiB passage in a 1 MiB note is refused through NoteChange::apply in well under a second. */
	public function testAnAmbiguousReplaceInALargeNoteIsRefusedQuickly(): void {
		$started = hrtime(true);
		try {
			\OCA\Mcp\Tools\Notes\NoteChange::apply('replace', str_repeat('a', 1048576), ['old' => str_repeat('a', 65536), 'new' => 'X']);
			$this->fail('the replace was accepted');
		} catch (ToolFailure $e) {
			$this->assertSame(FilesMessages::snippetAmbiguous(), $e->getMessage());
		}
		$this->assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
	}

	/**
	 * The passage is capped at 64 KiB: the schema bounds characters, the server bytes, so a multibyte passage under the
	 * character bound but over the byte one is refused in the plan and in the write, and one at the limit goes through.
	 */
	public function testThePassageIsLimitedTo64KibInCharactersAndBytes(): void {
		$limit = \OCA\Mcp\Tools\Files\UniqueSnippet::MAX_BYTES;
		$this->tree->nodes[self::PATH]['content'] = '<' . str_repeat('a', $limit) . '>' . str_repeat('ç', $limit / 2 + 1);
		$before = $this->content();
		try {
			$this->plan(['id' => $this->id, 'old' => str_repeat('a', $limit + 1), 'new' => 'X']);
			$this->fail('a passage over maxLength passed the schema');
		} catch (ArgumentValidationException) {
			$this->addToAssertionCount(1);
		}
		$multibyte = ['id' => $this->id, 'old' => str_repeat('ç', $limit / 2 + 1), 'new' => 'X'];
		$this->assertStringContainsString((string)$limit, $this->refusal(fn () => $this->plan($multibyte)));
		$confirmed = $multibyte + ['etag' => $this->etag(), PlanState::ARGUMENT => 'x'];
		$this->assertStringContainsString((string)$limit, $this->refusal(fn () => $this->write($confirmed)));
		$this->assertNothingWritten($before);

		$this->approve(['id' => $this->id, 'old' => str_repeat('a', $limit), 'new' => 'X']);
		$this->assertSame('<X>' . str_repeat('ç', $limit / 2 + 1), $this->content());
	}

	/** Bytes that are not text cannot be located. */
	public function testReplaceRefusesAPassageThatIsNotUtf8(): void {
		$before = $this->content();
		$message = $this->refusal(fn () => $this->write(['id' => $this->id, 'old' => "\xC3", 'new' => 'x', 'etag' => $this->etag(), PlanState::ARGUMENT => 'x']));
		$this->assertSame(FilesMessages::snippetNotUtf8(), $message);
		$this->assertNothingWritten($before);
	}

	/** @return array<string, array{string, string, string}> */
	public static function appends(): array {
		return [
			'LF, ends with a newline' => ["a\nb\n", 'c', "a\nb\nc\n"],
			'LF, no newline at the end' => ["a\nb", 'c', "a\nb\nc"],
			'LF, text with its own newline' => ["a\n", "c\n", "a\nc\n"],
			'CRLF, ends with a newline' => ["a\r\nb\r\n", "c\nd", "a\r\nb\r\nc\r\nd\r\n"],
			'CRLF, no newline at the end' => ["a\r\nb", "c\r\nd", "a\r\nb\r\nc\r\nd"],
			'single line without newline' => ['a', 'c', "a\nc"],
			'empty note' => ['', 'c', 'c'],
		];
	}

	#[DataProvider('appends')]
	/**
	 * @param string $note content before
	 * @param string $text text appended
	 * @param string $expected content after
	 */
	public function testAppendAddsALineInTheLineEndingOfTheNote(string $note, string $text, string $expected): void {
		$this->tree->nodes[self::PATH]['content'] = $note;
		$this->approve(['id' => $this->id, 'append' => $text]);
		$this->assertSame($expected, $this->content());
	}

	/** The confirmed call of a small change always says which version it applies to. */
	public function testAConfirmedAppendOrReplaceNeedsTheEtag(): void {
		$before = $this->content();
		$this->assertSame(NotesMessages::editNeedsEtag(), $this->refusal(fn () => $this->write(['id' => $this->id, 'append' => 'x'])));
		$this->assertSame(NotesMessages::editNeedsEtag(), $this->refusal(fn () => $this->write(['id' => $this->id, 'old' => '101', 'new' => '9', 'etag' => ' '])));
		$this->assertNothingWritten($before);
	}

	/** A stale etag is refused in the plan and in the write, with the current one in the message. */
	public function testAStaleEtagIsRefusedWithTheCurrentOneAndNothingIsWritten(): void {
		$before = $this->content();
		$current = $this->etag();
		foreach ([['append' => 'x'], ['old' => '101', 'new' => '102']] as $mode) {
			$arguments = ['id' => $this->id, 'etag' => 'velho'] + $mode;
			$this->assertSame(NotesMessages::changedSinceRead($current), $this->refusal(fn () => $this->write($arguments)));
			$this->assertSame(NotesMessages::changedSinceRead($current), $this->refusal(fn () => $this->plan($arguments)));
		}
		$this->assertStringContainsString($current, NotesMessages::changedSinceRead($current));
		$this->assertNothingWritten($before);
	}

	/** The whole-content mode behaves as before. */
	public function testAContentRewriteKeepsTheEtagOptionalAndItsOldConflictMessage(): void {
		$this->assertSame(ToolFailure::CONFLICT, $this->refusal(fn () => $this->write(['id' => $this->id, 'content' => 'x', 'etag' => 'velho'])));
		$this->write(['id' => $this->id, 'content' => 'novo']);
		$this->assertSame('novo', $this->content());
	}

	/** The plan carries sizes, the change, the diff, the etag and the plan_state, not the note. */
	public function testThePlanCarriesTheDiffAndTheEtagButNotTheNote(): void {
		$etag = $this->etag();
		$plan = $this->plan(['id' => $this->id, 'append' => '- 102 aberto']);

		$this->assertSame('append', $plan['mode']);
		$this->assertSame($etag, $plan['etag']);
		$this->assertSame(['content'], $plan['changed']);
		$this->assertSame(['append' => '- 102 aberto'], $plan['change']);
		$this->assertStringContainsString("+- 102 aberto", $plan['diff']);
		$this->assertSame(['title' => 'Outubro', 'category' => 'Chamados', 'bytes' => 24], $plan['note']['before']);
		$this->assertSame(37, $plan['note']['after']['bytes']);
		$this->assertArrayNotHasKey('content', $plan['note']['before']);
		$this->assertArrayNotHasKey('content', $plan['note']['after']);
		$this->assertSame(NotesMessages::planAppend(), $plan['message']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan[PlanState::ARGUMENT]);
		$this->assertSame([], $plan['shared']);
		$this->assertNothingWritten("# Chamados\n- 101 aberto\n");
	}

	/** The diff is cut at its limit and keeps only the context around the passage. */
	public function testTheDiffOfALargeChangeIsBoundedAndShowsOnlyThePassage(): void {
		$far = str_repeat("linha antiga que não muda\n", 200);
		$this->tree->nodes[self::PATH]['content'] = "MARCA\n" . $far;
		$plan = $this->plan(['id' => $this->id, 'old' => 'MARCA', 'new' => str_repeat("nova\n", 1000), 'etag' => $this->etag()]);

		$this->assertLessThanOrEqual(NoteChangePlan::DIFF_CHARS + 1 + mb_strlen(UnifiedDiff::TRUNCATED), mb_strlen($plan['diff']));
		$this->assertStringEndsWith(UnifiedDiff::TRUNCATED, $plan['diff']);
		$this->assertLessThan(strlen($far), strlen((string)json_encode($plan)));
		$small = $this->plan(['id' => $this->id, 'old' => 'MARCA', 'new' => 'NOVA', 'etag' => $this->etag()]);
		$this->assertSame(3, substr_count($small['diff'], 'linha antiga'), 'only the context lines around the passage');
	}

	/** A change in between is refused, and the new plan applies on top of it. */
	public function testTheConfirmAppliesTheChangeToTheNoteAsItIsNow(): void {
		$first = $this->plan(['id' => $this->id, 'append' => '- 102 aberto']);
		// Somebody else edits the note between the plan and the confirmation.
		$this->tree->nodes[self::PATH]['content'] = "# Chamados\n- 101 fechado\n";
		$this->tree->nodes[self::PATH]['etag'] .= '+web';

		$stale = $this->refusal(fn () => $this->write(['id' => $this->id, 'append' => '- 102 aberto', 'etag' => $first['etag']]));
		$this->assertSame(NotesMessages::changedSinceRead($this->etag()), $stale);
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->content());

		$again = $this->plan(['id' => $this->id, 'append' => '- 102 aberto']);
		$this->write(['id' => $this->id, 'append' => '- 102 aberto', 'etag' => $again['etag'], PlanState::ARGUMENT => $again[PlanState::ARGUMENT]]);
		$this->assertSame("# Chamados\n- 101 fechado\n- 102 aberto\n", $this->content());
	}

	/** A title can go with a small change. */
	public function testATitleGoesWithAnAppend(): void {
		$plan = $this->plan(['id' => $this->id, 'append' => 'x', 'title' => 'Novembro', 'etag' => $this->etag()]);
		$this->assertSame(['title', 'content'], $plan['changed']);
		$this->assertSame('Novembro', $plan['note']['after']['title']);

		$out = $this->approve(['id' => $this->id, 'append' => 'x', 'title' => 'Novembro']);
		$this->assertSame('Novembro', $out['title']);
		$this->assertSame("# Chamados\n- 101 aberto\nx\n", $this->content('/alice/files/Notes/Chamados/Novembro.md'));
	}

	/** The result counts against the note size limit. */
	public function testAResultOverTheLimitIsRefusedBeforeWriting(): void {
		$before = $this->content();
		$big = str_repeat('x', NotesModule::MAX_BYTES);
		$message = $this->refusal(fn () => $this->write(['id' => $this->id, 'append' => $big, 'etag' => $this->etag(), PlanState::ARGUMENT => 'x']));
		$this->assertSame(NotesMessages::noteTooLarge(NotesModule::MAX_BYTES), $message);
		$this->assertNothingWritten($before);
	}

	/** files_lock: the plan warns and the write is refused before anything changes. */
	public function testANoteOpenInTextRefusesTheAppendAndThePlanWarns(): void {
		$this->locks->put(new FakeLock($this->id, ILock::TYPE_APP, 'text'));
		$before = $this->content();
		$plan = $this->plan(['id' => $this->id, 'append' => 'x']);
		$this->assertArrayHasKey('lock', $plan);

		try {
			$this->approve(['id' => $this->id, 'append' => 'x']);
			$this->fail('an append to a locked note was written');
		} catch (LockWriteFailure $e) {
			$this->assertStringContainsString('Nothing was changed.', $e->getMessage());
		}
		$this->assertNothingWritten($before);
	}

	/** The shared-write confirmation still comes before the replace. */
	public function testASharedNoteAsksForConfirmationBeforeTheReplace(): void {
		$this->config->user['alice']['notes']['notesPath'] = 'Equipe';
		$id = $this->tree->addFile('/alice/files/Equipe/Ata.md', "decisões\n", 'text/markdown', ['scope' => 'shared']);
		$path = '/alice/files/Equipe/Ata.md';
		$arguments = ['id' => $id, 'old' => 'decisões', 'new' => 'decisões finais', 'etag' => $this->etag($path)];
		$plan = $this->plan($arguments);
		$arguments[PlanState::ARGUMENT] = $plan[PlanState::ARGUMENT];

		$this->assertNotSame([], $plan['shared']);
		$asked = $this->write($arguments);
		$this->assertTrue($asked['requiresConfirmation']);
		$this->assertSame("decisões\n", $this->content($path));

		$this->write($arguments + ['confirm_shared' => true]);
		$this->assertSame("decisões finais\n", $this->content($path));
	}

	/** A note Nextcloud will not update is refused in the plan and in the write. */
	public function testAReadOnlyNoteRefusesTheAppendEitherWay(): void {
		$this->tree->nodes[self::PATH]['updateable'] = false;
		$before = $this->content();
		$this->assertSame(ToolFailure::FORBIDDEN, $this->refusal(fn () => $this->plan(['id' => $this->id, 'append' => 'x'])));
		$this->assertSame(ToolFailure::FORBIDDEN, $this->refusal(fn () => $this->write(['id' => $this->id, 'append' => 'x', 'etag' => $this->etag(), PlanState::ARGUMENT => 'x'])));
		$this->assertNothingWritten($before);
	}

	/** The confirmed call of a small change gives back the plan_state of its plan. */
	public function testAConfirmedChangeWithoutThePlanStateIsAnArgumentError(): void {
		$before = $this->content();
		$this->expectException(ArgumentValidationException::class);
		try {
			$this->write(['id' => $this->id, 'append' => 'x', 'etag' => $this->etag()]);
		} finally {
			$this->assertNothingWritten($before);
		}
	}

	/** @return array<string, array{array<string, string>, array<string, string>}> what was approved, what was confirmed */
	public static function otherArguments(): array {
		return [
			'another append' => [['append' => 'x'], ['append' => 'y']],
			'another old' => [['old' => '101', 'new' => '9'], ['old' => 'aberto', 'new' => '9']],
			'another new' => [['old' => '101', 'new' => '9'], ['old' => '101', 'new' => '8']],
			'a title added' => [['append' => 'x'], ['append' => 'x', 'title' => 'Novembro']],
			'another title' => [['append' => 'x', 'title' => 'Novembro'], ['append' => 'x', 'title' => 'Dezembro']],
			'replace instead of append' => [['append' => '9'], ['old' => '101', 'new' => '9']],
		];
	}

	#[DataProvider('otherArguments')]
	/**
	 * @param array<string, string> $approved arguments of the plan
	 * @param array<string, string> $confirmed arguments of the confirmed call
	 */
	public function testTheStateOfOnePlanNeverConfirmsOtherArguments(array $approved, array $confirmed): void {
		$before = $this->content();
		$plan = $this->plan(['id' => $this->id] + $approved);
		$arguments = ['id' => $this->id, 'etag' => $plan['etag'], PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]] + $confirmed;

		$new = $this->changedPlan(fn () => $this->write($arguments));
		$this->assertSame(NotesMessages::notePlanChanged(), $new['warnings'][0]['message']);
		$this->assertNotSame($plan[PlanState::ARGUMENT], $new[PlanState::ARGUMENT]);
		$this->assertNothingWritten($before);
	}

	/** Nextcloud keeps the etag of a file written twice within the same second: the content still tells them apart. */
	public function testAChangeUnderTheSameEtagAnswersWithTheNewPlanAndKeepsTheOtherEdit(): void {
		$plan = $this->plan(['id' => $this->id, 'append' => '- 102 aberto']);
		$this->tree->nodes[self::PATH]['content'] = "# Chamados\n- 101 fechado\n";
		$arguments = ['id' => $this->id, 'append' => '- 102 aberto', 'etag' => $plan['etag'], PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]];

		$new = $this->changedPlan(fn () => $this->write($arguments));
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->content());
		$this->assertStringContainsString('- 101 fechado', $new['diff']);
		$this->assertSame([], array_filter($this->tree->ops, static fn (string $op): bool => str_starts_with($op, 'write')));

		$this->write([PlanState::ARGUMENT => $new[PlanState::ARGUMENT]] + $arguments);
		$this->assertSame("# Chamados\n- 101 fechado\n- 102 aberto\n", $this->content());
	}

	/** The state is read again right before putContent(): a change after the first check is not overwritten either. */
	public function testTheWriteChecksTheStateAgainRightBeforeItWrites(): void {
		$plan = $this->plan(['id' => $this->id, 'append' => '- 102 aberto']);
		$arguments = $this->validated(['id' => $this->id, 'append' => '- 102 aberto', 'etag' => $plan['etag'],
			PlanState::ARGUMENT => $plan[PlanState::ARGUMENT], 'confirm' => true]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn () => $this->tree->rootFolder());
		$notes = new NotesRepository($root, $this->config->mock($this));
		$access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
		$confirmation = new \OCA\Mcp\Tools\Notes\NoteChangeConfirmation($notes, $access, new PlanState($this->config->mock($this)));
		$folder = $notes->folder('alice', false);
		$note = $notes->find($folder, $this->id);

		$content = $confirmation->content($folder, 'alice', $note, $arguments, 'append');
		$this->assertSame("# Chamados\n- 101 aberto\n- 102 aberto\n", $content);
		// Another client writes between the first check and the write, and Nextcloud keeps the etag.
		$this->tree->nodes[self::PATH]['content'] = "# Chamados\n- 101 fechado\n";
		$write = $confirmation->writer($folder, 'alice', $note, $arguments, 'append', $content, [$note->getName()]);
		$this->changedPlan($write);
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->content());
	}

	/** notes_read gives the path the Files tools take and the etag. */
	public function testReadAnswersWithThePathAndTheEtag(): void {
		$result = $this->module->call('notes_read', ['id' => $this->id], 'alice');
		$read = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('/Notes/Chamados/Outubro.md', $read['path']);
		$this->assertSame($this->etag(), $read['etag']);
		$this->assertSame("# Chamados\n- 101 aberto\n", $read['content']);
	}
}
