<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCA\Mcp\L10n\Translator;

/**
 * Every string of the Talk module lives here.
 *
 * Tool descriptions are read by the model, not by the person, so they are fixed English constants. What the user
 * can see (failures, the draft instructions, the labels a message carries) is a static method that translates an
 * English source text through {@see Translator}, in the language of the authenticated user.
 */
final class Messages {
    /* Tool descriptions, exposed to MCP clients in tools/list. */
    public const TOOL_LIST_CONVERSATIONS = 'Lists the Talk conversations the authenticated user takes part in, with the number of unread messages. It changes no state: it does not mark anything as read, does not touch notifications and does not touch activity.';
    public const TOOL_READ_MESSAGES = 'Reads the latest messages of a conversation, without marking anything as read, without touching notifications and without recording activity. Use the id returned in reply_to to answer and attachmentId to cite an attachment.';
    public const TOOL_REPLY = 'Sends a message in a conversation, optionally quoting another message of the same conversation with reply_to. To point at a Deck card or a calendar event, send reference with {type: deck_card, card_id, board_id optional} or {type: calendar_event, calendar (the path reported by calendar_list_calendars), uid}: the server checks that the user can access the item and appends the title and the link of the item to the text, and the draft already shows the final text. Anyone in the conversation who cannot access the item will see the title and the link, so only reference what the user approves showing. Before sending, the agent MUST call the tool without confirm, which returns a plan and writes nothing, show that plan to the user and ask whether they really want it; only after an explicit yes repeat the same call with confirm: true and the same arguments. Nothing is kept on the server: there is no approval id, no token and no expiry, so the approval itself depends on the agent showing the plan and on the user saying yes in the client.';
    public const TOOL_ATTACH_FILE = 'Shares into the conversation a file that already exists in the user\'s Nextcloud; Talk itself publishes the attachment card. When message is filled in, it is sent as a second text message right after the attachment, and messageId carries the id of that message. If the file is already shared in this conversation, nothing is published. If the share is created but the caption fails, the answer is not an error: it comes with attachmentId and captionSent false plus the instruction to send the caption with talk_reply, because the attachment is already in the chat. Before sending, the agent MUST call the tool without confirm, which returns a plan and writes nothing, show that plan to the user and ask whether they really want it; only after an explicit yes repeat the same call with confirm: true and the same arguments. Nothing is kept on the server: there is no approval id, no token and no expiry, so the approval itself depends on the agent showing the plan and on the user saying yes in the client.';
    public const TOOL_QUOTE_FILE = 'Publishes a message citing an attachment already present in the conversation, identified by attachment_id, with an optional caption in message. Before sending, the agent MUST call the tool without confirm, which returns a plan and writes nothing, show that plan to the user and ask whether they really want it; only after an explicit yes repeat the same call with confirm: true and the same arguments. Nothing is kept on the server: there is no approval id, no token and no expiry, so the approval itself depends on the agent showing the plan and on the user saying yes in the client.';

    public const TOOL_MESSAGE_USER = 'Sends a direct message to a user, without needing the conversation token: send user with the account id (if you are not sure of it, call users_search first). The direct conversation is created when it does not exist yet, the way Talk does when a chat is started; the preview never creates anything. Before sending, the agent MUST call the tool without confirm, which returns a plan and writes nothing, show that plan to the user and ask whether they really want it; only after an explicit yes repeat the same call with confirm: true and the same arguments. Nothing is kept on the server: there is no approval id, no token and no expiry, so the approval itself depends on the agent showing the plan and on the user saying yes in the client.';

    public const TOOL_SEND_BATCH = 'Sends several messages at once in the same conversation, each one optionally quoting an earlier message: send messages with the list of objects {message, reply_to}. Use it when the answer is a sequence of short messages (one per item of a list, for instance), and prefer talk_reply when it is a single message. The conversation and the quotes are checked before anything is sent; sending happens item by item and each item is reported in sent or failed, without repeating what was already published. Before sending, the agent MUST call the tool without confirm, which returns a plan and writes nothing, show that plan to the user and ask whether they really want it; only after an explicit yes repeat the same call with confirm: true and the same arguments. Nothing is kept on the server: there is no approval id, no token and no expiry, s the confirmation covers the whole batch, not each item, and the confirmation itself depends on the agent showing the plan and on the user saying yes in the client.';

