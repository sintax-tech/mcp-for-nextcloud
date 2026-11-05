<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/**
 * What the app relies on from Nextcloud beyond what {@see LibScanner} finds by itself, for
 * {@see NextcloudApiContractTest}.
 *
 * The scanner sees every Nextcloud class named in lib/ and every method name called there. It cannot see the
 * parameters: Talk is held in untyped objects and Deck is resolved by class name, so a positional argument that moved
 * between releases (as `ChatManager::addSystemMessage()` gained `$participant` in Talk 21.1.4) would only fail on that
 * server. {@see self::POSITIONAL} lists those calls with the parameters the app fills, in order.
 */
final class NextcloudApiUsage {
	/**
	 * Releases each fixture of tests/fixtures/nextcloud-api is generated from: the oldest release of every declared
	 * Nextcloud major, with the oldest Deck and Talk MCP supports on it ({@see \OCA\Mcp\Platform\Compat\AppEnablement}).
	 */
	public const SOURCES = [
		'31' => ['server' => '31.0.0', 'deck' => '1.15.0', 'spreed' => '21.1.4'],
		'32' => ['server' => '32.0.0', 'deck' => '1.16.0', 'spreed' => '22.0.0'],
		'33' => ['server' => '33.0.0', 'deck' => '1.17.0', 'spreed' => '23.0.0'],
		'34' => ['server' => '34.0.0', 'deck' => '1.18.0', 'spreed' => '24.0.0'],
		'35' => ['server' => '35.0.0', 'deck' => '1.19.0', 'spreed' => '25.0.0'],
	];

	/**
	 * Classes lib/ names that the fixtures do not carry, by app: the Guests app is not part of the trees the fixtures are
	 * generated from. {@see \OCA\Mcp\Access\Guests\GuestRestrictions} resolves these services by name and refuses the
	 * request when one is missing or fails, and Integration/Guests/GuestRestrictionsTest calls them on the release the
	 * harness pins (guests v4.10.1).
	 */
	public const OUTSIDE_FIXTURES = [
		'guests' => ['OCA\\Guests\\AppWhitelist', 'OCA\\Guests\\Config', 'OCA\\Guests\\GuestManager', 'OCA\\Guests\\RestrictionManager'],
	];

	/**
	 * Classes the fixtures carry although lib/ never names them: objects the app only receives (a Talk participant,
	 * a metadata set, the log writer ILogFactory::get() returns) and the Nextcloud plugin whose listener
	 * {@see \OCA\Mcp\Modules\Calendar\Writing\EmbeddedDavDispatcher} detaches.
	 */
	public const EXTRA_CLASSES = [
		'OCA\\Circles\\Model\\Circle',
		'OCA\\Talk\\Participant',
		'OCP\\FilesMetadata\\Model\\IFilesMetadata',
		'OCA\\DAV\\CalDAV\\Schedule\\Plugin',
		'OCP\\Log\\IWriter',
		'OCP\\Group\\Backend\\IIsAdminBackend',
		// The Mail account and folder OCA\Mcp\Modules\Mail\Integration\MailGateway receives from AccountService and MailboxMapper.
		'OCA\\Mail\\Account',
		'OCA\\Mail\\Db\\Mailbox',
		// The cached message, its address lists and the message with its body that the message tools receive.
		'OCA\\Mail\\Db\\Message',
		'OCA\\Mail\\AddressList',
		'OCA\\Mail\\Address',
		'OCA\\Mail\\Model\\IMAPMessage',
		// The attachment OCA\Mcp\Modules\Mail\Integration\MailAttachmentReader receives from the IMAP message mapper.
		'OCA\\Mail\\Attachment',
		// The flag event OCA\Mcp\Modules\Mail\Integration\MailFlagEvent dispatches after a flag change, as the Mail app does.
		'OCA\\Mail\\Events\\MessageFlaggedEvent',
		// The settings of an account (its Trash, Junk folder and Trash retention) OCA\Mcp\Modules\Mail\Moving\MailMoveDestination
		// reads, and the delete event OCA\Mcp\Modules\Mail\Moving\MoveMessageHandler dispatches after a move, as the Mail
		// app's own move does.
		'OCA\\Mail\\Db\\MailAccount',
		'OCA\\Mail\\Events\\MessageDeletedEvent',
		// The Mail app's own draft save and the message it takes, which OCA\Mcp\Modules\Mail\Integration\MailDraftStore uses through
		// OCA\Mcp\Modules\Mail\Integration\MailServices.
		'OCA\\Mail\\Service\\MailTransmission',
		'OCA\\Mail\\Model\\NewMessageData',
		// The Mail app's Outbox and its messages, which OCA\Mcp\Modules\Mail\Integration\MailSendOutbox sends through, as the Mail
		// app's own OutboxController does.
		'OCA\\Mail\\Service\\OutboxService',
		'OCA\\Mail\\Db\\LocalMessage',
		// The Nextcloud Tables app, kept with the Tables module's contract.
		...Tables\TablesApiUsage::EXTRA_CLASSES,
		...Forms\FormsApiUsage::EXTRA_CLASSES,
	];

