<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Attachments;

use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Presentation\ConversationPlan;

class QuotePlan {
    public function __construct(private AttachmentAccess $attachmentAccess) {}
    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the card
     * @param int $attachmentId Room share id of the attachment, as talk_read_messages reports it
     * @param string|null $caption Optional text rendered with the card
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string|null, file:array{attachmentId:int, name:string|null, size:int|null, mimeType:string|null}}, message:string}
     * @throws InvalidArgumentException When the caption is blank or too long
     * @throws ConversationAccessException When the attachment is not a share of this conversation
     */
    public function quote(Conversation $conversation, string $userId, int $attachmentId, ?string $caption): array {
        // The share is read once and named in the preview: a user approves "relatorio.pdf", not the id 77.
        $file = $this->attachmentAccess->describe($this->attachmentAccess->requireRoomShareOf($conversation, $userId, $attachmentId));
        $text = AttachmentMessage::normalizeCaption($caption);

        return ConversationPlan::payload('talk_quote_file', $conversation, $userId, [
            'message' => $text,
            'file' => $file,
        ]);
    }
}