    public const TOOL_CREATE_GROUP = 'Creates a group conversation in Talk, with the authenticated user as its owner: send name with the name of the group and participants with the list of accounts to invite (optional). Every guest goes through the same visibility rule as a direct message, and all of them appear in the preview before any invitation. Creating a group invites people and this tool does not undo an invitation once it has been made, so the operation requires the talk.create grant and an explicit approval. Before sending, the agent MUST call the tool without confirm, which returns a plan and creates nothing, show that plan to the user and ask whether they really want it; only after an explicit yes repeat the same call with confirm: true and the same arguments. Nothing is kept on the server: there is no approval id, no token and no expiry, so the confirmation covers the whole group, name and guests together, and it itself depends on the agent showing the plan and on the user saying yes in the client. Whoever does not approve does not get an empty group in the list: the room is only created by the approved call. The invitations are made one by one after the creation: whoever joined comes in participants and whoever Talk refused comes in invitations_failed, with the id of each one; in that case the group already exists, so do not repeat the call, tell the user who was left out.';

    private function __construct() {
    }

    /**
     * Instruction shown next to a group draft.
     *
     * @param string $group Name of the group
     * @param string $guests Sentence listing the invited people, already translated; empty when nobody is invited
     * @return string The instruction in the language of the current user
     */
    public static function confirmationInstructionGroup(string $group, string $guests): string {
        return Translator::t('I will create the Talk group \'%s\' with you as the owner%s. Show this plan to the user and only repeat the same call with confirm: true after they explicitly say yes; if they ask for changes, edit the plan and call the tool again. Without that confirmation the server creates nothing.', [$group, $guests]);
    }

    /**
     * Sentence naming the invited people at the end of the group instruction.
     *
     * @param list<string> $names Display names of the invited people, in the order the draft shows them
     * @return string The sentence in the language of the current user, or an empty string when nobody is invited
     */
    public static function invitedGuests(array $names): string {
        return $names === [] ? '' : Translator::t(' and as guests %s', [implode(', ', $names)]);
    }

    /**
     * Instruction shown next to a draft.
     *
     * @param string $conversation Display name of the conversation
     * @return string The instruction in the language of the current user
     */
    public static function confirmationInstruction(string $conversation): string {
        return Translator::t('Show this plan to the user exactly as it will be sent to the conversation \'%s\', ask whether they really want it and only repeat the same call with confirm: true after they explicitly say yes. If they ask for changes, adjust the text, call the tool again and show the new plan. Without that confirmation the server publishes nothing.', [$conversation]);
    }

    /**
     * Instruction shown next to a direct message draft.
     *
     * @param string $person Display name of the person being written to
     * @return string The instruction in the language of the current user
     */
    public static function confirmationInstructionTarget(string $person): string {
        return Translator::t('Show this plan to the user exactly as it will be sent in a direct conversation with \'%s\', ask whether they really want it and only repeat the same call with confirm: true after they explicitly say yes. If they ask for changes, adjust the text, call the tool again and show the new plan. Without that confirmation the server publishes nothing and the direct conversation is not created.', [$person]);
    }

    /**
     * Label of the line a Deck reference adds to a message, before the title of the item.
     * @return string The label in the language of the current user.
     */
    public static function referenceLabelDeck(): string {
        return Translator::t('Deck card');
    }

    /**
     * Label of the line a calendar reference adds to a message, before the title of the item.
     * @return string The label in the language of the current user.
     */
    public static function referenceLabelCalendar(): string {
        return Translator::t('Calendar event');
    }

    /** Title shown for an event without SUMMARY. @return string The title in the language of the current user. */
    public static function referenceUntitled(): string {
        return Translator::t('(no title)');
    }

