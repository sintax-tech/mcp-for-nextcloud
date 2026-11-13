<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\MailCards;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The attachment step of a mail card: one attachment saved in the actor's Files under a free name. Implemented by the
 * Mail module through the work classes of mail_save_attachment; without a binding the container fails.
 *
 * Every call checks again, for the actor, everything mail_save_attachment is checked for: the grant mail.save, the
 * attachment capability, Guests, the Mail app, the arguments, the Files scope, the native ACL, the type policy,
 * `confirm_shared` for a folder of somebody else and the byte budget. There is no replacement: an occupied name is
 * refused and the existing file is left as it is. A plan reads metadata only; a step runs at most once and never
 * deletes what it wrote.
 */
interface MailAttachmentSave {
	/**
	 * The plan of mail_save_attachment for a new file, without downloading the attachment.
	 *
	 * @param string $userId the actor
	 * @param int $messageId the message, as mail_save_attachment takes it
	 * @param string $attachmentId the attachment, as mail_save_attachment takes it
	 * @param string $path the final path of the new file in the actor's Files
	 * @param bool $confirmShared whether the actor confirmed a folder owned by somebody else
	 * @return array<string, mixed> the plan of mail_save_attachment, with nothing written
	 * @throws ToolFailure when the step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on arguments mail_save_attachment refuses
	 */
	public function planSave(string $userId, int $messageId, string $attachmentId, string $path, bool $confirmShared): array;

	/**
	 * Saves the attachment as a new file as mail_save_attachment does, once, and reports the file it read back with
	 * the canonical link of that file on this instance (null when it cannot be built). The link adds no access.
	 *
	 * @param string $userId the actor
	 * @param int $messageId the message, as mail_save_attachment takes it
	 * @param string $attachmentId the attachment, as mail_save_attachment takes it
	 * @param string $path the final path of the new file in the actor's Files
	 * @param bool $confirmShared whether the actor confirmed a folder owned by somebody else
	 * @return MailCardStep applied (saved and read back), failed (refused before the write) or uncertain (the file may exist)
	 * @throws \InvalidArgumentException on arguments mail_save_attachment refuses
	 */
	public function save(string $userId, int $messageId, string $attachmentId, string $path, bool $confirmShared): MailCardStep;
}
