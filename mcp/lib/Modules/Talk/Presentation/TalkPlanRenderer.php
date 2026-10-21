<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use Throwable;

final class TalkPlanRenderer
{
    /**
     * Renders one plan as Markdown.
     *
     * @param string $tool Name of the writing tool the plan belongs to
     * @param array<string, mixed> $plan Plan returned by WritePreview, as the module built it
     * @return string|null The body, or null when this class cannot read the plan
     */
    public static function render(string $tool, array $plan): ?string
    {
        try {
            return match ($tool) {
                'talk_reply' => MessagePlanRenderer::reply($plan),
                'talk_send_batch' => MessagePlanRenderer::batch($plan),
                'talk_attach_file' => AttachmentPlanRenderer::attach($plan),
                'talk_quote_file' => AttachmentPlanRenderer::quote($plan),
                'talk_message_user' => MessagePlanRenderer::directMessage($plan),
                'talk_create_group' => GroupPlanRenderer::group($plan),
                default => null,
            };
        } catch (Throwable) {
            // The envelope catches this too, but a plan that cannot be rendered must never be the reason a
            // confirmation fails: null is the generic body, which is a worse text and still the right one.
            return null;
        }
    }
}