    /**
     * The conversation does not exist for this account, or it is not readable.
     * @return string The message in the language of the current user.
     */
    public static function conversationNotFound(): string {
        return Translator::t('conversation not found or not accessible');
    }

    /**
     * The conversation exists but this account may not write in it.
     * @return string The message in the language of the current user.
     */
    public static function conversationNotWritable(): string {
        return Translator::t('no permission to write in this conversation');
    }

    /**
     * The file is missing, outside the user folder or not readable.
     * @return string The message in the language of the current user.
     */
    public static function fileNotFound(): string {
        return Translator::t('file not found or not accessible');
    }

    /**
     * The attachment id is not a share of this conversation.
     * @return string The message in the language of the current user.
     */
    public static function attachmentNotFound(): string {
        return Translator::t('attachment not found in this conversation');
    }

    /**
     * The account does not exist or may not be messaged by this account.
     * @return string The message in the language of the current user.
     */
    public static function userNotReachable(): string {
        return Translator::t('user not found or no permission to message them');
    }

    /**
     * Refusal of one guest of a group.
     *
     * @param string $accountId Account id the caller sent
     * @return string The message in the language of the current user
     */
    public static function participantNotReachable(string $accountId): string {
        return Translator::t('participant \'%s\' not found or no permission to message them', [$accountId]);
    }

    /**
     * Reported per guest in invitations_failed, after the group already exists.
     * @return string The message in the language of the current user.
     */
    public static function invitationFailed(): string {
        return Translator::t('Talk did not accept the invitation of this participant');
    }

    /**
     * The referenced Deck card or calendar event does not exist or is not readable.
     * @return string The message in the language of the current user.
     */
    public static function referenceNotFound(): string {
        return Translator::t('referenced item not found or not accessible');
    }

    /**
     * The Deck app is off for this account, or the MCP has no read grant in Deck.
     * @return string The message in the language of the current user.
     */
    public static function referenceDeckOff(): string {
        return Translator::t('Deck reference unavailable: the Deck app is turned off for you or the MCP has no read permission in Deck');
    }

    /**
     * The Calendar app is off for this account, or the MCP has no read grant in Calendar.
     * @return string The message in the language of the current user.
     */
    public static function referenceCalendarOff(): string {
        return Translator::t('Calendar reference unavailable: the Calendar app is turned off for you or the MCP has no read permission in Calendar');
    }

    /**
     * Talk did not create the room.
     * @return string The message in the language of the current user.
     */
    public static function conversationNotCreated(): string {
        return Translator::t('conversation not created by Talk');
    }

    /**
     * Talk refused the message.
     * @return string The message in the language of the current user.
     */
    public static function messageNotSent(): string {
        return Translator::t('the message could not be sent');
    }

    /**
     * The share could not be created.
     * @return string The message in the language of the current user.
     */
    public static function fileNotShared(): string {
        return Translator::t('the file could not be shared');
    }

    /**
     * The file already carries a card in this conversation, so nothing new is published.
     * @return string The message in the language of the current user.
     */
    public static function fileAlreadyShared(): string {
        return Translator::t('the file is already shared in this conversation');
    }

    /**
     * The share was created but the caption failed, so the caption has to be sent as its own message.
     * @return string The message in the language of the current user.
     */
    public static function captionNotSent(): string {
        return Translator::t('The attachment was shared in this conversation, but the caption was not sent. Do not repeat talk_attach_file: the file is already shared and a new card would not be created. If the user still wants the caption, send it as a new message with talk_reply, following the same approval.');
    }

    /**
     * spreed is not installed or not enabled for this account.
     * @return string The message in the language of the current user.
     */
    public static function talkUnavailable(): string {
        return Translator::t('the conversations app is not available');
    }

    /**
     * Fallback for anything unexpected.
     * @return string The message in the language of the current user.
     */
    public static function unexpected(): string {
        return Translator::t('unexpected error while accessing Talk');
    }

    /**
     * The conversation token is missing or is not a string.
     * @return string The message in the language of the current user.
     */
    public static function invalidToken(): string {
        return Translator::t('invalid conversation_token');
    }

