<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages;
use OCA\Mcp\Modules\Mail\Tools\SaveAttachment;
use OCA\Mcp\Modules\Mail\Writing\SaveAttachmentHandler;
use OCA\Mcp\Modules\Mail\Writing\SaveAttachmentPreview;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\MailCards\MailAttachmentSave;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The attachment step of a mail card, through the very work classes of mail_save_attachment.
 *
 * The step is checked as the registry checks mail_save_attachment (grant mail.save, the attachment capability, Guests
 * and the Mail app by {@see ToolAccess}, then the published schema); the work classes check the grant, the Mail
 * version, the message, the byte budget, the Files scope, the ACL, the type policy and the shared folder again, and
 * write through the protected Files creation as they always do. The arguments never carry `replace` or `etag`, so an
 * occupied name is refused by the creation and the existing file is not touched.
 */
final class MailAttachmentSaveSteps implements MailAttachmentSave {
	/** Files' own route to one file by id, the private link the Files app shares; it opens only for who can read it. */
	private const FILE_ROUTE = 'files.View.showFile';

	/**
	 * @param ToolAccess $access the registry's own decision on grant, capability, Guests and app
	 * @param SaveAttachmentPreview $plans the plan of mail_save_attachment
	 * @param SaveAttachmentHandler $saves the effect of mail_save_attachment
	 * @param IURLGenerator $urls the canonical link of the saved file on this instance
	 * @param LoggerInterface $logger the class and code of a file link that could not be built, never its message
	 */
	public function __construct(
		private ToolAccess $access,
		private SaveAttachmentPreview $plans,
		private SaveAttachmentHandler $saves,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
	}

	/** {@inheritDoc} */
	public function planSave(string $userId, int $messageId, string $attachmentId, string $path, bool $confirmShared): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(SaveAttachment::definition()->name));
		}
		return $this->plans->plan(self::arguments($messageId, $attachmentId, $path, $confirmShared), $userId);
	}

	/** {@inheritDoc} */
	public function save(string $userId, int $messageId, string $attachmentId, string $path, bool $confirmShared): WorkflowStep {
		if (!$this->allowed($userId)) {
			return WorkflowStep::failed(WorkflowMessages::notAllowed(SaveAttachment::definition()->name));
		}
		$arguments = self::arguments($messageId, $attachmentId, $path, $confirmShared);
		// The step classifies as mail_save_attachment answers, never more hopefully or more gravely: what the tool
		// answers as an error (ToolFailure, a lock) is failed with its own sentence, what its receipt answers as
		// uncertain is uncertain. Any other exception reaches the caller unchanged, as it reaches the registry.
		try {
			$result = $this->saves->handle($arguments + ['confirm' => true], $userId);
		} catch (ToolFailure $refused) {
			return WorkflowStep::failed($refused->getMessage());
		} catch (LockedException $locked) {
			return WorkflowStep::failed(NodeAccess::lockFailure($locked)->getMessage());
		}
		$receipt = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		if (($receipt['requiresConfirmation'] ?? false) === true) {
			// A folder of somebody else without confirm_shared: answered before anything is read or written.
			return WorkflowStep::failed((string)$receipt['message']);
		}
		$fileId = is_int($receipt['fileId'] ?? null) ? $receipt['fileId'] : null;
		$ids = (is_string($receipt['path'] ?? null) ? ['path' => $receipt['path']] : []) + ($fileId === null ? [] : ['fileId' => $fileId]);
		if (($receipt['outcome'] ?? null) !== 'saved' || ($receipt['verified'] ?? false) !== true || $fileId === null) {
			return WorkflowStep::uncertain((string)($receipt['message'] ?? MailSaveMessages::uncertain()), $ids, $receipt);
		}
		return WorkflowStep::applied($ids, $receipt + ['url' => $this->fileUrl($fileId)]);
	}

	/**
	 * @param int $messageId the message
	 * @param string $attachmentId the attachment
	 * @param string $path the final path of the new file
	 * @param bool $confirmShared whether the actor confirmed a folder of somebody else
	 * @return array<string, mixed> the arguments as the published schema of mail_save_attachment accepts them, never with replace
	 * @throws InvalidArgumentException naming the first argument the schema refuses
	 */
	private static function arguments(int $messageId, string $attachmentId, string $path, bool $confirmShared): array {
		$arguments = ['message_id' => $messageId, 'attachment_id' => $attachmentId, 'path' => $path];
		if ($confirmShared) {
			$arguments['confirm_shared'] = true;
		}
		return ArgumentValidator::validate(SaveAttachment::definition()->inputSchema, $arguments);
	}

	/**
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call mail_save_attachment now
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(string $userId): bool {
		return $this->access->allowed(SaveAttachment::definition()->toArray(), $userId);
	}

	/**
	 * @param int $fileId the file just read back
	 * @return string|null its absolute private link on this instance; null when it cannot be built, never a failure
	 */
	private function fileUrl(int $fileId): ?string {
		try {
			return $this->urls->linkToRouteAbsolute(self::FILE_ROUTE, ['fileid' => $fileId]);
		} catch (Throwable $e) {
			$this->logger->warning('MCP mail card file link failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
			return null;
		}
	}
}
