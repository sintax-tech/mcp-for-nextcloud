<?php
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
     * Nextcloud major, with the oldest Deck and Talk MCP supports on it ({@see \OCA\Mcp\Service\Compat\AppEnablement}).
     */
    public const SOURCES = [
        '31' => ['server' => '31.0.0', 'deck' => '1.15.0', 'spreed' => '21.1.4'],
        '32' => ['server' => '32.0.0', 'deck' => '1.16.0', 'spreed' => '22.0.0'],
        '33' => ['server' => '33.0.0', 'deck' => '1.17.0', 'spreed' => '23.0.0'],
        '34' => ['server' => '34.0.0', 'deck' => '1.18.0', 'spreed' => '24.0.0'],
        '35' => ['server' => '35.0.0', 'deck' => '1.19.0', 'spreed' => '25.0.0'],
    ];

    /**
     * Classes the fixtures carry although lib/ never names them: objects the app only receives (a Talk participant,
     * a metadata set, the log writer ILogFactory::get() returns) and the Nextcloud plugin whose listener
     * {@see \OCA\Mcp\Tools\Calendar\EmbeddedDavDispatcher} detaches.
     */
    public const EXTRA_CLASSES = [
        'OCA\\Talk\\Participant',
        'OCP\\FilesMetadata\\Model\\IFilesMetadata',
        'OCA\\DAV\\CalDAV\\Schedule\\Plugin',
        'OCP\\Log\\IWriter',
    ];

    /**
     * Calls whose arguments are positional and unchecked by any type, with the parameters the app fills in order.
     * Every declared release must have these names at these positions and nothing else required after them.
     *
     * @var array<string, list<string>>
     */
    public const POSITIONAL = [
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
        // Deck, resolved by class name in OCA\Mcp\Tools\Deck\DeckServiceGateway.
        'OCA\\Deck\\Service\\AssignmentService::assignUser' => ['cardId', 'userId'],
        'OCA\\Deck\\Service\\AssignmentService::unassignUser' => ['cardId', 'userId'],
        'OCA\\Deck\\Service\\BoardService::create' => ['title', 'userId', 'color'],
        'OCA\\Deck\\Service\\BoardService::delete' => ['id'],
        'OCA\\Deck\\Service\\BoardService::deleteUndo' => ['id'],
        'OCA\\Deck\\Service\\BoardService::find' => ['boardId', 'fullDetails', 'allowDeleted'],
        'OCA\\Deck\\Service\\BoardService::findAll' => ['since', 'fullDetails', 'includeArchived'],
        'OCA\\Deck\\Service\\BoardService::setUserId' => ['userId'],
        'OCA\\Deck\\Service\\CardService::create' => ['title', 'stackId', 'type', 'order', 'owner', 'description', 'duedate'],
        'OCA\\Deck\\Service\\CardService::delete' => ['id'],
        'OCA\\Deck\\Service\\CardService::find' => ['cardId'],
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
    ];

    /** Constants the app reads by name from classes it holds untyped. */
    public const CONSTANTS = [
        'OCA\\Talk\\Model\\Attendee' => ['ACTOR_USERS', 'PERMISSIONS_CHAT', 'PERMISSIONS_LOBBY_IGNORE'],
        'OCA\\Talk\\Room' => ['READ_ONLY', 'TYPE_CHANGELOG', 'TYPE_GROUP'],
        'OCA\\Talk\\Webinary' => ['LOBBY_NONE'],
        'OCA\\DAV\\CalDAV\\CalDavBackend' => ['CALENDAR_TYPE_CALENDAR'],
    ];

    /**
     * APIs a declared release does not have, the majors that lack them, the fallback those majors use instead and the
     * class that chooses. The test checks all three, so a gap can neither appear nor close unnoticed.
     *
     * A method name the scanner also sees on another object lists those files in `elsewhere`, with the call it really is.
     *
     * @var array<string, array{missing: list<string>, fallback: string, by: string, elsewhere?: array<string, string>}>
     */
    public const VERSION_GATED = [
        'OCA\\DAV\\CalDAV\\EmbeddedCalDavServer' => [
            'missing' => ['31'],
            'fallback' => 'OCA\\DAV\\CalDAV\\InvitationResponse\\InvitationResponseServer',
            'by' => \OCA\Mcp\Tools\Calendar\EmbeddedCalDavServerFactory::class,
        ],
        'OCP\\Config\\IUserConfig' => [
            'missing' => ['31'],
            'fallback' => 'OCP\\IConfig::getUsersForUserValue',
            'by' => \OCA\Mcp\Service\GrantPolicy::class,
        ],
        'OCP\\App\\IAppManager::isEnabledForAnyone' => [
            'missing' => ['31'],
            'fallback' => 'OCP\\App\\IAppManager::isInstalled',
            'by' => \OCA\Mcp\Service\Compat\AppEnablement::class,
        ],
        'OCA\\Deck\\Db\\CardMapper::findAllForStacks' => [
            'missing' => ['31', '32'],
            'fallback' => 'OCA\\Deck\\Db\\CardMapper::findAll',
            'by' => \OCA\Mcp\Tools\Deck\DeckServiceGateway::class,
        ],
        // Deck 1.18 (Nextcloud 34) gave cards a start date and a colour, and CardService::update() clears both when they
        // are left out. Up to 1.17 the update ends at `done`, so there is nothing to send back.
        'OCA\\Deck\\Db\\Card::getStartdate' => [
            'missing' => ['31', '32', '33'],
            'fallback' => 'OCA\\Deck\\Service\\CardService::update',
            'by' => \OCA\Mcp\Tools\Deck\DeckServiceGateway::class,
        ],
        'OCA\\Deck\\Db\\Card::getColor' => [
            'missing' => ['31', '32', '33'],
            'fallback' => 'OCA\\Deck\\Service\\CardService::update',
            'by' => \OCA\Mcp\Tools\Deck\DeckServiceGateway::class,
            'elsewhere' => ['Tools/Deck/CardFormatter.php' => 'OCA\\Deck\\Db\\Board::getColor'],
        ],
        // Up to Talk 23 Room::getLobbyState() opens a lobby whose timer has passed by itself.
        'OCA\\Talk\\Service\\RoomService::validateLobbyTimer' => [
            'missing' => ['31', '32', '33'],
            'fallback' => 'OCA\\Talk\\Room::getLobbyState',
            'by' => \OCA\Mcp\Tools\Talk\ConversationResolver::class,
        ],
    ];

    /**
     * Method names the scanner pairs with a class that never receives them: the call is made on another object.
     *
     * @var array<string, string> "Class::method" => the call it really is
     */
    public const NAME_CLASHES = [
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
    ];
}
