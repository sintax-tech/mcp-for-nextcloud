<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Notes;

use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Notes\Creation\CreateNoteHandler;
use OCA\Mcp\Modules\Notes\Creation\CreateNotePreview;
use OCA\Mcp\Modules\Notes\Deletion\DeleteNoteHandler;
use OCA\Mcp\Modules\Notes\Deletion\DeleteNotePreview;
use OCA\Mcp\Modules\Notes\Editing\EditNoteHandler;
use OCA\Mcp\Modules\Notes\Editing\EditNotePreview;
use OCA\Mcp\Modules\Notes\Integration\NotesTrash;
use OCA\Mcp\Modules\Notes\Locating\NoteCategories;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Moving\MoveNoteHandler;
use OCA\Mcp\Modules\Notes\Moving\MoveNotePreview;
use OCA\Mcp\Modules\Notes\NoteSnapshot;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Reading\ListNotesHandler;
use OCA\Mcp\Modules\Notes\Reading\ReadNoteHandler;
use OCA\Mcp\Modules\Notes\Reading\SearchNotesHandler;
use OCA\Mcp\Modules\Notes\Tools\CreateNote;
use OCA\Mcp\Modules\Notes\Tools\DeleteNote;
use OCA\Mcp\Modules\Notes\Tools\EditNote;
use OCA\Mcp\Modules\Notes\Tools\ListNotes;
use OCA\Mcp\Modules\Notes\Tools\MoveNote;
use OCA\Mcp\Modules\Notes\Tools\ReadNote;
use OCA\Mcp\Modules\Notes\Tools\SearchNotes;
use OCA\Mcp\Modules\Notes\Writing\NoteWrite;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Builds the Notes catalog module from the services its unit tests used to hand the legacy constructor, wiring each
 * tool the way the app container autowires it.
 */
final class NotesTestModule {
	public static function build(
		NotesRepository $notes,
		IAppManager $appManager,
		IUserManager $userManager,
		SharedWriteGuard $guard,
		NodeAccessInfo $accessInfo,
		?VisibilityGuard $visibilityGuard = null,
		?LockAwareWrite $locks = null,
		?PlanState $states = null,
		?FileTypeGuard $types = null,
	): NotesModule {
		$categories = new NoteCategories($visibilityGuard);
		$snapshots = new NoteSnapshot($notes, $accessInfo);
		$trash = new NotesTrash($appManager, $userManager);
		$creates = new CreateNoteHandler($notes, $guard, $visibilityGuard, $types);
		$edits = new EditNoteHandler($notes, $guard, $accessInfo, $visibilityGuard, $locks, $states, $types);
		$moves = new MoveNoteHandler($notes, $guard, $accessInfo, $visibilityGuard, $locks, $types);
		$writes = new NoteWrite($notes, $accessInfo, $visibilityGuard, $locks);
		return new NotesModule(self::container([
			ListNotes::class => new ListNotes(new ListNotesHandler($notes, $accessInfo)),
			SearchNotes::class => new SearchNotes(new SearchNotesHandler($notes, $accessInfo, $visibilityGuard, $categories)),
			ReadNote::class => new ReadNote(new ReadNoteHandler($notes, $accessInfo)),
			CreateNote::class => new CreateNote($creates, new CreateNotePreview($notes, $creates, $categories, $snapshots)),
			EditNote::class => new EditNote($edits, new EditNotePreview($notes, $edits, $snapshots, $accessInfo, $locks, $states, $types)),
			MoveNote::class => new MoveNote($moves, new MoveNotePreview($notes, $moves, $categories, $snapshots, $accessInfo, $visibilityGuard, $locks)),
			DeleteNote::class => new DeleteNote(
				new DeleteNoteHandler($notes, $trash, $writes, $guard, $locks),
				new DeleteNotePreview($notes, $trash, $snapshots, $accessInfo, $locks),
			),
		]));
	}

	/**
	 * @param array<string, object> $services instances by id
	 */
	public static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}
}
