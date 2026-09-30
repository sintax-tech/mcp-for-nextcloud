<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Throwable;

/**
 * Decides whether an attachment may be quoted in a conversation, which is the one rule both the real write and
 * the draft preview need: quoting any other id would either post a card the user never saw or fail after they
 * already approved.
 */
class AttachmentAccess {
    public function __construct(private IShareManager $shareManager) {}

    /**
     * Checks that the id really is a room share of this conversation, and not a link share, a file of another
     * conversation or something the user cannot see. All of them are the same answer, so the module never reveals
     * that an id exists elsewhere.
     *
     * @param Conversation $conversation Conversation the card would be published in
     * @param string $userId Authenticated user
     * @param int $attachmentId Room share id reported by talk_read_messages
     * @throws ConversationAccessException When the id is not a share of this conversation
     */
    public function requireRoomShareOf(Conversation $conversation, string $userId, int $attachmentId): void {
        try {
            $share = $this->shareManager->getShareById((string)$attachmentId, $userId);
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::ATTACHMENT_NOT_FOUND, $e);
        }

        if ($share->getShareType() !== IShare::TYPE_ROOM
            || (string)$share->getSharedWith() !== $conversation->token()) {
            throw new ConversationAccessException(Messages::ATTACHMENT_NOT_FOUND);
        }
    }
}