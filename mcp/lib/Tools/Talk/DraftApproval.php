<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCP\Comments\IComment;

/**
 * Builds the draft a user has to approve before anything is published in a conversation, and then decides whether
 * the confirmed call is really that draft being approved.
 *
 * A writing tool called without confirm answers with this payload and writes nothing: it resolves and validates
 * exactly what the confirmed call would need, records what it showed, and stops. The payload is what the agent
 * shows the user, so it carries the conversation, the exact text, the quoted message and the file being sent, and
 * never a field the call would not publish.
 *
 * The confirmation is not a boolean the caller can assert out of thin air: each draft stores a fingerprint of its
 * own content and hands back an id, and approveReply, approveAttach, approveQuote and approveDirectMessage
 * recompute that fingerprint from the arguments of the confirmed call. Publishing therefore requires a draft of this same account, this same
 * conversation, this same action and this same payload, still inside its TTL and not spent yet. What the server
 * can prove is that it showed the draft; that a human read and accepted it is the client's promise to keep.
 */
class DraftApproval {
    /** Longest excerpt of the quoted message a draft carries; the id is there to read the rest. */
    public const EXCERPT_MAX_LENGTH = 200;

    /** Largest batch a single call may carry; past this the answer stops being something a user can read. */
    public const MAX_BATCH = 50;

