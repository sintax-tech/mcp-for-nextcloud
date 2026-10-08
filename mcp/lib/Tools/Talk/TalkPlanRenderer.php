<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCA\Mcp\Tools\PlanText;
use Throwable;

/**
 * Turns the plan of a Talk write into the body a person reads before saying yes.
 *
 * The plan arrives as an array built by {@see WritePreview}, and the envelope that owns the plan text is not the
 * only thing that can read it: this class answers the plan with the conversation or the person by the name the user
 * knows them by, the text as an excerpt, and the file by its own name. A raw payload would make the user approve
 * something only the author of the tool can read, so the id of a share, of a quote or of a room never reaches the
 * visible body while a name is available.
 *
 * It reads and nothing else: no conversation is resolved, no file is looked up and no message is published, because
 * the plan already carries everything the user approves. It also never throws. A field the renderer does not
 * understand is not a failure to show, it is a reason to answer null and let the envelope fall back to its generic
 * body, so a plan this class cannot read is still shown to the user in full rather than not at all.
 */
final class TalkPlanRenderer {
    /** Longest excerpt of a message body the body carries; beyond this the text is cut and says so. */
    public const EXCERPT_MAX_LENGTH = 200;

    /**
     * Renders one plan as Markdown.
     *
     * @param string $tool Name of the writing tool the plan belongs to
     * @param array<string, mixed> $plan Plan returned by WritePreview, as the module built it
     * @return string|null The body, or null when this class cannot read the plan
     */
    public static function render(string $tool, array $plan): ?string {
        try {
            return match ($tool) {
                'talk_reply' => self::reply($plan),
                'talk_send_batch' => self::batch($plan),
                'talk_attach_file' => self::attach($plan),
                'talk_quote_file' => self::quote($plan),
                'talk_message_user' => self::directMessage($plan),
                'talk_create_group' => self::group($plan),
                default => null,
            };
        } catch (Throwable) {
            // The envelope catches this too, but a plan that cannot be rendered must never be the reason a
            // confirmation fails: null is the generic body, which is a worse text and still the right one.
            return null;
        }
    }

