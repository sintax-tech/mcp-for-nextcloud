<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCP\Constants;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Throwable;

/**
 * Shares a file the user already has into a conversation, and only that.
 *
 * The card is not published here: creating a room share already makes the Talk listener post the file_shared
 * message, so writing an envelope as well would show the attachment twice. A caption cannot ride inside that card
 * either, because the listener reads it from an HTTP request parameter a JSON-RPC call does not have, so it goes
 * out right after as a normal message.
 */
class FileSharer {
    public function __construct(
        private IShareManager $shareManager,
        private UserFileResolver $fileResolver,
        private ConversationWriter $writer,
    ) {}

    /**
     * Shares the file and, when there is a caption, sends it as the message right after the attachment card.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the owner of the file and of the share
     * @param string $path Path of the file relative to the user folder
     * @param string|null $caption Optional text sent after the attachment card
     * @return array{conversation_token:string, attachmentId:int, file:array{path:string, name:string, size:int}, messageId?:int}
     * @throws InvalidArgumentException When the path or the caption is not usable
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     * @throws ConversationAccessException When the share cannot be created or the caption cannot be sent
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function attach(Conversation $conversation, string $userId, string $path, ?string $caption = null): array {
        $file = $this->fileResolver->resolveShareableFile($userId, $path);
        $token = $conversation->room->getToken();

        if ($this->isAlreadySharedIn($userId, $token, $file)) {
            // Sharing again would make Talk post a second card and then refuse with a 403.
            throw new FileAccessException(Messages::FILE_ALREADY_SHARED);
        }

        try {
            $share = $this->shareManager->newShare();
            $share->setNode($file)
                ->setShareType(IShare::TYPE_ROOM)
                ->setSharedWith($token)
                ->setSharedBy($userId)
                ->setPermissions(Constants::PERMISSION_READ);
            $created = $this->shareManager->createShare($share);
        } catch (Throwable $e) {
            // Read-only, federated and permission failures are all refused by the Talk provider in here.
            throw new FileAccessException(Messages::FILE_NOT_SHARED, $e);
        }

        $result = [
            'conversation_token' => $token,
            'attachmentId' => (int)$created->getId(),
            'file' => [
                'path' => $file->getPath(),
                'name' => $file->getName(),
                'size' => (int)$file->getSize(),
            ],
        ];

        if ($caption !== null) {
            $result['messageId'] = $this->writer->sendText($conversation, $userId, $caption);
        }

        return $result;
    }

    /**
     * Whether this file is already shared in this conversation, by anybody.
     *
     * Talk refuses a duplicate by file and conversation, ignoring the author, so this check has to do the same.
     * The two share lookups are complementary by construction: one returns what the user initiated, the other
     * only what somebody else initiated in the user's own conversations.
     *
     * @param string $userId Authenticated user
     * @param string $token Token of the conversation
     * @param object $file The file node, as an OCA\Files\File
     * @return bool True when a share of this file already targets this conversation
     */
    private function isAlreadySharedIn(string $userId, string $token, object $file): bool {
        $shares = array_merge(
            $this->shareManager->getSharesBy($userId, IShare::TYPE_ROOM, $file, false, -1),
            $this->shareManager->getSharedWith($userId, IShare::TYPE_ROOM, $file, -1),
        );

        foreach ($shares as $share) {
            if ($share->getSharedWith() === $token) {
                return true;
            }
        }

        return false;
    }
}
