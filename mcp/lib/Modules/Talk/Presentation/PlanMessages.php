<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

final class PlanMessages
{
    /**
     * Instruction shown next to a group draft.
     *
     * @param string $group Name of the group
     * @param string $guests Sentence listing the invited people, already translated; empty when nobody is invited
     * @return string The instruction in the language of the current user
     */
    public static function confirmationInstructionGroup(string $group, string $guests): string
    {
        return Translator::t(
            'I will create the Talk group \'%s\' with you as the owner%s. Show this plan to the user and only repeat the same call with confirm: true after they explicitly say yes; if they ask for changes, edit the plan and call the tool again. Without that confirmation the server creates nothing.',
            [$group, $guests],
        );
    }

    /**
     * Sentence naming the invited people at the end of the group instruction.
     *
     * @param list<string> $names Display names of the invited people, in the order the draft shows them
     * @return string The sentence in the language of the current user, or an empty string when nobody is invited
     */
    public static function invitedGuests(array $names): string
    {
        return $names === [] ? '' : Translator::t(' and as guests %s', [implode(', ', $names)]);
    }

    /**
     * Instruction shown next to a draft.
     *
     * @param string $conversation Display name of the conversation
     * @return string The instruction in the language of the current user
     */
    public static function confirmationInstruction(string $conversation): string
    {
        return Translator::t(
            'Show this plan to the user exactly as it will be sent to the conversation \'%s\', ask whether they really want it and only repeat the same call with confirm: true after they explicitly say yes. If they ask for changes, adjust the text, call the tool again and show the new plan. Without that confirmation the server publishes nothing.',
            [$conversation],
        );
    }

    /**
     * Instruction shown next to a direct message draft.
     *
     * @param string $person Display name of the person being written to
     * @return string The instruction in the language of the current user
     */
    public static function confirmationInstructionTarget(string $person): string
    {
        return Translator::t(
            'Show this plan to the user exactly as it will be sent in a direct conversation with \'%s\', ask whether they really want it and only repeat the same call with confirm: true after they explicitly say yes. If they ask for changes, adjust the text, call the tool again and show the new plan. Without that confirmation the server publishes nothing and the direct conversation is not created.',
            [$person],
        );
    }

    /**
     * Opening line of a plan that lands in a conversation.
     *
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planToConversation(string $conversation): string
    {
        return Translator::t('Message to **%s**:', [PlanText::inline($conversation)]);
    }

    /**
     * Opening line of a plan that opens a direct conversation.
     *
     * @param string $person Display name of the person being written to
     * @return string The line in the language of the current user
     */
    public static function planToPerson(string $person): string
    {
        return Translator::t('Direct message to **%s**:', [PlanText::inline($person)]);
    }

    /**
     * Consequence of a direct message: the plan carries no conversation because there may be none yet.
     *
     * @param string $person Display name of the person being written to
     * @return string The line in the language of the current user
     */
    public static function planDirectConversationCreated(string $person): string
    {
        return Translator::t('The direct conversation with **%s** will be created if it does not exist yet.', [
            PlanText::inline($person),
        ]);
    }

    /**
     * Opening line of a batch of one, so a single message never reads as a list.
     *
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planOneMessage(string $conversation): string
    {
        return Translator::t('One message to **%s**:', [PlanText::inline($conversation)]);
    }

    /**
     * Opening line of a batch of several messages.
     *
     * @param int $count How many messages the batch carries
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planMessages(int $count, string $conversation): string
    {
        return Translator::t('%d messages to **%s**:', [$count, PlanText::inline($conversation)]);
    }

    /**
     * Who the message answers, named the way the user knows them.
     *
     * @param string $author Display name of the quoted author
     * @return string The line in the language of the current user
     */
    public static function planReplyingTo(string $author): string
    {
        return Translator::t('Replying to **%s**:', [PlanText::inline($author)]);
    }

    /**
     * The item a message links to, so the user sees whose item the link exposes.
     *
     * @param string $label Kind of the item, already translated
     * @param string $title Title of the item
     * @return string The line in the language of the current user
     */
    public static function planLinkTo(string $label, string $title): string
    {
        return Translator::t('The message also carries a link to the %s *%s*.', [
            PlanText::inline($label),
            PlanText::inline($title),
        ]);
    }

    /**
     * Opening line of an attachment plan.
     *
     * @param string $name Name of the file
     * @param string $size Size of the file, already formatted
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planSharingFile(string $name, string $size, string $conversation): string
    {
        return Translator::t('The file **%s** (%s) will be shared in **%s**.', [
            PlanText::inline($name),
            PlanText::inline($size),
            PlanText::inline($conversation),
        ]);
    }

    /**
     * @return string The line introducing the caption of an attachment, in the language of the current user
     */
    public static function planCaptionAfterFile(): string
    {
        return Translator::t('Caption, sent as a message right after the file:');
    }

    /**
     * Opening line of a plan citing an attachment already in the conversation.
     *
     * @param string $name Name of the cited file
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planCitingFile(string $name, string $conversation): string
    {
        return Translator::t('A message will be published in **%s** citing the file **%s**.', [
            PlanText::inline($conversation),
            PlanText::inline($name),
        ]);
    }

    /**
     * Opening line of a plan citing an attachment whose name the plan does not carry.
     *
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planCitingUnknownFile(string $conversation): string
    {
        return Translator::t('A message will be published in **%s** citing an attachment already shared there.', [
            PlanText::inline($conversation),
        ]);
    }

    /**
     * @return string The line introducing the caption of a citation, in the language of the current user
     */
    public static function planCaptionWithCitation(): string
    {
        return Translator::t('Caption shown with the citation:');
    }

    /**
     * Opening line of a group plan.
     *
     * @param string $group Name of the group
     * @return string The line in the language of the current user
     */
    public static function planCreatingGroup(string $group): string
    {
        return Translator::t('The Talk group **%s** will be created with you as the owner.', [
            PlanText::inline($group),
        ]);
    }

    /**
     * @return string The line introducing the invited people, in the language of the current user
     */
    public static function planInviting(): string
    {
        return Translator::t('These people will be invited:');
    }

    /**
     * @return string The line saying nobody is invited, in the language of the current user
     */
    public static function planNoGuests(): string
    {
        return Translator::t('Nobody is invited: the group starts with you alone.');
    }

    /**
     * Marker left when a long text was cut to fit the plan.
     * @return string The marker in the language of the current user.
     */
    public static function contentTruncated(): string
    {
        return Translator::t('[content truncated]');
    }

    /**
     * A size a person can read at a glance instead of a byte count.
     *
     * @param int $bytes Size in bytes
     * @return string The size in the language of the current user
     */
    public static function sizeInBytes(int $bytes): string
    {
        return Translator::t('%d bytes', [$bytes]);
    }

    /**
     * @param float $kilobytes Size in kilobytes, already rounded
     * @return string The size in the language of the current user
     */
    public static function sizeInKilobytes(float $kilobytes): string
    {
        return Translator::t('%s kB', [rtrim(rtrim(number_format($kilobytes, 1, '.', ''), '0'), '.')]);
    }

    /**
     * @param float $megabytes Size in megabytes, already rounded
     * @return string The size in the language of the current user
     */
    public static function sizeInMegabytes(float $megabytes): string
    {
        return Translator::t('%s MB', [rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.')]);
    }

    /**
     * @param float $gigabytes Size in gigabytes, already rounded
     * @return string The size in the language of the current user
     */
    public static function sizeInGigabytes(float $gigabytes): string
    {
        return Translator::t('%s GB', [rtrim(rtrim(number_format($gigabytes, 1, '.', ''), '0'), '.')]);
    }
}