    /**
     * The message body is missing or blank.
     * @return string The message in the language of the current user.
     */
    public static function emptyMessage(): string {
        return Translator::t('message cannot be empty');
    }

    /**
     * The batch carries no message at all.
     * @return string The message in the language of the current user.
     */
    public static function emptyBatch(): string {
        return Translator::t('send at least one message in messages');
    }

    /**
     * The batch is longer than the module accepts.
     *
     * @param int $max Largest batch accepted
     * @return string The message in the language of the current user
     */
    public static function tooManyMessages(int $max): string {
        return Translator::t('batch with more than %d messages', [$max]);
    }

    /**
     * The message body is over the module character limit.
     * @return string The message in the language of the current user.
     */
    public static function messageTooLong(): string {
        return Translator::t('message exceeds the character limit');
    }

    /**
     * The path is missing, absolute or traverses outside the user folder.
     * @return string The message in the language of the current user.
     */
    public static function invalidPath(): string {
        return Translator::t('invalid path');
    }

    /**
     * The account id is missing or is not a string.
     * @return string The message in the language of the current user.
     */
    public static function invalidUser(): string {
        return Translator::t('invalid user');
    }

    /**
     * The reference object does not follow {type: deck_card|calendar_event, ...}.
     * @return string The message in the language of the current user.
     */
    public static function invalidReference(): string {
        return Translator::t('invalid reference: use {type: deck_card, card_id, board_id?} or {type: calendar_event, calendar, uid}');
    }

    /**
     * The group name is blank once normalized.
     * @return string The message in the language of the current user.
     */
    public static function invalidGroupName(): string {
        return Translator::t('the group name cannot be empty');
    }

    /**
     * The group name is over the module character limit.
     * @return string The message in the language of the current user.
     */
    public static function groupNameTooLong(): string {
        return Translator::t('the group name exceeds the character limit');
    }

    /**
     * The group would invite more people than the module accepts.
     *
     * @param int $max Largest number of guests accepted
     * @return string The message in the language of the current user
     */
    public static function tooManyParticipants(int $max): string {
        return Translator::t('group with more than %d participants', [$max]);
    }

    /**
     * A reply or attachment id is not a positive integer.
     * @return string The message in the language of the current user.
     */
    public static function invalidIdentifier(): string {
        return Translator::t('invalid identifier');
    }

    /**
     * The limit argument is outside the accepted range.
     * @return string The message in the language of the current user.
     */
    public static function invalidLimit(): string {
        return Translator::t('limit must be a number between 1 and 200');
    }

    /**
     * The quoted message does not exist in this conversation.
     * @return string The message in the language of the current user.
     */
    public static function replyTargetNotFound(): string {
        return Translator::t('quoted message not found in this conversation');
    }

    /**
     * Raised for a tool name this module does not serve.
     * @return string The message in the language of the current user.
     */
    public static function unknownTool(): string {
        return Translator::t('unknown tool');
    }

    /* Texts of the human plan, the body a person reads before approving a write. */

    /**
     * Opening line of a plan that lands in a conversation.
     *
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planToConversation(string $conversation): string {
        return Translator::t('Message to **%s**:', [$conversation]);
    }

    /**
     * Opening line of a plan that opens a direct conversation.
     *
     * @param string $person Display name of the person being written to
     * @return string The line in the language of the current user
     */
    public static function planToPerson(string $person): string {
        return Translator::t('Direct message to **%s**:', [$person]);
    }

    /**
     * Consequence of a direct message: the plan carries no conversation because there may be none yet.
     *
     * @param string $person Display name of the person being written to
     * @return string The line in the language of the current user
     */
    public static function planDirectConversationCreated(string $person): string {
        return Translator::t('The direct conversation with **%s** will be created if it does not exist yet.', [$person]);
    }

