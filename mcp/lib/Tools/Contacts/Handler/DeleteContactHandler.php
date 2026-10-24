<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts\Handler;

use Closure;
use OCA\Mcp\Tools\Contacts\ContactAccess;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\Contacts\ContactDav;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCA\Mcp\Tools\WriteGate;

/** Deletes one contact only after a mandatory, verified copy and fresh native ACL/ETag checks. */
final class DeleteContactHandler {
	/**
	 * @param ContactAccess $access existing native collection authorization
	 * @param ContactDav $dav existing conditional CardDAV writer
	 * @param ContactBackup $backup mandatory contact copy, independent of the Files preference
	 * @param Closure(array<string, mixed>, string): array<string, mixed> $prepare shared unchanged write preparation
	 * @param Closure(int, string): array<string, mixed> $readRow existing validated contact URI reader
	 */
	public function __construct(private ContactAccess $access, private ContactDav $dav, private ContactBackup $backup,
		private Closure $prepare, private Closure $readRow) {
	}

	/**
	 * @param array<string, mixed> $arguments validated contact address and concurrency fields
	 * @param string $userId authenticated actor
	 * @return array<string, mixed> deletion plan, including the current required copy directory
	 * @throws \Throwable if the existing preparation refuses access or input
	 */
	public function preview(array $arguments, string $userId): array {
		return ($this->prepare)($arguments, $userId)['plan'];
	}

	/**
	 * @param array<string, mixed> $arguments validated contact fields and explicit confirmations
	 * @param string $userId authenticated actor
	 * @return array<string, mixed> unchanged plan or verified deletion receipt
	 * @throws \Throwable for denied access, missing confirmation, failed backup or native deletion failure
	 */
	public function handle(array $arguments, string $userId): array {
		$prepared = ($this->prepare)($arguments, $userId);
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
		$latest = ($this->readRow)($book->id, $row['uri']);
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