    public function __construct(
        private ConversationWriter $writer,
        private FileSharer $sharer,
        private AttachmentAccess $attachmentAccess,
        private ActorNames $actorNames,
        private ApprovalRegistry $approvals,
    ) {}

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body, normalized exactly as it will be sent
     * @param int|null $replyTo Id of the quoted message, when the reply quotes one
     * @return array{requiresConfirmation:true, approvalId:string, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string, replyTo?:array{id:int, author:string, excerpt:string}}, message:string}
     * @throws InvalidArgumentException When the message is blank or too long, or the quoted id is not a positive integer
     * @throws ConversationAccessException When the quoted message does not exist in this conversation
     */
    public function reply(Conversation $conversation, string $userId, string $message, ?int $replyTo): array {
        $text = ConversationWriter::normalizeMessage($message);

        $draft = ['message' => $text];

        if ($replyTo !== null) {
            $comment = $this->writer->quoteTarget($conversation, $userId, $replyTo);
            $draft['replyTo'] = [
                'id' => (int)$comment->getId(),
                'author' => $this->actorNames->displayName((string)$comment->getActorId()),
                'excerpt' => $this->excerptOf($comment),
            ];
        }

        return $this->payload('talk_reply', $conversation, $userId, $draft, [
            'conversation' => $conversation->token(),
            'message' => $text,
            'replyTo' => $replyTo,
        ]);
    }

    /**
     * Draft of a whole batch. Every item is shown with the very text that will be stored and, when the item quotes
     * something, with who is being answered: a user approving a list has to see the list, not a promise of one.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the messages
     * @param list<array{message:string, replyTo?:int|null}> $items Messages as received from the client
     * @return array{requiresConfirmation:true, approvalId:string, action:string, conversation:array{token:string, displayName:string}, draft:array{messages:list<array{message:string, replyTo?:array{id:int, author:string, excerpt:string}}>}, message:string}
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is not usable
     * @throws ConversationAccessException When a quoted message does not exist in this conversation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function batch(Conversation $conversation, string $userId, array $items): array {
        if ($items === []) {
            throw new InvalidArgumentException(Messages::EMPTY_BATCH);
        }
        if (count($items) > self::MAX_BATCH) {
            throw new InvalidArgumentException(sprintf(Messages::TOO_MANY_MESSAGES, self::MAX_BATCH));
        }

        // Everything that only needs the payload is checked first: a list with a blank item is a mistake in the
        // call, and no conversation lookup should have been started for it.
        $approved = self::normalizedBatch($items);

        $draft = [];
        foreach ($approved as $item) {
            $shown = ['message' => $item['message']];
            // A quoted id that does not exist is a mistake in the batch, not one failed item: sending the rest
            // would show the user something different from what they approved.
            if ($item['replyTo'] !== null) {
                $comment = $this->writer->quoteTarget($conversation, $userId, $item['replyTo']);
                $shown['replyTo'] = [
                    'id' => (int)$comment->getId(),
                    'author' => $this->actorNames->displayName((string)$comment->getActorId()),
                    'excerpt' => $this->excerptOf($comment),
                ];
            }
            $draft[] = $shown;
        }

        return $this->payload('talk_send_batch', $conversation, $userId, ['messages' => $draft], [
            'conversation' => $conversation->token(),
            'messages' => $approved,
        ]);
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, owner of the file and of the share
     * @param string $path Path of the file relative to the user folder
     * @param string|null $caption Optional text sent after the attachment card
     * @return array{requiresConfirmation:true, approvalId:string, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string|null, file:array{path:string, name:string, size:int}}, message:string}
     * @throws InvalidArgumentException When the path or the caption is not usable
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     */
    public function attach(Conversation $conversation, string $userId, string $path, ?string $caption): array {
        $file = $this->sharer->previewFile($conversation, $userId, $path);
        // The caption is sent as a message of its own, so it follows the rule a message follows.
        $text = $caption === null ? null : ConversationWriter::normalizeMessage($caption);

        return $this->payload('talk_attach_file', $conversation, $userId, [
            'message' => $text,
            'file' => $file,
        ], [
            'conversation' => $conversation->token(),
            'message' => $text,
            'path' => $path,
        ]);
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the card
     * @param int $attachmentId Room share id of the attachment, as talk_read_messages reports it
     * @param string|null $caption Optional text rendered with the card
     * @return array{requiresConfirmation:true, approvalId:string, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string|null, file:array{attachmentId:int, name:string|null, size:int|null, mimeType:string|null}}, message:string}
     * @throws InvalidArgumentException When the caption is blank or too long
     * @throws ConversationAccessException When the attachment is not a share of this conversation
     */
    public function quote(Conversation $conversation, string $userId, int $attachmentId, ?string $caption): array {
        // The share is read once and named in the draft: a user approves "relatorio.pdf", not the id 77.
        $file = $this->attachmentAccess->describe($this->attachmentAccess->requireRoomShareOf($conversation, $userId, $attachmentId));
        $text = AttachmentMessage::normalizeCaption($caption);

        return $this->payload('talk_quote_file', $conversation, $userId, [
            'message' => $text,
            'file' => $file,
        ], [
            'conversation' => $conversation->token(),
            'message' => $text,
            'attachmentId' => $attachmentId,
        ]);
    }

    /**
     * Draft of a direct message. It carries the target account instead of a conversation token, because the room
     * may not exist yet and showing a draft must not create it: the confirmed call is what opens the conversation.
     *
     * @param DirectContact $target Account the message is addressed to, already checked for reachability
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body, normalized exactly as it will be sent
     * @return array{requiresConfirmation:true, approvalId:string, action:string, target:array{id:string, displayName:string}, draft:array{message:string}, message:string}
     * @throws InvalidArgumentException When the message is blank or too long
     */
    public function directMessage(DirectContact $target, string $userId, string $message): array {
        $text = ConversationWriter::normalizeMessage($message);

        return [
            'requiresConfirmation' => true,
            'approvalId' => $this->approvals->issue($userId, [
                'action' => 'talk_message_user',
                'target' => $target->id,
                'message' => $text,
            ]),
            'action' => 'talk_message_user',
            'target' => $target->describe(),
            'draft' => ['message' => $text],
            'message' => sprintf(Messages::CONFIRMATION_INSTRUCTION_TARGET, $target->displayName),
        ];
    }

    /**
     * @param string $userId Authenticated user, the author of the message
     * @param string|null $approvalId Id returned by the draft call
     * @param string $targetId Account the approved call writes to
     * @param string $message Message body of the approved call
     * @throws ApprovalException When no pending draft of this call can be approved
     */
    public function approveDirectMessage(string $userId, ?string $approvalId, string $targetId, string $message): void {
        $this->approvals->consume($userId, $approvalId, [
            'action' => 'talk_message_user',
            'target' => $targetId,
            'message' => ConversationWriter::normalizeMessage($message),
        ]);
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param string|null $approvalId Id returned by the draft call
     * @param string $message Message body of the approved call
     * @param int|null $replyTo Id of the quoted message
     * @throws ApprovalException When no pending draft of this call can be approved
     */
    public function approveReply(Conversation $conversation, string $userId, ?string $approvalId, string $message, ?int $replyTo): void {
        $this->approvals->consume($userId, $approvalId, [
            'action' => 'talk_reply',
            'conversation' => $conversation->token(),
            'message' => ConversationWriter::normalizeMessage($message),
            'replyTo' => $replyTo,
        ]);
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the messages
     * @param string|null $approvalId Id returned by the draft call
     * @param list<array{message:string, replyTo?:int|null}> $items Messages of the approved call
     * @throws ApprovalException When no pending draft of this call can be approved
     */
    public function approveBatch(Conversation $conversation, string $userId, ?string $approvalId, array $items): void {
        $this->approvals->consume($userId, $approvalId, [
            'action' => 'talk_send_batch',
            'conversation' => $conversation->token(),
            'messages' => self::normalizedBatch($items),
        ]);
    }

    /**
     * The same normalization the draft did, so the approved call is compared to what the user saw.
     *
     * @param list<array{message:string, replyTo?:int|null}> $items Messages of a confirmed call
     * @return list<array{message:string, replyTo:int|null}>
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is not usable
     */
    private static function normalizedBatch(array $items): array {
        if ($items === []) {
            throw new InvalidArgumentException(Messages::EMPTY_BATCH);
        }
        if (count($items) > self::MAX_BATCH) {
            throw new InvalidArgumentException(sprintf(Messages::TOO_MANY_MESSAGES, self::MAX_BATCH));
        }

        $normalized = [];
        foreach (array_values($items) as $item) {
            $normalized[] = [
                'message' => ConversationWriter::normalizeMessage($item['message'] ?? ''),
                'replyTo' => $item['replyTo'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, owner of the file
     * @param string|null $approvalId Id returned by the draft call
     * @param string $path Path of the file relative to the user folder
     * @param string|null $caption Optional caption sent after the card
     * @throws ApprovalException When no pending draft of this call can be approved
     */
    public function approveAttach(Conversation $conversation, string $userId, ?string $approvalId, string $path, ?string $caption): void {
        $this->approvals->consume($userId, $approvalId, [
            'action' => 'talk_attach_file',
            'conversation' => $conversation->token(),
            'message' => $caption === null ? null : ConversationWriter::normalizeMessage($caption),
            'path' => $path,
        ]);
    }

    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the card
     * @param string|null $approvalId Id returned by the draft call
     * @param int $attachmentId Room share id the approved call cites
     * @param string|null $caption Optional caption rendered with the card
     * @throws ApprovalException When no pending draft of this call can be approved
     */
    public function approveQuote(Conversation $conversation, string $userId, ?string $approvalId, int $attachmentId, ?string $caption): void {
        $this->approvals->consume($userId, $approvalId, [
            'action' => 'talk_quote_file',
            'conversation' => $conversation->token(),
            'message' => AttachmentMessage::normalizeCaption($caption),
            'attachmentId' => $attachmentId,
        ]);
    }

    /**
     * Wraps a draft in the answer the agent shows, with the id that will prove this exact draft was approved.
     *
     * @param string $action Tool the draft belongs to
     * @param Conversation $conversation Conversation the write would land in
     * @param string $userId Authenticated user, who sees the conversation name
     * @param array<string, mixed> $draft What the confirmed call would publish
     * @param array<string, mixed> $approval What the approval is bound to: conversation and normalized payload
     * @return array{requiresConfirmation:true, approvalId:string, action:string, conversation:array{token:string, displayName:string}, draft:array<string, mixed>, message:string}
     */
    private function payload(string $action, Conversation $conversation, string $userId, array $draft, array $approval): array {
        $displayName = $conversation->displayName($userId);

        return [
            'requiresConfirmation' => true,
            'approvalId' => $this->approvals->issue($userId, ['action' => $action] + $approval),
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