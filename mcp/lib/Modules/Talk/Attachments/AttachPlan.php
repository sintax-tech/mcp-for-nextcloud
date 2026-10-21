<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Attachments;

use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Messaging\MessageText;
use OCA\Mcp\Modules\Talk\Presentation\ConversationPlan;

class AttachPlan {
    public function __construct(private FileSharer $sharer) {}
    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, owner of the file and of the share
     * @param string $path Path of the file relative to the user folder
     * @param string|null $caption Optional text sent after the attachment card
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string|null, file:array{path:string, name:string, size:int}}, message:string}
     * @throws InvalidArgumentException When the path or the caption is not usable
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     */
    public function attach(Conversation $conversation, string $userId, string $path, ?string $caption): array {
        $file = $this->sharer->previewFile($conversation, $userId, $path);
        // The caption is sent as a message of its own, so it follows the rule a message follows.
        $text = $caption === null ? null : MessageText::normalizeMessage($caption);

        return ConversationPlan::payload('talk_attach_file', $conversation, $userId, [
            'message' => $text,
            'file' => $file,
        ]);
    }
}
