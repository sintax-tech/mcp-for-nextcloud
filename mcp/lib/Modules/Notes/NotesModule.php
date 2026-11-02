<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Modules\Notes\Creation\CreateNoteHandler;
use OCA\Mcp\Modules\Notes\Creation\CreateNotePreview;
use OCA\Mcp\Modules\Notes\Deletion\DeleteNoteHandler;
use OCA\Mcp\Modules\Notes\Deletion\DeleteNotePreview;
use OCA\Mcp\Modules\Notes\Editing\EditNoteHandler;
use OCA\Mcp\Modules\Notes\Editing\EditNotePreview;
use OCA\Mcp\Modules\Notes\Editing\NoteChange;
use OCA\Mcp\Modules\Notes\Integration\NotesTrash;
use OCA\Mcp\Modules\Notes\Locating\NoteCategories;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Moving\MoveNoteHandler;
use OCA\Mcp\Modules\Notes\Moving\MoveNotePreview;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesPlanRenderer;
use OCA\Mcp\Modules\Notes\Reading\ListNotesHandler;
use OCA\Mcp\Modules\Notes\Reading\ReadNoteHandler;
use OCA\Mcp\Modules\Notes\Reading\SearchNotesHandler;
use OCA\Mcp\Modules\Notes\Writing\NoteWrite;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCP\App\IAppManager;
use OCP\IUserManager;

/**
 * Notes tools: the definitions and, for each call and plan, the one class that does the work (lookup in Locating,
 * reads in Reading, each write and its plan in the folder of its verb). This class only wires them.
 *
 * The four writing tools also answer {@see self::preview()}, which the registry asks for whenever a write arrives
 * without `confirm: true`. A note open in Text is refused before anything changes ({@see LockAwareWrite}).
 */
class NotesModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
	/** Maximum note size read or written, in bytes. */
	public const MAX_BYTES = NotesRepository::MAX_BYTES;

	/**
	 * @param NotesRepository $notes note lookup, reads and naming
	 * @param IAppManager $appManager whether the trash bin is on for the account
	 * @param IUserManager $userManager the account the trash-bin check is made for
	 * @param SharedWriteGuard $guard the confirmation a note outside the personal folder needs
	 * @param NodeAccessInfo $accessInfo who owns a note and who else it reaches
	 * @param VisibilityGuard|null $visibilityGuard administrator exclusions, null when none apply
	 * @param LockAwareWrite|null $locks the files_lock check, null when the app is not there
	 * @param PlanState|null $states the plan states of append and replace; the container always provides it
	 * @param FileTypeGuard|null $types file-name policy; null only when a unit double omits the container
	 */
	public function __construct(
		private NotesRepository $notes,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private SharedWriteGuard $guard,
		private NodeAccessInfo $accessInfo,
		private ?VisibilityGuard $visibilityGuard = null,
		private ?LockAwareWrite $locks = null,
		private ?PlanState $states = null,
		private ?FileTypeGuard $types = null,
	) {}

	/**
	 * What the schemas cannot say: how a note is named and what the module refuses to do.
	 *
	 * @return list<string>
	 */
	public function guideNotes(): array {
		return [
			'A note is addressed by the `id` notes_list or notes_search returns, not by its title; the title is the file name and '
				. 'the category is the subfolder it sits in.',
			'Use notes_search to find notes by keyword in title or Markdown content.',
			'notes_create never overwrites: when the title is taken in that category it picks a free name, and the '
				. 'response says which name it used.',
			'notes_delete only deletes where the Nextcloud trash bin takes the note back; anywhere else it refuses '
				. 'instead of removing it for good.',
			'Notes are capped at ' . self::MAX_BYTES . ' bytes read or written, and a title at 200 characters.',
			'Pass `etag` to make a change fail instead of overwriting somebody else\'s version of the same note.',
			'For a small change use notes_edit with append or old/new: only the change is sent. Use content only to rewrite the whole note.',
			'Some notes or categories may be hidden by administrator policy and will appear as if they do not exist.',
		];
	}

	/** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}> */
	public function definitions(): array {
		$id = ['type' => 'integer', 'minimum' => 1, 'description' => NotesMessages::PARAM_ID];
		$etag = ['type' => 'string', 'description' => NotesMessages::PARAM_ETAG];
		// Notes live inside the user's own folder by construction, so this only ever fires when an
		// administrator pointed the notes path at a share, a team folder or an external storage.
		$confirmShared = ['type' => 'boolean', 'description' => NotesMessages::PARAM_CONFIRM_SHARED];
		return [
			self::tool('notes_list', 'read', NotesMessages::TOOL_LIST_DESCRIPTION, []),
			self::tool('notes_search', 'read', NotesMessages::TOOL_SEARCH_DESCRIPTION, [
				'query' => ['type' => 'string', 'minLength' => 1, 'description' => NotesMessages::PARAM_SEARCH_QUERY],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => NotesMessages::PARAM_SEARCH_LIMIT],
				'category' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_SEARCH_CATEGORY],
			], ['query']),
			self::tool('notes_read', 'read', NotesMessages::TOOL_READ_DESCRIPTION, ['id' => $id], ['id']),
			self::tool('notes_create', 'create', NotesMessages::TOOL_CREATE_DESCRIPTION, [
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => NotesMessages::PARAM_TITLE],
				'content' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_CONTENT],
				'category' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_CATEGORY],
				'confirm_shared' => $confirmShared,
			], ['title']),
			self::tool('notes_edit', 'edit', NotesMessages::TOOL_EDIT_DESCRIPTION, [
				'id' => $id,
				...NoteChange::properties(),
				'content' => ['type' => 'string', 'description' => NotesMessages::PARAM_NEW_CONTENT],
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => NotesMessages::PARAM_NEW_TITLE],
				'etag' => $etag,
				PlanState::ARGUMENT => PlanState::property(),
				'confirm_shared' => $confirmShared,
			], ['id']),
			self::tool('notes_move', 'move', NotesMessages::TOOL_MOVE_DESCRIPTION, [
				'id' => $id,
				'category' => ['type' => 'string', 'description' => NotesMessages::PARAM_CATEGORY_TARGET],
				'etag' => $etag,
				'confirm_shared' => $confirmShared,
			], ['id', 'category']),
			self::tool('notes_delete', 'delete', NotesMessages::TOOL_DELETE_DESCRIPTION, [
				'id' => $id,
				'etag' => $etag,
				'confirm_shared' => $confirmShared,
			], ['id']),
		];
	}

	/**
	 * @param string $name one of the names from definitions()
	 * @param array<string, mixed> $arguments already validated against the tool schema, defaults applied
	 * @param string $userId authenticated user
	 * @return array{content: list<array{type:string, text:string}>, isError?: bool}
	 * @throws InvalidArgumentException for an unknown tool or an edit without changes
	 * @throws ToolFailure for client-safe failures (not found, forbidden, conflict, precondition)
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return NodeAccess::run(fn (): array => match ($name) {
			'notes_list' => (new ListNotesHandler($this->notes, $this->accessInfo))->handle($userId),
			'notes_search' => (new SearchNotesHandler($this->notes, $this->accessInfo, $this->visibilityGuard, $this->categories()))->handle($userId, $arguments),
			'notes_read' => ToolResult::json((new ReadNoteHandler($this->notes, $this->accessInfo))->handle($userId, $arguments)),
			'notes_create' => ToolResult::json($this->creates()->handle($userId, $arguments)),
			'notes_edit' => ToolResult::json($this->edits()->handle($userId, $arguments)),
			'notes_move' => ToolResult::json($this->moves()->handle($userId, $arguments)),
			'notes_delete' => ToolResult::json($this->deletes()->handle($userId, $arguments)),
			default => throw new InvalidArgumentException('Unknown tool'),
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * The plan says what the note looks like now and what the call does to it; each plan lives beside the write it
	 * describes and refuses what that write would refuse.
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return NodeAccess::run(fn (): array => match ($name) {
			'notes_create' => (new CreateNotePreview($this->notes, $this->creates(), $this->categories(), $this->snapshots()))->plan($userId, $arguments),
			'notes_edit' => (new EditNotePreview($this->notes, $this->edits(), $this->snapshots(), $this->accessInfo, $this->locks, $this->states, $this->types))->plan($userId, $arguments),
			'notes_move' => (new MoveNotePreview($this->notes, $this->moves(), $this->categories(), $this->snapshots(), $this->accessInfo, $this->visibilityGuard, $this->locks))->plan($userId, $arguments),
			'notes_delete' => (new DeleteNotePreview($this->notes, $this->trash(), $this->snapshots(), $this->accessInfo, $this->locks))->plan($userId, $arguments),
			default => throw new InvalidArgumentException('Unknown tool'),
		});
	}

	/**
	 * {@inheritDoc}
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new NotesPlanRenderer())->render($tool, $plan);
	}

	/**
	 * @return CreateNoteHandler the creation, with this module's guards
	 */
	private function creates(): CreateNoteHandler {
		return new CreateNoteHandler($this->notes, $this->guard, $this->visibilityGuard, $this->types);
	}

	/**
	 * @return EditNoteHandler the edit, with this module's guards
	 */
	private function edits(): EditNoteHandler {
		return new EditNoteHandler($this->notes, $this->guard, $this->accessInfo, $this->visibilityGuard, $this->locks, $this->states, $this->types);
	}

	/**
	 * @return MoveNoteHandler the move, with this module's guards
	 */
	private function moves(): MoveNoteHandler {
		return new MoveNoteHandler($this->notes, $this->guard, $this->accessInfo, $this->visibilityGuard, $this->locks, $this->types);
	}

	/**
	 * @return DeleteNoteHandler the deletion, with this module's guards
	 */
	private function deletes(): DeleteNoteHandler {
		return new DeleteNoteHandler($this->notes, $this->trash(), new NoteWrite($this->notes, $this->accessInfo, $this->visibilityGuard, $this->locks), $this->guard, $this->locks);
	}

	/**
	 * @return NoteCategories the category lookup, with this module's exclusions
	 */
	private function categories(): NoteCategories {
		return new NoteCategories($this->visibilityGuard);
	}

	/**
	 * @return NoteSnapshot the note as a plan shows it
	 */
	private function snapshots(): NoteSnapshot {
		return new NoteSnapshot($this->notes, $this->accessInfo);
	}

	/**
	 * @return NotesTrash the trash-bin checks for this module's accounts
	 */
	private function trash(): NotesTrash {
		return new NotesTrash($this->appManager, $this->userManager);
	}

	/**
	 * @param array<string, array<string, mixed>> $properties
	 * @param list<string> $required
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
	 */
	private static function tool(string $name, string $operation, string $description, array $properties, array $required = []): array {
		$schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
		return ['name' => $name, 'description' => $description, 'module' => 'notes', 'operation' => $operation, 'app' => 'notes',
			'inputSchema' => $required === [] ? $schema : $schema + ['required' => $required]];
	}
}
