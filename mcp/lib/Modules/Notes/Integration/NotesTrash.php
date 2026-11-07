<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IUserManager;

/**
 * The Nextcloud trash bin as the Notes module sees it: a note is only deleted when the trash bin takes it back.
 * This is the only place of the module that names the files_trashbin app.
 */
final class NotesTrash {
	/** Storage wrapper files_trashbin puts around storages whose deletions go to the trash bin. */
	public const STORAGE = 'OCA\\Files_Trashbin\\Storage';

	/**
	 * @param IAppManager $appManager whether the trash bin is on for the account
	 * @param IUserManager $userManager the account the trash-bin check is made for
	 */
	public function __construct(
		private IAppManager $appManager,
		private IUserManager $userManager,
	) {
	}

	/**
	 * A deletion only happens when the trash bin would take the note back.
	 *
	 * @param string $userId authenticated user
	 * @return void
	 * @throws ToolFailure when files_trashbin is off for this account
	 */
	public function assertEnabled(string $userId): void {
		$user = $this->userManager->get($userId);
		if ($user === null || !$this->appManager->isEnabledForUser('files_trashbin', $user)) {
			throw new ToolFailure(NotesMessages::notRecoverable());
		}
	}

	/**
	 * An enabled app does not cover every mount: external or excluded storages delete permanently.
	 *
	 * @param File $note the note about to be deleted
	 * @return void
	 * @throws ToolFailure when the storage of the note has no trash bin
	 */
	public function assertTakesBack(File $note): void {
		if (!$note->getStorage()->instanceOfStorage(self::STORAGE)) {
			throw new ToolFailure(NotesMessages::notRecoverable());
		}
	}
}
