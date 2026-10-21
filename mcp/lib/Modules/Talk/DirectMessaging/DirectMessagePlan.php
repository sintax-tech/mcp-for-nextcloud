<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\DirectMessaging;

use OCA\Mcp\Modules\Talk\Messaging\MessageText;
use OCA\Mcp\Modules\Talk\Presentation\PlanMessages;

class DirectMessagePlan
{
    /**
     * Preview of a direct message. It carries the target account instead of a conversation token, because the
     * room may not exist yet and showing a plan must not create it: the confirmed call is what opens the
     * conversation.
     *
     * @param DirectContact $target Account the message is addressed to, already checked for reachability
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body, normalized exactly as it will be sent
     * @return array{requiresConfirmation:true, action:string, target:array{id:string, displayName:string}, draft:array{message:string}, message:string}
     * @throws InvalidArgumentException When the message is blank or too long
     */
    public function directMessage(DirectContact $target, string $userId, string $message): array
    {
        $text = MessageText::normalizeMessage($message);

        return [
            'requiresConfirmation' => true,
            'action' => 'talk_message_user',
            'target' => $target->describe(),
            'draft' => ['message' => $text],
            'message' => PlanMessages::confirmationInstructionTarget($target->displayName),
        ];
    }
}
