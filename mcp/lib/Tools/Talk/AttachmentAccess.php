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
    /** Share provider id the manager needs as prefix of every share id; from spreed's RoomShareProvider::identifier(). */
    private const ROOM_PROVIDER_ID = 'ocRoomShare';

    public function __construct(
        private IShareManager $shareManager,
        private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard = null,
    ) {}

    /**
     * The id the share manager resolves: "<provider>:<id>". An id that already carries a provider is kept as is,
     * so it is never prefixed twice.
     *
     * @param int|string $id Bare share id, or an already full one
     * @return string Full share id
     */
    public static function fullShareId(int|string $id): string {
        $id = (string)$id;

        return str_contains($id, ':') ? $id : self::ROOM_PROVIDER_ID . ':' . $id;
    }

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
        try {
            $share = $this->shareManager->getShareById(self::fullShareId($attachmentId), $userId);
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::attachmentNotFound(), $e);
        }

        if ($share->getShareType() !== IShare::TYPE_ROOM
            || (string)$share->getSharedWith() !== $conversation->token()) {
            throw new ConversationAccessException(Messages::attachmentNotFound());
        }

        if ($this->visibilityGuard !== null) {
            try {
                $node = $share->getNode();
                if ($node instanceof \OCP\Files\Node && !$this->visibilityGuard->isVisible($node)) {
                    throw new ConversationAccessException(Messages::attachmentNotFound());
                }
            } catch (ConversationAccessException $e) {
                throw $e;
            } catch (\Throwable) {}
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
            if ($this->visibilityGuard !== null && $node instanceof \OCP\Files\Node && !$this->visibilityGuard->isVisible($node)) {
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