    /**
     * Opening line of a batch of one, so a single message never reads as a list.
     *
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planOneMessage(string $conversation): string {
        return Translator::t('One message to **%s**:', [$conversation]);
    }

    /**
     * Opening line of a batch of several messages.
     *
     * @param int $count How many messages the batch carries
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planMessages(int $count, string $conversation): string {
        return Translator::t('%d messages to **%s**:', [$count, $conversation]);
    }

    /**
     * Who the message answers, named the way the user knows them.
     *
     * @param string $author Display name of the quoted author
     * @return string The line in the language of the current user
     */
    public static function planReplyingTo(string $author): string {
        return Translator::t('Replying to **%s**:', [$author]);
    }

    /**
     * The item a message links to, so the user sees whose item the link exposes.
     *
     * @param string $label Kind of the item, already translated
     * @param string $title Title of the item
     * @return string The line in the language of the current user
     */
    public static function planLinkTo(string $label, string $title): string {
        return Translator::t('The message also carries a link to the %s *%s*.', [$label, $title]);
    }

    /**
     * Opening line of an attachment plan.
     *
     * @param string $name Name of the file
     * @param string $size Size of the file, already formatted
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planSharingFile(string $name, string $size, string $conversation): string {
        return Translator::t('The file **%s** (%s) will be shared in **%s**.', [$name, $size, $conversation]);
    }

    /**
     * @return string The line introducing the caption of an attachment, in the language of the current user
     */
    public static function planCaptionAfterFile(): string {
        return Translator::t('Caption, sent as a message right after the file:');
    }

    /**
     * Opening line of a plan citing an attachment already in the conversation.
     *
     * @param string $name Name of the cited file
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planCitingFile(string $name, string $conversation): string {
        return Translator::t('A message will be published in **%s** citing the file **%s**.', [$conversation, $name]);
    }

    /**
     * Opening line of a plan citing an attachment whose name the plan does not carry.
     *
     * @param string $conversation Name of the conversation, the way the user knows it
     * @return string The line in the language of the current user
     */
    public static function planCitingUnknownFile(string $conversation): string {
        return Translator::t('A message will be published in **%s** citing an attachment already shared there.', [$conversation]);
    }

    /**
     * @return string The line introducing the caption of a citation, in the language of the current user
     */
    public static function planCaptionWithCitation(): string {
        return Translator::t('Caption shown with the citation:');
    }

    /**
     * Opening line of a group plan.
     *
     * @param string $group Name of the group
     * @return string The line in the language of the current user
     */
    public static function planCreatingGroup(string $group): string {
        return Translator::t('The Talk group **%s** will be created with you as the owner.', [$group]);
    }

    /**
     * @return string The line introducing the invited people, in the language of the current user
     */
    public static function planInviting(): string {
        return Translator::t('These people will be invited:');
    }

    /**
     * @return string The line saying nobody is invited, in the language of the current user
     */
    public static function planNoGuests(): string {
        return Translator::t('Nobody is invited: the group starts with you alone.');
    }

    /**
     * Marker left when a long text was cut to fit the plan.
     * @return string The marker in the language of the current user.
     */
    public static function contentTruncated(): string {
        return Translator::t('[content truncated]');
    }

    /**
     * A size a person can read at a glance instead of a byte count.
     *
     * @param int $bytes Size in bytes
     * @return string The size in the language of the current user
     */
    public static function sizeInBytes(int $bytes): string {
        return Translator::t('%d bytes', [$bytes]);
    }

    /**
     * @param float $kilobytes Size in kilobytes, already rounded
     * @return string The size in the language of the current user
     */
    public static function sizeInKilobytes(float $kilobytes): string {
        return Translator::t('%s kB', [rtrim(rtrim(number_format($kilobytes, 1, '.', ''), '0'), '.')]);
    }

    /**
     * @param float $megabytes Size in megabytes, already rounded
     * @return string The size in the language of the current user
     */
    public static function sizeInMegabytes(float $megabytes): string {
        return Translator::t('%s MB', [rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.')]);
    }

    /**
     * @param float $gigabytes Size in gigabytes, already rounded
     * @return string The size in the language of the current user
     */
    public static function sizeInGigabytes(float $gigabytes): string {
        return Translator::t('%s GB', [rtrim(rtrim(number_format($gigabytes, 1, '.', ''), '0'), '.')]);
    }
}
