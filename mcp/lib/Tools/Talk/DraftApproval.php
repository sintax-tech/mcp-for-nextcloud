<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\Comments\IComment;

/**
 * Builds the draft a user has to approve before anything is published in a conversation.
 *
 * A writing tool called without confirm: true answers with this payload and writes nothing: it resolves and
 * validates exactly what the confirmed call would need, so an approved draft cannot fail for a predictable
 * reason, and then stops. The payload is what the agent shows the user, so it carries the conversation, the
 * exact text, the quoted message and the file being sent, and never a field the call would not publish.
 */
class DraftApproval {
    /** Longest excerpt of the quoted message a draft carries; the id is there to read the rest. */
    public const EXCERPT_MAX_LENGTH = 200;

    public function __construct(
        private ConversationWriter $writer,
        private FileSharer $sharer,
        private AttachmentAccess $attachmentAccess,
        private ActorNames $actorNames,
    ) {}

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body, normalized exactly as it will be sent
     * @param int|null $replyTo Id of the quoted message, when the reply quotes one
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string, replyTo?:array{id:int, author:string, excerpt:string}}, message:string}
     * @throws InvalidArgumentException When the message is blank or too long, or the quoted id is not a positive integer
     * @throws ConversationAccessException When the quoted message does not exist in this conversation
     */
    public function reply(Conversation $conversation, string $userId, string $message, ?int $replyTo): array {
        $draft = ['message' => ConversationWriter::normalizeMessage($message)];

        if ($replyTo !== null) {
            $comment = $this->writer->quoteTarget($conversation, $userId, $replyTo);
            $draft['replyTo'] = [
                'id' => (int)$comment->getId(),
                'author' => $this->actorNames->displayName((string)$comment->getActorId()),
                'excerpt' => $this->excerptOf($comment),
            ];
        }

        return $this->payload('talk_reply', $conversation, $userId, $draft);
    }

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
        return $this->payload('talk_attach_file', $conversation, $userId, [
            'message' => $caption === null ? null : ConversationWriter::normalizeMessage($caption),
            'file' => $file,
        ]);
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the card
     * @param int $attachmentId Room share id of the attachment, as talk_read_messages reports it
     * @param string|null $caption Optional text rendered with the card
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string|null}, message:string}
     * @throws InvalidArgumentException When the caption is blank or too long
     * @throws ConversationAccessException When the attachment is not a share of this conversation
     */
    public function quote(Conversation $conversation, string $userId, int $attachmentId, ?string $caption): array {
        $this->attachmentAccess->requireRoomShareOf($conversation, $userId, $attachmentId);

        return $this->payload('talk_quote_file', $conversation, $userId, [
            'message' => AttachmentMessage::normalizeCaption($caption),
        ]);
    }

    /**
     * @param string $action Tool the draft belongs to
     * @param Conversation $conversation Conversation the write would land in
     * @param string $userId Authenticated user, who sees the conversation name
     * @param array<string, mixed> $draft What the confirmed call would publish
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array<string, mixed>, message:string}
     */
    private function payload(string $action, Conversation $conversation, string $userId, array $draft): array {
        $displayName = $conversation->displayName($userId);

        return [
            'requiresConfirmation' => true,
            'action' => $action,
            'conversation' => [
                'token' => $conversation->token(),
                'displayName' => $displayName,
            ],
            'draft' => $draft,
            'message' => sprintf(Messages::CONFIRMATION_INSTRUCTION, $displayName),
        ];
    }

    /**
     * What the user is answering, in their own words: a plain message is shortened, and a card the reader
     * already renders cannot be quoted as text, so it is named by its type instead.
     *
     * @param IComment $comment Quoted message, as Talk stores it
     * @return string At most self::EXCERPT_MAX_LENGTH characters
     */
    private function excerptOf(IComment $comment): string {
        $text = (string)$comment->getMessage();

        $envelope = json_decode(ltrim($text), true);
        if (is_array($envelope) && isset($envelope['message']) && is_string($envelope['message'])) {
            $text = '[' . $envelope['message'] . ']';
        }

        return mb_substr(trim($text), 0, self::EXCERPT_MAX_LENGTH);
    }
}