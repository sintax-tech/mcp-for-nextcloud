<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Attachments;

use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Throwable;

/**
 * Decides whether an attachment may be quoted in a conversation, which is the one rule both the real write and
 * the draft preview need: quoting any other id would either post a card the user never saw or fail after they
 * already approved.
 */
class AttachmentAccess {

    public function __construct(
        private IShareManager $shareManager,
        private VisibilityGuard $visibilityGuard,
        private \OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter $references,
    ) {}

    /**
     * Checks that the id really is a room share of this conversation, and not a link share, a file of another
     * conversation or something the user cannot see. All of them are the same answer, so the module never reveals
     * that an id exists elsewhere.
     *
     * @param Conversation $conversation Conversation the card would be published in
     * @param string $userId Authenticated user
     * @param int $attachmentId Bare room share id as talk_attach_file and talk_read_messages report it; the share
     *                          manager only resolves "<provider>:<id>", so the provider prefix is added here
     * @return IShare The validated share, so a draft can name the file the card will carry
     * @throws ConversationAccessException When the id is not a share of this conversation
     */
    public function requireRoomShareOf(Conversation $conversation, string $userId, int $attachmentId): IShare {
        // Before the lookup: an unreadable Files scope is the same 503 for a share that exists and one that does not.
        $this->references->assertAvailable($userId);
        try {
            $share = $this->shareManager->getShareById(\OCA\Mcp\Platform\FileAccess\References\RoomShareId::full($attachmentId), $userId);
        } catch (Throwable $e) {
            throw new ConversationAccessException(AttachmentMessages::attachmentNotFound(), $e);
        }

        if ($share->getShareType() !== IShare::TYPE_ROOM
            || (string)$share->getSharedWith() !== $conversation->token()) {
            throw new ConversationAccessException(AttachmentMessages::attachmentNotFound());
        }

        try {
            $node = $share->getNode();
        } catch (Throwable $e) {
            // A share whose node cannot be resolved cannot be checked against the hidden tags, so it is
            // refused with the very same answer as an unknown attachment, in the draft and in the confirm.
            throw new ConversationAccessException(AttachmentMessages::attachmentNotFound(), $e);
        }
        if (!$node instanceof \OCP\Files\Node || !$this->visibilityGuard->isVisible($node)) {
            throw new ConversationAccessException(AttachmentMessages::attachmentNotFound());
        }
        // The owner's node is not enough: the file is checked again where the caller sees it, by the rule
        // talk_read_messages applies, so a draft never names an attachment the reading hides.
        if (!$this->references->allowsRoomShare($userId, $share)) {
            throw new ConversationAccessException(AttachmentMessages::attachmentNotFound());
        }

        return $share;
    }

    /**
     * Names the file behind a room share, so a user approving a quote knows which file will be cited instead of
     * trusting an id. A file deleted between the share and the quote keeps its share but has no node to read, so
     * only the id is reported then: the name of a file that no longer exists is worse than none.
     *
     * @param IShare $share Room share already validated by requireRoomShareOf
     * @return array{attachmentId:int, name:string|null, size:int|null, mimeType:string|null}
     */
    public function describe(IShare $share): array {
        $description = [
            'attachmentId' => (int)$share->getId(),
            'name' => null,
            'size' => null,
            'mimeType' => null,
        ];

        try {
            $node = $share->getNode();
            if ($node instanceof \OCP\Files\Node && !$this->visibilityGuard->isVisible($node)) {
                return $description;
            }
        } catch (Throwable) {
            return $description;
        }

        return [
            'attachmentId' => $description['attachmentId'],
            'name' => (string)$node->getName(),
            'size' => $node->getSize() === null ? null : (int)$node->getSize(),
            'mimeType' => $node instanceof File ? $node->getMimeType() : null,
        ];
    }
}