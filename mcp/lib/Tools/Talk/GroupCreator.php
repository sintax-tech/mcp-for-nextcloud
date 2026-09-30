<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates a group conversation through the product's own call, the way the Talk UI does when a user starts one.
 *
 * The room and the invitations are two steps of the product's API and the second one is not transactional with the
 * first: a room whose invitations fail stays in the caller's list, without the guests that did not get in. That is
 * the same behaviour the UI has, and the tool reports each of those guests by name instead of hiding the room behind
 * a compensating delete, because deleting a conversation is not something this tool may decide on its own.
 *
 * Every participant is resolved before anything is created, with the same rule the direct message uses: an account
 * the caller may not enumerate is refused, so the answer does not map who exists on the server.
 */
class GroupCreator {
    /** Longest group name this tool accepts; the product validates the name too, this only bounds the payload. */
    public const MAX_NAME_LENGTH = 64;

    /** Largest number of people one call may invite on top of the caller. */
    public const MAX_PARTICIPANTS = 50;

    public function __construct(
        private TalkServices $talkServices,
        private IUserManager $userManager,
        private UserConversationResolver $userConversations,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string $userId Authenticated user, the owner of the new conversation
     * @param string $name Name of the group, normalized exactly as it will be stored
     * @param list<string> $participantIds Accounts to invite, besides the caller
     * @return array{conversation_token:string, name:string, participants:list<array{id:string, displayName:string}>, invitations_failed:list<array{id:string, displayName:string, error:string}>}
     * @throws InvalidArgumentException When the name or the participant list is not usable
     * @throws ConversationAccessException When an account is out of reach or the product refuses the creation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function create(string $userId, string $name, array $participantIds): array {
        $title = self::normalizeName($name);
        if ($participantIds !== [] && count($participantIds) > self::MAX_PARTICIPANTS) {
            throw new InvalidArgumentException(sprintf(Messages::TOO_MANY_PARTICIPANTS, self::MAX_PARTICIPANTS));
        }
        // Resolved before anything is created: a group that already exists cannot be un-invited by this tool.
        $contacts = $this->userConversations->contacts($userId, $participantIds, true);

        $owner = $this->userManager->get($userId);
        if ($owner === null) {
            throw new ConversationAccessException(Messages::CONVERSATION_NOT_CREATED);
        }

        // Every service is resolved before the room exists: an unavailable Talk discovered after the creation
        // would hide a conversation that is already in the user's list.
        $constants = $this->talkServices->conversationConstants($userId);
        $roomService = $this->talkServices->roomService($userId);
        $participantService = $this->talkServices->participantService($userId);

        try {
            $room = $roomService
                ->createConversation($constants['groupType'], $title, $owner);
        } catch (TalkUnavailableException|ConversationAccessException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Nothing was created, so there is nothing half built to clean up and nothing to report but a refusal.
            throw new ConversationAccessException(Messages::CONVERSATION_NOT_CREATED, $e);
        }

        $result = [
            'conversation_token' => (string)$room->getToken(),
            'name' => $title,
            'participants' => [],
            'invitations_failed' => [],
        ];

        $invited = [];
        $failed = [];
        // One call per guest instead of one for the list: addUsers() is not transactional across its rows, so a
        // single call that throws halfway leaves no way to tell who got in. Per guest, the answer is exact.
        foreach ($contacts as $contact) {
            try {
                $participantService->addUsers($room, [[
                    'actorType' => $constants['actorUsers'],
                    'actorId' => $contact->id,
                    'displayName' => $contact->displayName,
                ]], $owner);
                $invited[] = $contact->describe();
            } catch (Throwable $e) {
                // The room exists from here on and this tool cannot undo it, so the guest is reported by name
                // instead of turning the whole call into a refusal that hides a conversation the user will find
                // in their own list. The product's own message stays in the log: it may name internals.
                $failed[] = $contact->describe() + ['error' => Messages::INVITATION_FAILED];
                $this->logger->warning('Talk created the group {token} but could not invite {guest}', [
                    'token' => $result['conversation_token'],
                    'guest' => $contact->id,
                    'exception' => $e,
                ]);
            }
        }

        $result['participants'] = $invited;
        $result['invitations_failed'] = $failed;

        return $result;
    }

    /**
     * The name as Talk will store it, the same way a message body is normalized before it is shown in a draft.
     *
     * @throws InvalidArgumentException When the name is blank or longer than self::MAX_NAME_LENGTH
     */
    public static function normalizeName(string $name): string {
        $title = trim($name);
        if ($title === '') {
            throw new InvalidArgumentException(Messages::INVALID_GROUP_NAME);
        }
        if (mb_strlen($title) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(Messages::GROUP_NAME_TOO_LONG);
        }

        return $title;
    }
}
