<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Modules\Talk\References\ReferenceMessages;

final class MessagePlanRenderer
{
    /**
     * A message in a conversation, with the message it answers when the reply quotes one.
     *
     * @param array<string, mixed> $plan Plan of talk_reply
     * @return string|null The body, or null when the conversation or the message is missing
     */
    public static function reply(array $plan): ?string
    {
        $conversation = PlanFields::conversationName($plan);
        $message = PlanFields::stringAt($plan, 'draft', 'message');
        if ($conversation === null || $message === null) {
            return null;
        }

        $body = [PlanMessages::planToConversation($conversation), '', MessageExcerpt::excerpt($message)];

        $quoted = self::quoteBlock(PlanFields::valueAt($plan, 'draft', 'replyTo'));
        if ($quoted !== null) {
            $body[] = '';
            $body[] = $quoted;
        }

        $reference = self::referenceLine($plan);
        if ($reference !== null) {
            $body[] = '';
            $body[] = $reference;
        }

        return implode("\n", $body);
    }

    /**
     * A batch: how many messages go where, each one with its own excerpt and its own answer.
     *
     * @param array<string, mixed> $plan Plan of talk_send_batch
     * @return string|null The body, or null when the conversation or the list of messages is missing
     */
    public static function batch(array $plan): ?string
    {
        $conversation = PlanFields::conversationName($plan);
        $items = PlanFields::valueAt($plan, 'draft', 'messages');
        if ($conversation === null || !is_array($items) || $items === []) {
            return null;
        }

        $items = array_values($items);
        $body = [
            count($items) === 1
                ? PlanMessages::planOneMessage($conversation)
                : PlanMessages::planMessages(count($items), $conversation),
            '',
        ];

        foreach ($items as $index => $item) {
            // One unusable item makes the whole batch unreadable: showing the rest would be showing a plan the
            // confirmed call does not match.
            if (!is_array($item)) {
                return null;
            }
            $message = PlanFields::stringAt($item, 'message');
            if ($message === null) {
                return null;
            }
            if ($index > 0) {
                $body[] = '';
            }
            $body[] = $index + 1 . '. ' . MessageExcerpt::excerpt($message);

            $quoted = self::quoteBlock($item['replyTo'] ?? null);
            if ($quoted !== null) {
                $body[] = '';
                $body[] = $quoted;
            }
        }

        return implode("\n", $body);
    }

    /**
     * A direct message. The plan carries an account and no conversation, and that absence is the consequence the
     * user has to be told about before answering.
     *
     * @param array<string, mixed> $plan Plan of talk_message_user
     * @return string|null The body, or null when the person or the message is missing
     */
    public static function directMessage(array $plan): ?string
    {
        $person = PlanFields::stringAt($plan, 'target', 'displayName');
        $message = PlanFields::stringAt($plan, 'draft', 'message');
        if ($person === null || $message === null) {
            return null;
        }

        return implode("\n", [
            PlanMessages::planToPerson($person),
            '',
            MessageExcerpt::excerpt($message),
            '',
            PlanMessages::planDirectConversationCreated($person),
        ]);
    }

    /**
     * The block naming who is being answered and what was answered.
     *
     * @param mixed $quote replyTo block of a plan item, or null when the item quotes nothing
     * @return string|null The block, or null when the quote is absent or incomplete
     */
    public static function quoteBlock(mixed $quote): ?string
    {
        if (!is_array($quote)) {
            return null;
        }
        $author = PlanFields::stringAt($quote, 'author');
        $excerpt = PlanFields::stringAt($quote, 'excerpt');
        if ($author === null || $excerpt === null) {
            return null;
        }

        return PlanMessages::planReplyingTo($author) . "\n\n" . MessageExcerpt::excerpt($excerpt);
    }

    /**
     * The item a message links to, named by its kind and its title, so the user sees whose item the link exposes.
     *
     * @param array<string, mixed> $plan Plan of talk_reply
     * @return string|null The line, or null when the plan carries no reference or one the renderer cannot name
     */
    private static function referenceLine(array $plan): ?string
    {
        $reference = $plan['reference'] ?? null;
        if (!is_array($reference)) {
            return null;
        }
        $title = PlanFields::stringAt($reference, 'title');
        $label = match ($reference['type'] ?? null) {
            'deck_card' => ReferenceMessages::referenceLabelDeck(),
            'calendar_event' => ReferenceMessages::referenceLabelCalendar(),
            default => null,
        };
        if ($title === null || $label === null) {
            return null;
        }

        return PlanMessages::planLinkTo($label, $title);
    }
}