    /**
     * A message in a conversation, with the message it answers when the reply quotes one.
     *
     * @param array<string, mixed> $plan Plan of talk_reply
     * @return string|null The body, or null when the conversation or the message is missing
     */
    private static function reply(array $plan): ?string {
        $conversation = self::conversationName($plan);
        $message = self::stringAt($plan, 'draft', 'message');
        if ($conversation === null || $message === null) {
            return null;
        }

        $body = [Messages::planToConversation($conversation), '', self::excerpt($message)];

        $quoted = self::quoteBlock(self::valueAt($plan, 'draft', 'replyTo'));
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
    private static function batch(array $plan): ?string {
        $conversation = self::conversationName($plan);
        $items = self::valueAt($plan, 'draft', 'messages');
        if ($conversation === null || !is_array($items) || $items === []) {
            return null;
        }

        $items = array_values($items);
        $body = [
            count($items) === 1
                ? Messages::planOneMessage($conversation)
                : Messages::planMessages(count($items), $conversation),
            '',
        ];

        foreach ($items as $index => $item) {
            // One unusable item makes the whole batch unreadable: showing the rest would be showing a plan the
            // confirmed call does not match.
            if (!is_array($item)) {
                return null;
            }
            $message = self::stringAt($item, 'message');
            if ($message === null) {
                return null;
            }
            if ($index > 0) {
                $body[] = '';
            }
            $body[] = ($index + 1) . '. ' . self::excerpt($message);

            $quoted = self::quoteBlock($item['replyTo'] ?? null);
            if ($quoted !== null) {
                $body[] = '';
                $body[] = $quoted;
            }
        }

        return implode("\n", $body);
    }

    /**
     * A file shared into a conversation, with the caption that follows it when the call carries one.
     *
     * @param array<string, mixed> $plan Plan of talk_attach_file
     * @return string|null The body, or null when the conversation or the name of the file is missing
     */
    private static function attach(array $plan): ?string {
        $conversation = self::conversationName($plan);
        $file = self::valueAt($plan, 'draft', 'file');
        if ($conversation === null || !is_array($file)) {
            return null;
        }
        $name = self::stringAt($file, 'name');
        if ($name === null) {
            return null;
        }

        $body = [
            Messages::planSharingFile($name, self::size($file['size'] ?? null), $conversation),
        ];

        $caption = self::stringAt($plan, 'draft', 'message');
        if ($caption !== null) {
            $body[] = '';
            $body[] = Messages::planCaptionAfterFile();
            $body[] = '';
            $body[] = self::excerpt($caption);
        }

        return implode("\n", $body);
    }

    /**
     * A message citing an attachment already in the conversation. A share whose name the plan does not carry is
     * named by the conversation instead of by its id: approving "77" tells nobody what is about to be cited.
     *
     * @param array<string, mixed> $plan Plan of talk_quote_file
     * @return string|null The body, or null when the conversation or the file block is missing
     */
    private static function quote(array $plan): ?string {
        $conversation = self::conversationName($plan);
        $file = self::valueAt($plan, 'draft', 'file');
        if ($conversation === null || !is_array($file)) {
            return null;
        }

        $name = self::stringAt($file, 'name');
        $body = [$name === null
            ? Messages::planCitingUnknownFile($conversation)
            : Messages::planCitingFile($name, $conversation)];

        $caption = self::stringAt($plan, 'draft', 'message');
        if ($caption !== null) {
            $body[] = '';
            $body[] = Messages::planCaptionWithCitation();
            $body[] = '';
            $body[] = self::excerpt($caption);
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
    private static function directMessage(array $plan): ?string {
        $person = self::stringAt($plan, 'target', 'displayName');
        $message = self::stringAt($plan, 'draft', 'message');
        if ($person === null || $message === null) {
            return null;
        }

        return implode("\n", [
            Messages::planToPerson($person),
            '',
            self::excerpt($message),
            '',
            Messages::planDirectConversationCreated($person),
        ]);
    }

    /**
     * A new group: its name, and everyone the call would invite.
     *
     * @param array<string, mixed> $plan Plan of talk_create_group
     * @return string|null The body, or null when the name of the group is missing
     */
    private static function group(array $plan): ?string {
        $name = self::stringAt($plan, 'draft', 'name');
        $participants = self::valueAt($plan, 'draft', 'participants');
        if ($name === null || !is_array($participants) || !is_array($plan['draft'] ?? null)) {
            return null;
        }

        $body = [Messages::planCreatingGroup($name)];

        if ($participants === []) {
            $body[] = '';
            $body[] = Messages::planNoGuests();

            return implode("\n", $body);
        }

        $body[] = '';
        $body[] = Messages::planInviting();
        foreach ($participants as $participant) {
            $displayName = is_array($participant) ? self::stringAt($participant, 'displayName') : null;
            if ($displayName === null) {
                return null;
            }
            $body[] = '- ' . PlanText::strong($displayName);
        }

        return implode("\n", $body);
    }

    /**
     * The conversation the plan writes into, by the name the user knows it by.
     *
     * @param array<string, mixed> $plan Plan of a tool that lands in an existing conversation
     * @return string|null The display name, or null when the plan does not carry one
     */
    private static function conversationName(array $plan): ?string {
        return self::stringAt($plan, 'conversation', 'displayName');
    }

    /**
     * The block naming who is being answered and what was answered.
     *
     * @param mixed $quote replyTo block of a plan item, or null when the item quotes nothing
     * @return string|null The block, or null when the quote is absent or incomplete
     */
    private static function quoteBlock(mixed $quote): ?string {
        if (!is_array($quote)) {
            return null;
        }
        $author = self::stringAt($quote, 'author');
        $excerpt = self::stringAt($quote, 'excerpt');
        if ($author === null || $excerpt === null) {
            return null;
        }

        return Messages::planReplyingTo($author) . "\n\n" . self::excerpt($excerpt);
    }

    /**
     * The item a message links to, named by its kind and its title, so the user sees whose item the link exposes.
     *
     * @param array<string, mixed> $plan Plan of talk_reply
     * @return string|null The line, or null when the plan carries no reference or one the renderer cannot name
     */
    private static function referenceLine(array $plan): ?string {
        $reference = $plan['reference'] ?? null;
        if (!is_array($reference)) {
            return null;
        }
        $title = self::stringAt($reference, 'title');
        $label = match ($reference['type'] ?? null) {
            'deck_card' => Messages::referenceLabelDeck(),
            'calendar_event' => Messages::referenceLabelCalendar(),
            default => null,
        };
        if ($title === null || $label === null) {
            return null;
        }

        return Messages::planLinkTo($label, $title);
    }

    /**
     * A message body as a Markdown quote, cut when it is longer than {@see self::EXCERPT_MAX_LENGTH}.
     *
     * @param string $message Message as the plan carries it
     * @return string The block quote, one "> " per line, with no line able to leave the block or to be read as Markdown
     */
    private static function excerpt(string $message): string {
        $text = trim($message);
        $cut = mb_strlen($text) > self::EXCERPT_MAX_LENGTH;
        if ($cut) {
            $text = mb_substr($text, 0, self::EXCERPT_MAX_LENGTH);
        }

        // Every line of the message, whatever ends it ("\n", "\r\n" or a lone "\r"), stays inside the block quote.
        $quoted = PlanText::quote($text, self::EXCERPT_MAX_LENGTH);

        return $cut ? $quoted . "\n> " . Messages::contentTruncated() : $quoted;
    }

    /**
     * A size a person can read, when the plan carries one.
     *
     * @param mixed $bytes Size in bytes, as the plan carries it
     * @return string The size, or an empty string when the plan carries none and the line must not promise one
     */
    private static function size(mixed $bytes): string {
        if (!is_int($bytes) || $bytes < 0) {
            return '';
        }
        if ($bytes < 1024) {
            return Messages::sizeInBytes($bytes);
        }
        if ($bytes < 1024 * 1024) {
            return Messages::sizeInKilobytes(round($bytes / 1024, 1));
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return Messages::sizeInMegabytes(round($bytes / (1024 * 1024), 1));
        }

        return Messages::sizeInGigabytes(round($bytes / (1024 * 1024 * 1024), 1));
    }

    /**
     * Reads a value nested under keys, without assuming any of them is there.
     *
     * @param array<string, mixed> $plan Plan, or fragment, to read from
     * @param string ...$keys Keys to walk, in order
     * @return mixed The value found, or null when any key of the path is missing
     */
    private static function valueAt(array $plan, string ...$keys): mixed {
        $value = $plan;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Reads a non-empty text nested under keys.
     *
     * @param array<string, mixed> $plan Plan, or fragment, to read from
     * @param string ...$keys Keys to walk, in order
     * @return string|null The text, or null when the path is missing or the value is not a usable text
     */
    private static function stringAt(array $plan, string ...$keys): ?string {
        $value = self::valueAt($plan, ...$keys);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}