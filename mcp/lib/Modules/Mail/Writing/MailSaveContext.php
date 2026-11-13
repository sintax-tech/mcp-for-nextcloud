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
use OCA\Mcp\Platform\FileAccess\{NewFileDestination, CommonMessages};
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
	 * @param NewFileDestination $files Protected Files port.
	 */
	public function __construct(private GrantPolicy $grants, private MailServices $services, private IAppManager $apps, private MailMessageLocator $locator, private MailSaveReader $reader, private MailSaveBudget $budget, private NewFileDestination $files) {
	}

	/**
	 * @param string $userId Actor UID.
	 * @return void
	 * @throws ToolFailure If save permission or Mail support is gone.
	 */
	public function authorize(string $userId): void {
		if (!$this->grants->granted($userId, 'mail', 'save') || !$this->services->isEnabledFor($userId) || !MailAttachmentSupport::available($this->apps)) {
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
		$destination = $this->files->inspect($userId, (string)$arguments['path']);
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
		return $this->files->create($userId, $prepared['destination']['path'], $content, ($arguments['confirm_shared'] ?? false) === true, $prepared['destination']['folderId']) + ['effectiveMaxBytes' => $limit, 'budget' => MailSaveMessages::budget($limit)];
	}
}
