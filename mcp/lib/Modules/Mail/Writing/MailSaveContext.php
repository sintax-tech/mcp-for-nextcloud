<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Writing;

use OCA\Mcp\Modules\Mail\Integration\{MailSaveReader, MailServices, MailAttachmentSupport};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Reading\MailAttachmentPreflight;
use OCA\Mcp\Modules\Mail\Presentation\{MailAttachmentMessages, MailSaveMessages};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\FileAccess\{NewFileDestination, FileReplacement, FileNameTaken, CommonMessages};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;

/** Revalidates actor access and source identity on every plan and confirmed call. */
final class MailSaveContext {
	/**
	 * @param GrantPolicy $grants Current permissions.
	 * @param MailServices $services Native enablement.
	 * @param IAppManager $apps Installed version.
	 * @param MailMessageLocator $locator Own-message lookup.
	 * @param MailSaveReader $reader Safe native reader.
	 * @param MailSaveBudget $budget Memory budget.
	 * @param NewFileDestination $files Protected Files creation port.
	 * @param FileReplacement $replacements Protected Files replacement port, with the pre-write copy.
	 */
	public function __construct(
		private GrantPolicy $grants,
		private MailServices $services,
		private IAppManager $apps,
		private MailMessageLocator $locator,
		private MailSaveReader $reader,
		private MailSaveBudget $budget,
		private NewFileDestination $files,
		private FileReplacement $replacements,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments Validated arguments.
	 * @return bool Whether the caller explicitly asked to replace an existing file.
	 */
	public static function replacing(array $arguments): bool {
		return ($arguments['replace'] ?? false) === true;
	}

	/**
	 * @param string $userId Actor UID.
	 * @return void
	 * @throws ToolFailure If save permission or Mail support is gone.
	 */
	public function authorize(string $userId): void {
		if (!$this->grants->granted($userId, 'mail', 'save')
			|| !$this->services->isEnabledFor($userId)
			|| !MailAttachmentSupport::available($this->apps)) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
	}

	/**
	 * @param array<string,mixed> $arguments Validated arguments.
	 * @param string $userId Actor UID.
	 * @param bool $content Whether to fetch the authorized part.
	 * @return array<string,mixed> Guarded destination and part metadata, optionally with content.
	 */
	public function prepare(array $arguments, string $userId, bool $content): array {
		$this->authorize($userId);
		$id = (string)$arguments['attachment_id'];
		if (!MailAttachmentPreflight::validId($id)) {
			throw new ToolFailure(MailAttachmentMessages::attachmentNotFound());
		}
		$destination = $this->destination($arguments, $userId);
		if ($content && $destination['requiresSharedConfirmation'] && ($arguments['confirm_shared'] ?? false) !== true) {
			return ['confirmation' => $destination['shared'][0]];
		}
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		$limit = $this->budget->effective();
		$read = $this->reader->read($userId, $found['account'], $found['mailbox'], $found['message'], $id, $limit, $content);
		return ['destination' => $destination, 'limit' => $limit, 'read' => $read];
	}

	/**
	 * @param array<string,mixed> $prepared Fresh source and destination.
	 * @param array<string,mixed> $arguments Confirmed arguments.
	 * @param string $userId Actor UID.
	 * @return array<string,mixed> Read-back receipt.
	 */
	public function save(array $prepared, array $arguments, string $userId): array {
		$this->authorize($userId);
		$content = $prepared['read']['content'];
		$limit = $prepared['limit'];
		if (strlen($content) > $limit) {
			throw new ToolFailure(MailSaveMessages::limit($limit));
		}
		$shared = ($arguments['confirm_shared'] ?? false) === true;
		$destination = $prepared['destination'];
		if (self::replacing($arguments)) {
			// The ETag read before the download: a change while the attachment was fetched refuses the write.
			$receipt = $this->replacements->replace(
				$userId,
				$destination['path'],
				$content,
				$shared,
				$destination['replaces']['fileId'],
				$destination['replaces']['etag'],
			);
		} else {
			try {
				$receipt = $this->files->create($userId, $destination['path'], $content, $shared, $destination['folderId']);
			} catch (FileNameTaken) {
				throw new ToolFailure(MailSaveMessages::occupied());
			}
		}
		return $receipt + [
			'effectiveMaxBytes' => $limit,
			'budget' => MailSaveMessages::budget($limit),
		];
	}

	/**
	 * @param array<string,mixed> $arguments Validated arguments.
	 * @param string $userId Actor UID.
	 * @return array<string,mixed> The free destination, or with replace the existing file and its pre-write copy.
	 * @throws ToolFailure When the name is taken without replace, or the destination is refused.
	 */
	private function destination(array $arguments, string $userId): array {
		$path = (string)$arguments['path'];
		if (self::replacing($arguments)) {
			return $this->replacements->inspect($userId, $path, isset($arguments['etag']) ? (string)$arguments['etag'] : null);
		}
		try {
			return $this->files->inspect($userId, $path);
		} catch (FileNameTaken) {
			throw new ToolFailure(MailSaveMessages::occupied());
		}
	}
}
