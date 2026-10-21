<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Talk;
final class TalkMessageFixtures
{
    public static function owner(string $method): string
    {
        return [
            'conversationNotFound' => \OCA\Mcp\Modules\Talk\Access\ConversationMessages::class,
            'conversationNotWritable' => \OCA\Mcp\Modules\Talk\Access\ConversationMessages::class,
            'invalidToken' => \OCA\Mcp\Modules\Talk\Access\ConversationMessages::class,
            'invalidLimit' => \OCA\Mcp\Modules\Talk\Access\ConversationMessages::class,
            'fileNotFound' => \OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::class,
            'attachmentNotFound' => \OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::class,
            'fileNotShared' => \OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::class,
            'fileAlreadyShared' => \OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::class,
            'captionNotSent' => \OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::class,
            'invalidPath' => \OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::class,
            'userNotReachable' => \OCA\Mcp\Modules\Talk\DirectMessaging\ContactMessages::class,
            'participantNotReachable' => \OCA\Mcp\Modules\Talk\DirectMessaging\ContactMessages::class,
            'invalidUser' => \OCA\Mcp\Modules\Talk\DirectMessaging\ContactMessages::class,
            'invitationFailed' => \OCA\Mcp\Modules\Talk\Groups\GroupMessages::class,
            'conversationNotCreated' => \OCA\Mcp\Modules\Talk\Groups\GroupMessages::class,
            'invalidGroupName' => \OCA\Mcp\Modules\Talk\Groups\GroupMessages::class,
            'groupNameTooLong' => \OCA\Mcp\Modules\Talk\Groups\GroupMessages::class,
            'tooManyParticipants' => \OCA\Mcp\Modules\Talk\Groups\GroupMessages::class,
            'referenceLabelDeck' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'referenceLabelCalendar' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'referenceUntitled' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'referenceNotFound' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'referenceDeckOff' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'referenceCalendarOff' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'invalidReference' => \OCA\Mcp\Modules\Talk\References\ReferenceMessages::class,
            'talkUnavailable' => \OCA\Mcp\Modules\Talk\Execution\ErrorMessages::class,
            'unexpected' => \OCA\Mcp\Modules\Talk\Execution\ErrorMessages::class,
            'unknownTool' => \OCA\Mcp\Modules\Talk\Execution\ErrorMessages::class,
            'messageNotSent' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'emptyMessage' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'emptyBatch' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'tooManyMessages' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'messageTooLong' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'invalidIdentifier' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'replyTargetNotFound' => \OCA\Mcp\Modules\Talk\Messaging\MessageMessages::class,
            'confirmationInstructionGroup' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'invitedGuests' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'confirmationInstruction' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'confirmationInstructionTarget' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planToConversation' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planToPerson' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planDirectConversationCreated' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planOneMessage' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planMessages' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planReplyingTo' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planLinkTo' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planSharingFile' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planCaptionAfterFile' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planCitingFile' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planCitingUnknownFile' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planCaptionWithCitation' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planCreatingGroup' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planInviting' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'planNoGuests' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'contentTruncated' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'sizeInBytes' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'sizeInKilobytes' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'sizeInMegabytes' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'sizeInGigabytes' => \OCA\Mcp\Modules\Talk\Presentation\PlanMessages::class,
            'TOOL_LIST_CONVERSATIONS' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_READ_MESSAGES' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_REPLY' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_ATTACH_FILE' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_QUOTE_FILE' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_MESSAGE_USER' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_SEND_BATCH' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
            'TOOL_CREATE_GROUP' => \OCA\Mcp\Modules\Talk\Presentation\ToolMessages::class,
        ][$method];
    }
}