	/**
	 * Calls whose arguments are positional and unchecked by any type, with the parameters the app fills in order.
	 * Every declared release must have these names at these positions and nothing else required after them.
	 *
	 * @var array<string, list<string>>
	 */
	public const POSITIONAL = [
		'OCP\\IGroup::count' => [],
		// BoardRecipientResolver authorizes the source before DeckRecipientDirectory reads complete native ACLs/readers.
		'OCA\\Deck\\Db\\AclMapper::findAll' => ['boardId'],
		'OCA\\Deck\\Service\\CirclesService::getCircle' => ['circleId'],
		'OCA\\Circles\\Model\\Circle::getInheritedMembers' => ['detailed'],
		'OCA\\Circles\\Model\\Circle::isLocal' => [],
		'OCA\\Circles\\Model\\Circle::getSingleId' => [],
		'OCA\\Circles\\Model\\Member::getUserId' => [],
		'OCA\\Circles\\Model\\Member::getSingleId' => [],
		'OCA\\Circles\\Model\\Member::getBasedOn' => [],
		'OCA\\Circles\\Model\\Member::getUserType' => [],
		'OCA\\Circles\\Model\\Member::getLevel' => [],
		'OCA\\Circles\\Model\\Member::isLocal' => [],
		'OCA\\Deck\\Service\\CirclesService::isCirclesEnabled' => [],
		// Fresh native administration follows the manager's public optional backend contract.
		'OCP\\Group\\Backend\\IIsAdminBackend::isAdmin' => ['uid'],
		// Talk, held untyped by OCA\Mcp\Tools\Talk.
		'OCA\\Talk\\Manager::getRoomForUserByToken' => ['token', 'userId'],
		'OCA\\Talk\\Manager::getRoomsForUser' => ['userId'],
		'OCA\\Talk\\Service\\ParticipantService::getParticipant' => ['room', 'userId', 'sessionId'],
		'OCA\\Talk\\Service\\ParticipantService::ensureOneToOneRoomIsFilled' => ['room'],
		'OCA\\Talk\\Service\\ParticipantService::addUsers' => ['room', 'participants', 'addedBy'],
		'OCA\\Talk\\Chat\\ChatManager::sendMessage' => ['chat', 'participant', 'actorType', 'actorId', 'message', 'creationDateTime', 'replyTo'],
		'OCA\\Talk\\Chat\\ChatManager::addSystemMessage' => ['chat', 'participant', 'actorType', 'actorId', 'message', 'creationDateTime', 'sendNotifications'],
		'OCA\\Talk\\Chat\\ChatManager::getParentComment' => ['chat', 'parentId'],
		'OCA\\Talk\\Chat\\ChatManager::getHistory' => ['chat', 'offset', 'limit', 'includeLastKnown'],
		'OCA\\Talk\\Service\\RoomService::createConversation' => ['type', 'name', 'owner'],
		'OCA\\Talk\\Service\\RoomService::createOneToOneConversation' => ['actor', 'targetUser'],
		'OCA\\Talk\\Room::getDisplayName' => ['userId', 'forceName'],
		'OCA\\Talk\\Room::getToken' => [],
		'OCA\\Talk\\Room::getType' => [],
		'OCA\\Talk\\Room::getReadOnly' => [],
		'OCA\\Talk\\Room::getLobbyState' => [],
		'OCA\\Talk\\Room::getLastActivity' => [],
		'OCA\\Talk\\Room::isFederatedConversation' => [],
		'OCA\\Talk\\Participant::getPermissions' => [],
		'OCA\\Talk\\Participant::getAttendee' => [],
		'OCA\\Talk\\Model\\Attendee::getParticipantType' => [],
		'OCA\\Talk\\Model\\Attendee::getUnreadMessages' => [],
		'OCA\\Talk\\Model\\Attendee::isArchived' => [],
		// Deck, resolved by class name in OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway.
		'OCA\\Deck\\Service\\AssignmentService::assignUser' => ['cardId', 'userId'],
		'OCA\\Deck\\Service\\AssignmentService::unassignUser' => ['cardId', 'userId'],
		'OCA\\Deck\\Service\\BoardService::create' => ['title', 'userId', 'color'],
		'OCA\\Deck\\Service\\BoardService::delete' => ['id'],
		'OCA\\Deck\\Service\\BoardService::deleteUndo' => ['id'],
		'OCA\\Deck\\Service\\BoardService::find' => ['boardId', 'fullDetails', 'allowDeleted'],
		'OCA\\Deck\\Service\\BoardService::findAll' => ['since', 'fullDetails', 'includeArchived'],
		'OCA\\Deck\\Service\\BoardService::setUserId' => ['userId'],
		'OCA\\Deck\\Service\\AttachmentService::delete' => ['cardId', 'attachmentId', 'type'],
		'OCA\\Deck\\Sharing\\DeckShareProvider::getSharedWithByType' => ['cardId', 'shareType', 'limit', 'offset'],
		'OCA\\Deck\\Sharing\\DeckShareProvider::getShareById' => ['id'],
		'OCA\\Deck\\Sharing\\DeckShareProvider::getSharesByPath' => ['path'],
		'OCA\\Deck\\Service\\CardService::create' => ['title', 'stackId', 'type', 'order', 'owner', 'description', 'duedate'],
		'OCA\\Deck\\Service\\CardService::delete' => ['id'],
		'OCA\\Deck\\Service\\CardService::find' => ['cardId'],
		'OCA\\Deck\\Service\\CardService::assignLabel' => ['cardId', 'labelId'],
		'OCA\\Deck\\Service\\CardService::removeLabel' => ['cardId', 'labelId'],
		'OCA\\Deck\\Service\\CardService::reorder' => ['id', 'stackId', 'order'],
		'OCA\\Deck\\Service\\CardService::update' => ['id', 'title', 'stackId', 'type', 'owner', 'description', 'order', 'duedate', 'deletedAt', 'archived', 'done'],
		'OCA\\Deck\\Service\\PermissionService::checkPermission' => ['mapper', 'id', 'permission', 'userId', 'allowDeletedCard'],
		'OCA\\Deck\\Service\\PermissionService::getPermissions' => ['boardId', 'userId'],
		'OCA\\Deck\\Service\\StackService::create' => ['title', 'boardId', 'order'],
		'OCA\\Deck\\Service\\StackService::delete' => ['id'],
		'OCA\\Deck\\Service\\StackService::findAll' => ['boardId'],
		'OCA\\Deck\\Service\\StackService::update' => ['id', 'title', 'boardId', 'order', 'deletedAt'],
		'OCA\\Deck\\Db\\StackMapper::findAll' => ['boardId'],
		'OCA\\Deck\\Db\\StackMapper::findBoardId' => ['id'],
		'OCA\\Deck\\Db\\StackMapper::findDeleted' => ['boardId'],
		'OCA\\Deck\\Db\\CardMapper::find' => ['id', 'enhance'],
		'OCA\\Deck\\Db\\CardMapper::findAll' => ['stackId', 'limit', 'offset'],
		'OCA\\Deck\\Db\\CardMapper::findAllArchived' => ['stackId'],
		'OCA\\Deck\\Db\\CardMapper::findBoardId' => ['id'],
		'OCA\\Deck\\Db\\BoardMapper::findAllByOwner' => ['userId'],
		'OCA\\Deck\\Db\\AssignmentMapper::findIn' => ['cardIds'],
		// Mail, resolved by class name in OCA\Mcp\Modules\Mail\Integration\MailServices and held untyped by MailGateway.
		'OCA\\Mail\\Service\\AccountService::findByUserId' => ['currentUserId'],
		'OCA\\Mail\\Service\\AccountService::find' => ['userId', 'id'],
		'OCA\\Mail\\Db\\MailboxMapper::findAll' => ['account'],
		'OCA\\Mail\\Account::getId' => [],
		'OCA\\Mail\\Account::getName' => [],
		'OCA\\Mail\\Account::getEmail' => [],
		'OCA\\Mail\\Account::getUserId' => [],
		// Mail messages, resolved by class name in OCA\Mcp\Modules\Mail\Integration\MailServices and held untyped by the message tools.
		'OCA\\Mail\\Db\\MessageMapper::findIdsByQuery' => ['mailbox', 'query', 'sortOrder', 'limit', 'uids'],
		'OCA\\Mail\\Db\\MessageMapper::findByIds' => ['userId', 'ids', 'sortOrder'],
		'OCA\\Mail\\Service\\MailManager::getMessage' => ['uid', 'id'],
		'OCA\\Mail\\Service\\MailManager::getMailbox' => ['uid', 'id'],
		'OCA\\Mail\\Service\\MailManager::getThread' => ['account', 'threadRootId'],
		'OCA\\Mail\\Service\\MailManager::getImapMessage' => ['client', 'account', 'mailbox', 'uid', 'loadBody'],
		'OCA\\Mail\\IMAP\\IMAPClientFactory::getClient' => ['account'],
		'OCA\\Mail\\IMAP\\Search\\Provider::findMatches' => ['account', 'mailbox', 'searchQuery'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::addFrom' => ['from'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::addTo' => ['to'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::addCc' => ['cc'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::addSubject' => ['subject'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::addBody' => ['body'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::setStart' => ['start'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::setEnd' => ['end'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::setCursor' => ['cursor'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::setHasAttachments' => ['hasAttachments'],
		'OCA\\Mail\\Service\\Search\\SearchQuery::addFlag' => ['flag'],
		'OCA\\Mail\\Service\\Search\\Flag::is' => ['flag'],
		'OCA\\Mail\\Service\\Search\\Flag::not' => ['flag'],
		'OCA\\Mail\\Db\\Mailbox::getAccountId' => [],
		'OCA\\Mail\\Db\\Mailbox::isCached' => [],
		'OCA\\Mail\\Db\\Mailbox::hasLocks' => ['now'],
		'OCA\\Mail\\Db\\Mailbox::getSyncNewToken' => [],
		'OCA\\Mail\\Db\\Mailbox::getSyncChangedToken' => [],
		'OCA\\Mail\\Db\\Mailbox::getSyncVanishedToken' => [],
		'OCA\\Mail\\Db\\Message::getSentAt' => [],
		'OCA\\Mail\\Db\\Message::getMailboxId' => [],
		'OCA\\Mail\\Db\\Message::getUid' => [],
		'OCA\\Mail\\Db\\Message::getThreadRootId' => [],
		'OCA\\Mail\\Db\\Message::getFrom' => [],
		'OCA\\Mail\\Db\\Message::getBcc' => [],
		'OCA\\Mail\\AddressList::iterate' => [],
		'OCA\\Mail\\Model\\IMAPMessage::getPlainBody' => [],
		'OCA\\Mail\\Model\\IMAPMessage::getHtmlBody' => ['id'],
		'OCA\\Mail\\Model\\IMAPMessage::getReplyTo' => [],
		// One attachment, read by OCA\Mcp\Modules\Mail\Integration\MailAttachmentReader on the client it already checked.
		'OCA\\Mail\\IMAP\\MessageMapper::getAttachment' => ['client', 'mailbox', 'messageUid', 'attachmentId', 'userId'],
		'OCA\\Mail\\Attachment::getContent' => [],
		// One flag change, by OCA\Mcp\Modules\Mail\Integration\MailFlagStore on the client it already checked, then the Mail app's
		// own event (the shape of the pinned releases; Mail 5.11.0 on takes the message: mail-flag-event.json).
		'OCA\\Mail\\IMAP\\MessageMapper::addFlag' => ['client', 'mailbox', 'uids', 'flag'],
		'OCA\\Mail\\IMAP\\MessageMapper::removeFlag' => ['client', 'mailbox', 'uids', 'flag'],
		'OCA\\Mail\\Events\\MessageFlaggedEvent::__construct' => ['account', 'mailbox', 'uid', 'flag', 'set'],
		// One move, by OCA\Mcp\Modules\Mail\Integration\MailMoveStore on the client it already checked, then the Mail app's own
		// event for the source UID (the same shape in every pinned release).
		'OCA\\Mail\\IMAP\\MessageMapper::move' => ['client', 'sourceFolderId', 'messageId', 'destFolderId'],
		'OCA\\Mail\\Events\\MessageDeletedEvent::__construct' => ['account', 'mailbox', 'messageId'],
		'OCA\\Mail\\Account::getMailAccount' => [],
		// The account settings OCA\Mcp\Modules\Mail\Moving\MailMoveDestination reads (Entity fields served by magic getters).
		'OCA\\Mail\\Db\\MailAccount::getTrashMailboxId' => [],
		'OCA\\Mail\\Db\\MailAccount::getJunkMailboxId' => [],
		'OCA\\Mail\\Db\\MailAccount::getTrashRetentionDays' => [],
		// One new draft, saved by the Mail app (OCA\Mcp\Modules\Mail\Integration\MailDraftStore): the Drafts folder of the account,
		// the message built as the Mail app builds a request (plain text, no attachment) and the save without a previous
		// draft, which appends to Drafts with Horde's null transport and never reaches SMTP or the Outbox.
		'OCA\\Mail\\Db\\MailAccount::getDraftsMailboxId' => [],
		'OCA\\Mail\\AddressList::parse' => ['str'],
		'OCA\\Mail\\Model\\NewMessageData::__construct' => ['account', 'to', 'cc', 'bcc', 'subject', 'body', 'attachments', 'isHtml'],
		'OCA\\Mail\\Service\\MailTransmission::saveDraft' => ['message', 'previousDraft'],
		// One message sent the way the Mail app's interface sends it (OCA\Mcp\Modules\Mail\Integration\MailSendOutbox): the Sent folder
		// its send chain needs, the sender, an outgoing local message (Entity fields set by magic setters, no send time),
		// saved with its recipients and no attachment, then sent through the chain, whose status and built message say
		// what happened (mail-send-path.json records the chain and the statuses from the pinned sources).
		'OCA\\Mail\\Db\\MailAccount::getSentMailboxId' => [],
		'OCA\\Mail\\Account::getEMailAddress' => [],
		'OCA\\Mail\\Db\\LocalMessage::setType' => [],
		'OCA\\Mail\\Db\\LocalMessage::setAccountId' => [],
		'OCA\\Mail\\Db\\LocalMessage::setSubject' => [],
		'OCA\\Mail\\Db\\LocalMessage::setBodyPlain' => [],
		'OCA\\Mail\\Db\\LocalMessage::setBodyHtml' => [],
		'OCA\\Mail\\Db\\LocalMessage::setHtml' => [],
		'OCA\\Mail\\Db\\LocalMessage::setSendAt' => [],
		'OCA\\Mail\\Db\\LocalMessage::getStatus' => [],
		'OCA\\Mail\\Db\\LocalMessage::getRaw' => [],
		'OCA\\Mail\\Service\\OutboxService::saveMessage' => ['account', 'message', 'to', 'cc', 'bcc', 'attachments'],
		'OCA\\Mail\\Service\\OutboxService::sendMessage' => ['message', 'account'],
		// DAV, built or resolved by class name.
		'OCA\\DAV\\CalDAV\\Auth\\CustomPrincipalPlugin::setCurrentPrincipal' => ['currentPrincipal'],
		'OCA\\DAV\\AppInfo\\PluginManager::__construct' => ['container', 'appManager'],
		'OCA\\DAV\\CalDAV\\EmbeddedCalDavServer::__construct' => ['public'],
		'OCA\\DAV\\CalDAV\\EmbeddedCalDavServer::getServer' => [],
		'OCA\\DAV\\CalDAV\\InvitationResponse\\InvitationResponseServer::__construct' => ['public'],
		'OCA\\DAV\\CalDAV\\InvitationResponse\\InvitationResponseServer::getServer' => [],
		'OCA\\DAV\\CalDAV\\Schedule\\Plugin::beforeUnbind' => ['path'],
		// Password events, built with arguments by position; 35 marks the password #[SensitiveParameter].
		'OCP\\Security\\Events\\GenerateSecurePasswordEvent::__construct' => ['context'],
		'OCP\\Security\\Events\\ValidatePasswordPolicyEvent::__construct' => ['password', 'context'],
		// Files versions and metadata, resolved by class name.
		'OCA\\Files_Versions\\Versions\\IVersionManager::getVersionsForFile' => ['user', 'file'],
		'OCA\\Files_Versions\\Versions\\IVersionManager::read' => ['version'],
		'OCA\\Files_Versions\\Versions\\IVersionManager::rollback' => ['version'],
		'OCP\\FilesMetadata\\IFilesMetadataManager::getMetadata' => ['fileId', 'generate'],
		'OCP\\FilesMetadata\\Model\\IFilesMetadata::hasKey' => ['needle'],
		'OCP\\FilesMetadata\\Model\\IFilesMetadata::getArray' => ['key'],
		'OCP\\FilesMetadata\\Model\\IFilesMetadata::getString' => ['key'],
		// The Nextcloud Tables app, kept with the Tables module's contract.
		...Tables\TablesApiUsage::POSITIONAL,
		...Forms\FormsCreationCoreApiUsage::POSITIONAL,
		...Forms\FormsApiUsage::POSITIONAL,
		...Polls\PollsApiUsage::POSITIONAL,
	];

	/**
	 * Parameters after the ones {@see self::POSITIONAL} passes, reviewed one by one: each is left to its default, and
	 * the reason says why that default is what the app wants. A trailing parameter missing here fails the contract,
	 * optional or not, because a default can still change the outcome: Deck 1.18 appended `startdate` and `color` to
	 * `CardService::update()` and clears both when they are left out.
	 *
	 * @var array<string, array<string, string>> "Class::method" => parameter => why its default is right
	 */
	public const REVIEWED_TRAILING = [
		'OCP\\IGroup::count' => ['search' => '"": estimate the entire group; false/zero/subcounts do not replace post-load limits'],
		'OCA\\Deck\\Db\\AclMapper::findAll' => [
			'limit' => 'null: complete authorized board ACLs, bounded after loading',
			'offset' => 'null: every row, never a partial page',
		],
		'OCA\\Circles\\Model\\Circle::getInheritedMembers' => [
			'remote' => 'false: native local inheritance; any returned effective non-local identity refuses',
		],
		'OCA\\Talk\\Manager::getRoomForUserByToken' => [
			'sessionId' => 'null: the module reads no session, it never joins the room',
			'includeLastMessage' => 'false: the last message is not part of what the module answers',
			'isSIPBridgeRequest' => 'false: the caller is a user, not the SIP bridge',
		],
		'OCA\\Talk\\Manager::getRoomsForUser' => [
			'sessionIds' => '[]: no session is joined',
			'includeLastMessage' => 'false: the listing reads the last activity, not the last message',
		],
		'OCA\\Talk\\Service\\ParticipantService::ensureOneToOneRoomIsFilled' => [
			'enforceUserId' => 'null: every member named by the one-to-one room is restored, as ChatController does before sending (sendMessage and shareObjectToChat)',
		],
		'OCA\\Talk\\Service\\ParticipantService::addUsers' => [
			'bansAlreadyChecked' => 'false: Talk checks the bans itself',
		],
		'OCA\\Talk\\Chat\\ChatManager::sendMessage' => [
			'referenceId' => "'': the module sends no client reference id",
			'silent' => 'false: a message the user confirmed notifies like any other',
			'rateLimitGuestMentions' => 'true: Talk keeps its rate limit',
			'threadId' => '0 (Talk 22+): the message goes to the main conversation',
			'threadTitle' => "'' (Talk 23+): no thread is created",
			'fromScheduledMessage' => 'false (Talk 23+): the message is sent now',
			'verb' => 'VERB_MESSAGE (Talk 24+): an ordinary message, not a private reply',
			'extraMetaData' => '[] (Talk 24+): no extra metadata',
		],
		'OCA\\Talk\\Chat\\ChatManager::addSystemMessage' => [
			'referenceId' => 'null: no client reference id',
			'replyTo' => 'null: a system message replies to nothing',
			'shouldSkipLastMessageUpdate' => 'false: the system message is the last activity, as in Talk',
			'silent' => 'false: notifies as Talk does',
			'threadId' => '0 (Talk 22+): main conversation',
		],
		'OCA\\Talk\\Chat\\ChatManager::getHistory' => [
			'threadId' => '0 (Talk 22+): the history of the whole conversation',
		],
		'OCA\\Talk\\Service\\RoomService::createConversation' => [
			'objectType' => "'': a plain group conversation, attached to no object",
			'objectId' => "'': same",
			'password' => "'': only public rooms take a password; the module creates group rooms",
			'readOnly' => 'READ_WRITE: members can write',
			'listable' => 'LISTABLE_NONE: not listed, as the Talk UI creates a group room',
			'messageExpiration' => '0: messages do not expire',
			'lobbyState' => 'LOBBY_NONE: no lobby',
			'lobbyTimer' => 'null: no lobby timer',
			'sipEnabled' => 'SIP_DISABLED: no dial-in',
			'permissions' => 'PERMISSIONS_DEFAULT: Talk\'s default permissions',
			'recordingConsent' => 'CONSENT_REQUIRED_NO: Talk\'s default',
			'mentionPermissions' => 'MENTION_PERMISSIONS_EVERYONE: Talk\'s default',
			'description' => "'': no description",
			'emoji' => 'null: no avatar emoji',
			'avatarColor' => 'null: no avatar colour',
			'attributes' => 'RoomAttributes::NONE (Talk 24+): a plain room',
			'allowInternalTypes' => 'true: Talk\'s default; the module only asks for the group type',
		],
		'OCA\\Talk\\Room::getLobbyState' => [
			'validateTime' => 'true (up to Talk 23): the expired timer opens the lobby, what Talk 24 does in RoomService::validateLobbyTimer()',
		],
		'OCA\\Deck\\Service\\AssignmentService::assignUser' => [
			'type' => 'TYPE_USER: the module assigns users only',
		],
		'OCA\\Deck\\Service\\AssignmentService::unassignUser' => [
			'type' => '0, the user type: the module unassigns users only',
		],
		'OCA\\Deck\\Sharing\\DeckShareProvider::getShareById' => [
			'recipientId' => 'null: resolve the parent association only after the module checks READ on its source card',
		],
		'OCA\\Deck\\Service\\CardService::create' => [
			'startdate' => 'null (Deck 1.18+): a new card has no start date',
			'color' => 'null (Deck 1.18+): a new card has no colour',
		],
		'OCA\\Deck\\Service\\CardService::update' => [
			'startdate' => 'not left to its default: DeckServiceGateway::fieldsAfterDone() sends the current value back (Deck 1.18+ clears it otherwise)',
			'color' => 'not left to its default: DeckServiceGateway::fieldsAfterDone() sends the current value back, typed per release',
		],
		'OCA\\Deck\\Service\\PermissionService::checkPermission' => [
			'allowDeletedBoard' => 'false (Deck 1.17+): a deleted board refuses, as the module wants',
		],
		'OCA\\Deck\\Service\\PermissionService::getPermissions' => [
			'allowDeleted' => 'false (Deck 1.17+): no permission on a deleted board',
		],
		'OCA\\Deck\\Service\\StackService::findAll' => [
			'since' => '-1: no change-tracking filter',
		],
		'OCA\\Deck\\Db\\StackMapper::findAll' => [
			'limit' => 'null: every stack of the board',
			'offset' => '0: from the first one',
		],
		'OCA\\Deck\\Db\\StackMapper::findDeleted' => [
			'limit' => 'null: every deleted stack',
			'offset' => '0: from the first one',
		],
		'OCA\\Deck\\Db\\CardMapper::findAll' => [
			'since' => '-1: no change-tracking filter',
		],
		'OCA\\Deck\\Db\\CardMapper::findAllArchived' => [
			'limit' => 'null: every archived card of the stack',
			'offset' => 'null: from the first one',
		],
		'OCA\\Deck\\Db\\BoardMapper::findAllByOwner' => [
			'limit' => 'null: every board the user owns',
			'offset' => 'null: from the first one',
		],
		'OCA\\Mail\\Db\\MessageMapper::findByIds' => [
			'orderBy' => '"sent_at" (Mail 5.7 on): the order of the folder query; the pager sorts again by date and id',
		],
		'OCA\\Mail\\IMAP\\IMAPClientFactory::getClient' => [
			'useCache' => 'true: the Mail app\'s own client with its own cache, as its controllers use it',
		],
		'OCA\\Mail\\Model\\NewMessageData::__construct' => [
			'isMdnRequested' => 'false: a draft asks for no read receipt; the Mail app\'s draft save ignores it anyway',
			'smimeCertificateId' => 'null: no S/MIME certificate; the draft save builds a plain message',
			'smimeSign' => 'false: the draft is not signed',
			'smimeEncrypt' => 'false: the draft is not encrypted',
		],
		// The Nextcloud Tables app, kept with the Tables module's contract.
		...Tables\TablesApiUsage::REVIEWED_TRAILING,
		...Forms\FormsApiUsage::REVIEWED_TRAILING,
	];

	/**
	 * Parameters of {@see self::POSITIONAL} and {@see self::REVIEWED_TRAILING} whose declared type differs between
	 * covered majors, with how the app copes. An untyped parameter (Deck up to 1.16) accepts anything, so only
	 * releases that declare a type are compared.
	 *
	 * @var array<string, array<string, string>> "Class::method" => parameter => how the app handles each type
	 */
	public const REVIEWED_TYPE_CHANGES = [
		'OCA\\Deck\\Service\\CardService::update' => [
			'color' => '?string in Deck 1.18, ?OptionalNullableValue in 1.19: DeckServiceGateway::fieldsAfterDone() reads the type and wraps the colour when asked',
		],
	];

	/**
	 * Horde IMAP and MIME classes the Mail message tools call, from the vendor tree of the Mail app, with the methods
	 * (and the parameters passed, in order), constants and documented magic properties used. They are not Nextcloud's
	 * nor the Mail app's own classes, so the main fixtures do not carry them: generate-mail-horde.php writes their
	 * surface from the same pinned Mail releases into mail-horde-surfaces.json, and MailHordeSurfaceContractTest
	 * checks this list against it per major. Only {@see \OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight},
	 * {@see \OCA\Mcp\Modules\Mail\Integration\MailBodyReader}, {@see \OCA\Mcp\Modules\Mail\Reading\MailAttachmentPreflight} and
	 * {@see \OCA\Mcp\Modules\Mail\Integration\MailAttachmentReader}, {@see \OCA\Mcp\Modules\Mail\Integration\MailFlagStore} and
	 * {@see \OCA\Mcp\Modules\Mail\Integration\MailMoveStore} use them: the STATUS and the metadata FETCH before a body or an
	 * attachment is read, a flag is stored or a message is moved, the read-write selection of a flag change or a move, the
	 * capabilities {@see \OCA\Mcp\Modules\Mail\Moving\MailMoveCapability} checks before a move (MOVE or UIDPLUS), and the
	 * message structure (Horde_Mime_Part) the attachment preflight walks.
	 * The same fixture records the format of the sync tokens the Mail app stores per folder (written by
	 * Horde_Imap_Client_Base::getSyncToken(), `V` for the UIDVALIDITY), which {@see \OCA\Mcp\Modules\Mail\Locating\MailCacheGeneration} reads.
	 *
	 * @var array<string, array{methods?:array<string, list<string>>, trailing?:array<string, string>, constants?:list<string>, properties?:list<string>}>
	 */
	public const HORDE = [
		'Horde_Imap_Client' => ['constants' => ['STATUS_UIDVALIDITY', 'OPEN_READWRITE']],
		'Horde_Imap_Client_Socket' => [
			'methods' => ['status' => ['mailbox', 'flags'], 'fetch' => ['mailbox', 'query', 'options'], 'logout' => [], 'openMailbox' => ['mailbox', 'mode']],
			// The capabilities of the connection, which OCA\Mcp\Modules\Mail\Moving\MailMoveCapability reads before a move.
			'properties' => ['capability'],
			'trailing' => ['opts' => 'status(): no option; the UIDVALIDITY is asked for alone'],
		],
		'Horde_Imap_Client_Data_Capability' => [
			'methods' => ['query' => ['capability']],
			'trailing' => ['parameter' => 'query(): null, only whether the server has MOVE or UIDPLUS at all'],
		],
		'Horde_Imap_Client_Fetch_Query' => ['methods' => ['envelope' => [], 'size' => [], 'structure' => [], 'flags' => []]],
		'Horde_Imap_Client_Ids' => [
			'methods' => ['__construct' => ['ids']],
			'trailing' => ['sequence' => 'false: the ids are UIDs, as the cache stores them, never sequence numbers'],
		],
		'Horde_Imap_Client_Fetch_Results' => ['methods' => ['first' => []]],
		'Horde_Imap_Client_Data_Fetch' => ['methods' => ['getSize' => [], 'getEnvelope' => [], 'getStructure' => [], 'getFlags' => []]],
		'Horde_Imap_Client_Data_Envelope' => ['properties' => ['message_id']],
		'Horde_Mime_Part' => [
			'methods' => ['getType' => [], 'getPrimaryType' => [], 'getName' => [], 'getCharset' => [], 'isAttachment' => [], 'getPart' => ['id']],
			'trailing' => [
				'charset' => 'getType(): false, the bare MIME type without its charset parameter',
				'default' => 'getName(): false, no default name; a part without one has none',
			],
		],
	];

	/** Constants the app reads by name from classes it holds untyped. */
	public const CONSTANTS = [
		'OCA\\Circles\\Model\\Member' => ['LEVEL_MEMBER', 'TYPE_USER', 'TYPE_GROUP', 'TYPE_CIRCLE'],
		'OCA\\Talk\\Model\\Attendee' => ['ACTOR_USERS', 'PERMISSIONS_CHAT', 'PERMISSIONS_LOBBY_IGNORE'],
		'OCA\\Talk\\Room' => ['READ_ONLY', 'TYPE_CHANGELOG', 'TYPE_GROUP'],
		'OCA\\Talk\\Webinary' => ['LOBBY_NONE'],
		'OCA\\DAV\\CalDAV\\CalDavBackend' => ['CALENDAR_TYPE_CALENDAR'],
		'OCA\\Mail\\Service\\Search\\Flag' => ['SEEN', 'FLAGGED', 'DELETED'],
		// The Nextcloud Tables app, kept with the Tables module's contract.
		...Tables\TablesApiUsage::CONSTANTS,
		...Forms\FormsApiUsage::CONSTANTS,
		...Polls\PollsApiUsage::CONSTANTS,
	];

	/**
	 * APIs a declared release does not have, the majors that lack them, the fallback those majors use instead and the
	 * class that chooses. Explicit omittedField declarations use VersionedOmissionContract instead of a fabricated fallback.
	 *
	 * A method name the scanner also sees on another object lists those files in `elsewhere`, with the call it really is.
	 *
	 * @var array<string, array{missing: list<string>, fallback?: string, by: string, omittedField?: string, pins?: array<string,string>, elsewhere?: array<string, string>}>
	 */
	public const VERSION_GATED = [
		...Polls\PollsContentApiUsage::VERSION_GATED,
		...Polls\PollsCreateApiUsage::VERSION_GATED,
		// Mail 5.5 made the folder query group by thread by default; 5.0.0 (Nextcloud 31 and 32 pins) has neither the
		// grouping nor the switch, so its plain MessageMapper::findIdsByQuery() already lists every message.
		'OCA\\Mail\\Service\\Search\\SearchQuery::setThreaded' => [
			'missing' => ['31', '32'],
			'fallback' => 'OCA\\Mail\\Db\\MessageMapper::findIdsByQuery',
			'by' => \OCA\Mcp\Modules\Mail\Reading\MailSearchCriteria::class,
		],
		'OCA\\DAV\\CalDAV\\EmbeddedCalDavServer' => [
			'missing' => ['31'],
			'fallback' => 'OCA\\DAV\\CalDAV\\InvitationResponse\\InvitationResponseServer',
			'by' => \OCA\Mcp\Modules\Calendar\Integration\EmbeddedCalDavServerFactory::class,
		],
		'OCP\\App\\IAppManager::isEnabledForAnyone' => [
			'missing' => ['31'],
			'fallback' => 'OCP\\App\\IAppManager::isInstalled',
			'by' => \OCA\Mcp\Platform\Compat\AppEnablement::class,
		],
		'OCA\\Deck\\Db\\CardMapper::findAllForStacks' => [
			'missing' => ['31', '32'],
			'fallback' => 'OCA\\Deck\\Db\\CardMapper::findAll',
			'by' => \OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway::class,
		],
		// Deck 1.18 (Nextcloud 34) gave cards a start date and a colour, and CardService::update() clears both when they
		// are left out. Up to 1.17 the update ends at `done`, so there is nothing to send back.
		// Dependencies are native only since Deck 1.18. The boundary returns the original Card on older entities.
		'OCA\\Deck\\Db\\CardMapper::findDependenciesForCards' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Db\\Card::getId',
			'by' => \OCA\Mcp\Modules\Deck\Integration\DeckDependencyGateway::class,
		],
		'OCA\\Deck\\Service\\CardService::assignDependentCard' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Db\\Card::getId',
			'by' => \OCA\Mcp\Modules\Deck\Integration\DeckDependencyGateway::class,
		],
		'OCA\\Deck\\Service\\CardService::removeDependentCard' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Db\\Card::getId',
			'by' => \OCA\Mcp\Modules\Deck\Integration\DeckDependencyGateway::class,
		],
		'OCA\\Deck\\Db\\Card::getDependentCards' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Db\\Card::getId',
			'by' => \OCA\Mcp\Modules\Deck\Integration\CardReferenceFilter::class,
		],
		'OCA\\Deck\\Db\\Card::setDependentCards' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Db\\Card::getId',
			'by' => \OCA\Mcp\Modules\Deck\Integration\CardReferenceFilter::class,
		],
		'OCA\\Deck\\Db\\Card::getStartdate' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Service\\CardService::update',
			'by' => \OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway::class,
		],
		'OCA\\Deck\\Db\\Card::getColor' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Deck\\Service\\CardService::update',
			'by' => \OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway::class,
			'elsewhere' => [
				'Tools/Deck/CardFormatter.php' => 'OCA\\Deck\\Db\\Board::getColor',
				'Tools/Deck/Handler/ListLabelsHandler.php' => 'OCA\\Deck\\Db\\Label::getColor',
				'Modules/Deck/Integration/DeckLabelGateway.php' => 'OCA\\Deck\\Db\\Label::getColor',
			],
		],
		// Up to Talk 23 Room::getLobbyState() opens a lobby whose timer has passed by itself.
		'OCA\\Talk\\Service\\RoomService::validateLobbyTimer' => [
			'missing' => ['31', '32', '33'],
			'fallback' => 'OCA\\Talk\\Room::getLobbyState',
			'by' => \OCA\Mcp\Tools\Talk\ConversationResolver::class,
		],
		// The Nextcloud Tables app, kept with the Tables module's contract.
		...Tables\TablesApiUsage::VERSION_GATED,
		...Forms\FormsCreationCoreApiUsage::VERSION_GATED,
	];

	/**
	 * Method names the scanner pairs with a class that never receives them: the call is made on another object.
	 *
	 * @var array<string, string> "Class::method" => the call it really is
	 */
	public const NAME_CLASHES = [
		'OCA\\Deck\\Service\\PermissionService::getUserId' => 'OCA\\Circles\\Model\\Member::getUserId, in Tools/Deck/DeckRecipientDirectory',
		'OCP\\Files\\Node::getLastActivity' => 'OCA\\Talk\\Room::getLastActivity, in Tools/Talk/ConversationReader',
		'OCP\\Files\\File::getLastActivity' => 'OCA\\Talk\\Room::getLastActivity, in Tools/Talk/ConversationReader',
		'OCP\\Files\\Folder::getLastActivity' => 'OCA\\Talk\\Room::getLastActivity, in Tools/Talk/ConversationReader',
		'OCP\\Files\\FileInfo::getLastActivity' => 'OCA\\Talk\\Room::getLastActivity, in Tools/Talk/ConversationReader',
		'OCP\\Files\\IRootFolder::getLastActivity' => 'OCA\\Talk\\Room::getLastActivity, in Tools/Talk/ConversationReader',
		'OCP\\Share\\IShare::getParent' => 'OCP\\Files\\Node::getParent, in Tools/Notes and Service/VisibilityGuard',
		'OCA\\DAV\\CalDAV\\CalDavBackend::unshare' => 'FilesPlanRenderer::unshare(), the app\'s own static method',
		'OCA\\DAV\\CardDAV\\PhotoCache::setLogger' => 'Sabre\\DAV\\Server::setLogger, in Tools/Contacts/EmbeddedCardDavServer',
		'OCA\\Talk\\Chat\\ChatManager::setLogger' => 'Sabre\\DAV\\Server::setLogger, in Tools/Contacts/EmbeddedCardDavServer',
		'OCA\\Talk\\Service\\RoomService::setLogger' => 'Sabre\\DAV\\Server::setLogger, in Tools/Contacts/EmbeddedCardDavServer',
		'OCA\\Deck\\Db\\Acl::getToken' => 'OCP\\Share\\IShare::getToken and OCA\\Talk\\Room::getToken, in Tools/Files/Sharing and Tools/Talk',
		'OCA\\Deck\\Db\\Acl::getCreatedAt' => 'OCA\\Deck\\Db\\Card::getCreatedAt, in Tools/Deck/DeckServiceGateway',
		'OCP\\Files\\IRootFolder::removeListener' => 'Sabre\\DAV\\Server::removeListener, in Tools/Calendar/EmbeddedDavDispatcher',
		'OCP\\DB\\QueryBuilder\\IQueryBuilder::execute' => 'the app\'s own tools, in Tools/Calendar/CalendarModule and Service/Calendar/CalendarSelftestService',
		'OCP\\BackgroundJob\\TimedJob::execute' => 'the app\'s own tools, in Tools/Calendar/CalendarModule and Service/Calendar/CalendarSelftestService',
		'OCA\\Deck\\Db\\StackMapper::findByIds' => 'OCA\\Mail\\Db\\MessageMapper::findByIds, in Tools/Mail/MailMessageGateway',
		'OCA\\Talk\\Service\\RoomService::setHasAttachments' => 'OCA\\Mail\\Service\\Search\\SearchQuery::setHasAttachments, in Tools/Mail/MailSearchCriteria',
		// The Nextcloud Tables app, kept with the Tables module's contract.
		...Tables\TablesApiUsage::NAME_CLASHES,
		...Forms\FormsCreationCoreApiUsage::NAME_CLASHES,
		...Polls\PollsApiUsage::NAME_CLASHES,
	];
}
