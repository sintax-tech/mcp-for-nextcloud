<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
 *
 * That order has a consequence this class owns: the share exists the moment the card is in the room, so a caption
 * that fails afterwards cannot be taken back with an error. Reporting the failure would hide a share that is
 * really published, and sharing again would only earn the duplicate refusal with the caption lost for good. So
 * the answer says what happened instead: the attachment id that was created, the caption that did not go out and
 * how to finish it. Nothing is deleted to paper over the failure, because removing a card other participants have
 * already seen is worse than a missing caption, and it would fail for its own reasons half the time.
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
     * @return array{conversation_token:string, attachmentId:int, file:array{path:string, name:string, size:int}, caption:string|null, captionSent:bool|null, messageId?:int, message?:string}
     * @throws InvalidArgumentException When the path or the caption is not usable
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     * @throws ConversationAccessException When the share cannot be created
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function attach(Conversation $conversation, string $userId, string $path, ?string $caption = null): array {
        // The share publishes the card the moment it exists, so a caption that cannot be sent is refused
        // while there is still nothing to take back.
        if ($caption !== null) {
            ConversationWriter::normalizeMessage($caption);
        }
        $file = $this->resolveShareable($conversation, $userId, $path);
        $token = $conversation->token();

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
            throw new FileAccessException(Messages::fileNotShared(), $e);
        }

        $result = [
            'conversation_token' => $token,
            'attachmentId' => (int)$created->getId(),
            'file' => self::describe($file),
            'caption' => $caption,
            'captionSent' => null,
        ];

        if ($caption === null) {
            return $result;
        }

        try {
            $result['messageId'] = $this->writer->sendText($conversation, $userId, $caption);
            $result['captionSent'] = true;
        } catch (Throwable $e) {
            // The attachment is published either way, so this is a partial success and says so, in the payload
            // and in the message the agent reads: the card is in the room, only the text is missing.
            $result['captionSent'] = false;
            $result['message'] = Messages::captionNotSent();
        }

        return $result;
    }

    /**
     * Resolves the file a draft would attach, applying every rule the share itself applies, so what the user
     * approves is exactly what can be shared.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the owner of the file and of the share
     * @param string $path Path of the file relative to the user folder
     * @return array{path:string, name:string, size:int} The file as it will be reported after the share
     * @throws InvalidArgumentException When the path is not usable
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     */
    public function previewFile(Conversation $conversation, string $userId, string $path): array {
        return self::describe($this->resolveShareable($conversation, $userId, $path));
    }

    /**
     * The file the share would carry, with the duplicate check that a confirmed call would hit anyway.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the owner of the file and of the share
     * @param string $path Path of the file relative to the user folder
     * @return object The file node, as an OCA\Files\File
     * @throws InvalidArgumentException When the path is not usable
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     */
    private function resolveShareable(Conversation $conversation, string $userId, string $path): object {
        $file = $this->fileResolver->resolveShareableFile($userId, $path);

        if ($this->isAlreadySharedIn($userId, $conversation->token(), $file)) {
            // Sharing again would make Talk post a second card and then refuse with a 403.
            throw new FileAccessException(Messages::fileAlreadyShared());
        }

        return $file;
    }

    /**
     * @param object $file The file node, as an OCA\Files\File
     * @return array{path:string, name:string, size:int}
     */
    private static function describe(object $file): array {
        return [
            'path' => $file->getPath(),
            'name' => $file->getName(),
            'size' => (int)$file->getSize(),
        ];
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
