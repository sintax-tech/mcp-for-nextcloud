<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCP\Comments\IComment;

final class QuotedMessage
{
    public const EXCERPT_MAX_LENGTH = 200;

    public function __construct(private ReplyTargetReader $parents, private ActorNames $actorNames) {}

    /** @return array{id:int, author:string, excerpt:string} */
    public function describe(Conversation $conversation, string $userId, int $replyTo): array
    {
        $comment = $this->parents->quoteTarget($conversation, $userId, $replyTo);
        return [
            'id' => (int) $comment->getId(),
            'author' => $this->actorNames->displayName((string) $comment->getActorId()),
            'excerpt' => self::excerptOf($comment),
        ];
    }
    /**
     * What the user is answering, in their own words: a plain message is shortened, and a card the reader
     * already renders cannot be quoted as text, so it is named by its type instead.
     *
     * @param IComment $comment Quoted message, as Talk stores it
     * @return string At most self::EXCERPT_MAX_LENGTH characters
     */
    public static function excerptOf(IComment $comment): string
    {
        $text = (string) $comment->getMessage();

        $envelope = json_decode(ltrim($text), true);
        if (is_array($envelope) && isset($envelope['message']) && is_string($envelope['message'])) {
            $text = '[' . $envelope['message'] . ']';
        }

        return mb_substr(trim($text), 0, self::EXCERPT_MAX_LENGTH);
    }
}
