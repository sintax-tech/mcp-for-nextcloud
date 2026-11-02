<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Deletion;

use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Backup\ContactBackup;
use OCA\Mcp\Modules\Contacts\Planning\ContactChange;
use OCA\Mcp\Modules\Contacts\Writing\ContactDav;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;

/** Deletes one contact only after a mandatory, verified copy and fresh native ACL/ETag checks. */
final class DeleteContactHandler {
	/**
	 * @param ContactAccess $access existing native collection authorization and validated contact reader
	 * @param ContactDav $dav existing conditional CardDAV writer
	 * @param ContactBackup $backup mandatory contact copy, independent of the Files preference
	 * @param ContactChange $changes shared unchanged write preparation
	 */
	public function __construct(private ContactAccess $access, private ContactDav $dav, private ContactBackup $backup,
		private ContactChange $changes) {
	}

	/**
	 * @param string $userId authenticated actor
	 * @param array<string, mixed> $arguments validated contact fields and explicit confirmations
	 * @return array<string, mixed> unchanged plan or verified deletion receipt
	 * @throws \Throwable for denied access, missing confirmation, failed backup or native deletion failure
	 */
	public function handle(string $userId, array $arguments): array {
		$prepared = $this->changes->prepare('contacts_delete_contact', $arguments, $userId);
		if (!WriteGate::confirmed($arguments)) {
			return ToolResult::json($prepared['plan']);
		}
		if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
			throw new ToolFailure(Translator::t('Confirm the change to the shared collection with confirm_shared=true.'));
		}
		$book = $prepared['book'];
		$row = $prepared['row'];
		$backupPath = $this->backup->save(
			$userId,
			$book->name,
			$prepared['plan']['before']['name'] ?: $prepared['plan']['before']['uid'],
			$row['data']
		);
		// Check ACL/ownership and ETag again after the backup; If-Match covers changes after this check.
		$currentBook = $this->access->resolve($userId, $arguments['addressbook'], true);
		if ($currentBook->id !== $book->id || $currentBook->ownerPrincipal !== $book->ownerPrincipal) {
			throw new ToolFailure(CommonMessages::conflict());
		}
		$latest = $this->access->row($book->id, $row['uri']);
		if ($latest['etag'] !== $row['etag']) {
			throw new ToolFailure(CommonMessages::conflict());
		}
		$this->dav->delete($userId, $book->uri, $row['uri'], $row['etag']);
		return ToolResult::json(
			[
				'uri' => $row['uri'],
				'addressbook' => $book->path,
				'deleted' => true,
				'permanent' => true,
				'recoverable' => false,
				'backupPath' => $backupPath,
				'message' => Translator::t(
					'The contact was permanently deleted. Import the saved vCard through Contacts to recover it.'
				),
			]
		);
	}
}
