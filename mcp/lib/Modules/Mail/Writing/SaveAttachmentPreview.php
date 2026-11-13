<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Writing;

use OCA\Mcp\Modules\Mail\Presentation\{MailSaveMessages, MailRows, MailMessages};

/** Metadata-only plan; never downloads the attachment or creates a node. */
final class SaveAttachmentPreview {
	/** @param MailSaveContext $context Fresh source/destination authorization. */
	public function __construct(private MailSaveContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments Validated arguments.
	 * @param string $userId Actor UID.
	 * @return array<string,mixed> Conversational plan.
	 */
	public function plan(array $arguments, string $userId): array {
		$prepared = $this->context->prepare($arguments, $userId, false);
		$part = $prepared['read']['part'];
		return [
			'action' => 'mail_save_attachment',
			'messageId' => (int)$arguments['message_id'],
			'attachmentId' => $part->id,
			'name' => $part->name === null ? null : MailRows::text($part->name),
			'declaredMimeType' => MailRows::text($part->type),
			'declaredUpperBytes' => $prepared['read']['declaredUpperBytes'],
			'effectiveMaxBytes' => $prepared['limit'],
			'budget' => MailSaveMessages::budget($prepared['limit']),
			'consequence' => MailSaveMessages::consequence(),
			'untrustedMailData' => true,
			'notice' => MailMessages::UNTRUSTED_NOTICE,
		] + $prepared['destination'];
	}
}
