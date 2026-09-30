<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\Files\File;
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
     * @return IShare The validated share, so a draft can name the file the card will carry
     * @throws ConversationAccessException When the id is not a share of this conversation
     */
    public function requireRoomShareOf(Conversation $conversation, string $userId, int $attachmentId): IShare {
        try {
            $share = $this->shareManager->getShareById((string)$attachmentId, $userId);
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::attachmentNotFound(), $e);
        }

        if ($share->getShareType() !== IShare::TYPE_ROOM
            || (string)$share->getSharedWith() !== $conversation->token()) {
            throw new ConversationAccessException(Messages::attachmentNotFound());
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