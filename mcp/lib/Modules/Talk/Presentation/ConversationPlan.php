<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Modules\Talk\Access\Conversation;

final class ConversationPlan
{
    /**
     * Wraps a plan in the answer the agent shows.
     *
     * @param string $action Tool the plan belongs to
     * @param Conversation $conversation Conversation the write would land in
     * @param string $userId Authenticated user, who sees the conversation name
     * @param array<string, mixed> $draft What the confirmed call would publish
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array<string, mixed>, message:string}
     */
    public static function payload(string $action, Conversation $conversation, string $userId, array $draft): array
    {
        $displayName = $conversation->displayName($userId);

        return [
            'requiresConfirmation' => true,
            'action' => $action,
            'conversation' => [
                'token' => $conversation->token(),
                'displayName' => $displayName,
            ],
            'draft' => $draft,
            'message' => PlanMessages::confirmationInstruction($displayName),
        ];
    }
}
