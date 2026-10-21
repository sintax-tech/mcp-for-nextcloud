<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

final class AttachmentPlanRenderer
{
    /**
     * A file shared into a conversation, with the caption that follows it when the call carries one.
     *
     * @param array<string, mixed> $plan Plan of talk_attach_file
     * @return string|null The body, or null when the conversation or the name of the file is missing
     */
    public static function attach(array $plan): ?string
    {
        $conversation = PlanFields::conversationName($plan);
        $file = PlanFields::valueAt($plan, 'draft', 'file');
        if ($conversation === null || !is_array($file)) {
            return null;
        }
        $name = PlanFields::stringAt($file, 'name');
        if ($name === null) {
            return null;
        }

        $body = [PlanMessages::planSharingFile($name, self::size($file['size'] ?? null), $conversation)];

        $caption = PlanFields::stringAt($plan, 'draft', 'message');
        if ($caption !== null) {
            $body[] = '';
            $body[] = PlanMessages::planCaptionAfterFile();
            $body[] = '';
            $body[] = MessageExcerpt::excerpt($caption);
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
    public static function quote(array $plan): ?string
    {
        $conversation = PlanFields::conversationName($plan);
        $file = PlanFields::valueAt($plan, 'draft', 'file');
        if ($conversation === null || !is_array($file)) {
            return null;
        }

        $name = PlanFields::stringAt($file, 'name');
        $body = [
            $name === null
                ? PlanMessages::planCitingUnknownFile($conversation)
                : PlanMessages::planCitingFile($name, $conversation),
        ];

        $caption = PlanFields::stringAt($plan, 'draft', 'message');
        if ($caption !== null) {
            $body[] = '';
            $body[] = PlanMessages::planCaptionWithCitation();
            $body[] = '';
            $body[] = MessageExcerpt::excerpt($caption);
        }

        return implode("\n", $body);
    }

    /**
     * A size a person can read, when the plan carries one.
     *
     * @param mixed $bytes Size in bytes, as the plan carries it
     * @return string The size, or an empty string when the plan carries none and the line must not promise one
     */
    private static function size(mixed $bytes): string
    {
        if (!is_int($bytes) || $bytes < 0) {
            return '';
        }
        if ($bytes < 1024) {
            return PlanMessages::sizeInBytes($bytes);
        }
        if ($bytes < 1024 * 1024) {
            return PlanMessages::sizeInKilobytes(round($bytes / 1024, 1));
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return PlanMessages::sizeInMegabytes(round($bytes / (1024 * 1024), 1));
        }

        return PlanMessages::sizeInGigabytes(round($bytes / (1024 * 1024 * 1024), 1));
    }
}
