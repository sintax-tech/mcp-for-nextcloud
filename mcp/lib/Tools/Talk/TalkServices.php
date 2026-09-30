<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Single place that knows the optional Nextcloud Talk classes by name, so the module compiles and the suite
 * passes with the spreed app absent. Nothing else in this module may reference an OCA\Talk class: handlers
 * receive plain objects and are tested with doubles.
 */
class TalkServices {
    public const APP_ID = 'spreed';

    private const MANAGER_CLASS = 'OCA\\Talk\\Manager';
    private const CHAT_MANAGER_CLASS = 'OCA\\Talk\\Chat\\ChatManager';
    private const PARTICIPANT_SERVICE_CLASS = 'OCA\\Talk\\Service\\ParticipantService';
    private const ATTENDEE_CLASS = 'OCA\\Talk\\Model\\Attendee';
    private const ROOM_CLASS = 'OCA\\Talk\\Room';
    private const WEBINARY_CLASS = 'OCA\\Talk\\Webinary';

    /**
     * @param IAppManager $appManager app enablement, checked per user on every call
     * @param ContainerInterface $container resolves the Talk services lazily by class name
     * @param IUserManager|null $userManager resolves the UID to the IUser that IAppManager::isEnabledForUser() expects
     */
    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
        private ?IUserManager $userManager = null,
    ) {}

    /** Whether the optional spreed app is enabled for the user on this very call. */
    public function isEnabledFor(string $userId): bool {
        // isEnabledForUser() takes an IUser, never the UID string: passing the string breaks at runtime.
        $user = $this->userManager?->get($userId);
        if ($this->userManager !== null && $user === null) {
            return false;
        }
        return $this->appManager->isEnabledForUser(self::APP_ID, $user);
    }

    /**
     * OCA\Talk\Manager: rooms for a user and room resolution by token.
     *
     * @return object OCA\Talk\Manager
     * @throws TalkUnavailableException When spreed is disabled for the user or the class is not loaded
     */
    public function manager(string $userId): object {
        return $this->resolve(self::MANAGER_CLASS, $userId);
    }

    /**
     * OCA\Talk\Chat\ChatManager: chat history, parent comment and message sending.
     *
     * @return object OCA\Talk\Chat\ChatManager
     * @throws TalkUnavailableException When spreed is disabled for the user or the class is not loaded
     */
    public function chatManager(string $userId): object {
        return $this->resolve(self::CHAT_MANAGER_CLASS, $userId);
    }

    /**
     * OCA\Talk\Service\ParticipantService: participant lookup and permissions.
     *
     * @return object OCA\Talk\Service\ParticipantService
     * @throws TalkUnavailableException When spreed is disabled for the user or the class is not loaded
     */
    public function participantService(string $userId): object {
        return $this->resolve(self::PARTICIPANT_SERVICE_CLASS, $userId);
    }

    /**
     * Talk constants the permission checks depend on, read from the loaded classes instead of being copied here,
     * so a change of value upstream cannot silently loosen or break the ACL.
     *
     * @return array{chatPermission:int, lobbyIgnorePermission:int, readOnly:int, changelogType:int, lobbyNone:int, actorUsers:string}
     * @throws TalkUnavailableException When spreed is disabled for the user or a class is not loaded
     */
    public function conversationConstants(string $userId): array {
        if (!$this->isEnabledFor($userId)) {
            throw new TalkUnavailableException();
        }
        foreach ([self::ATTENDEE_CLASS, self::ROOM_CLASS, self::WEBINARY_CLASS] as $class) {
            if (!class_exists($class)) {
                throw new TalkUnavailableException();
            }
        }

        return [
            'chatPermission' => (int)constant(self::ATTENDEE_CLASS . '::PERMISSIONS_CHAT'),
            'lobbyIgnorePermission' => (int)constant(self::ATTENDEE_CLASS . '::PERMISSIONS_LOBBY_IGNORE'),
            'readOnly' => (int)constant(self::ROOM_CLASS . '::READ_ONLY'),
            'changelogType' => (int)constant(self::ROOM_CLASS . '::TYPE_CHANGELOG'),
            'lobbyNone' => (int)constant(self::WEBINARY_CLASS . '::LOBBY_NONE'),
            'actorUsers' => (string)constant(self::ATTENDEE_CLASS . '::ACTOR_USERS'),
        ];
    }

    /**
     * Resolves a Talk class by name only after the app is confirmed enabled and the class exists.
     *
     * @param string $class Fully qualified Talk class name, never a hard reference from the module
     * @param string $userId Authenticated user, because app enablement is per user
     * @return object The Talk service, untyped on purpose: the class may not exist in this installation
     * @throws TalkUnavailableException When spreed is disabled, the class is absent or the container returns no object
     */
    protected function resolve(string $class, string $userId): object {
        if (!$this->isEnabledFor($userId) || !class_exists($class)) {
            throw new TalkUnavailableException();
        }
        $service = $this->container->get($class);
        if (!is_object($service)) {
            throw new TalkUnavailableException();
        }
        return $service;
    }
}
