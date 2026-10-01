<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * files_share: creates a share of an own file or folder, or changes the one that already exists for the same recipient
 * (plan 0.10, decisions 5, 6 and 10).
 *
 * {@see self::plan()} and {@see self::apply()} run the same {@see self::prepare()}, so the confirmed call checks
 * everything again (invariant 1): grant of the type, own and visible node, the administrator's sharing rules, the
 * permission the user has on the node and the validity. The rules the core would refuse with an untranslated
 * exception are checked first through the public IShareManager getters, so the person reads why in their language;
 * whatever the core still refuses becomes a safe message, and only the exception class is logged.
 *
 * A public link (`with: "link"`) is refused here with a clear message: {@see self::prepareLink()} is the one place
 * the link ticket fills in. Nextcloud sends its own notification to whoever receives a new share; this class adds
 * none. Stateless: the user is a parameter of every call.
 */
final class ShareWriter {
    /** The plan creates a share. */
    public const CREATE = 'create';
    /** The plan changes the share the user already has for this recipient. */
    public const UPDATE = 'update';
    /** The share already is what the call asks for: the confirmed call writes nothing. */
    public const NONE = 'none';
    /** Longest note for the recipient, in characters, as the schema declares it. */
    public const NOTE_MAX = 500;
    /** Hour of the chosen day handed to the core, so a move to the server zone keeps the same date (within ±12 h). */
    private const EXPIRY_HOUR = '12:00:00';

    public function __construct(
        private ShareAccess $access,
        private ShareRecipientResolver $recipients,
        private ShareFormatter $formatter,
        private IShareManager $shareManager,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private UserTimezone $zones,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {}

    /**
     * The plan of files_share: what would be created or changed, before → after, and the warnings. Writes nothing.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param array{path:string, with:string, permission?:string, expires?:string, note?:string} $arguments validated arguments
     * @return array{action:string, path:string, isDir:bool, with:array{type:string, id:string, displayName:string}, shareId:string|null, before:array{permission:string, reshare:bool, expires:string|null, note:string}|null, after:array{permission:string, reshare:bool, expires:string|null, note:string|null}, expiresSource:string, notifies:bool, timezone:string, warnings:list<array{message:string}>, message:string}
     *   `expiresSource` says where `after.expires` comes from: requested, kept, default (the administrator's, applied by
     *   the core) or none
     * @throws ArgumentValidationException for an unknown recipient, a level outside view/edit or an unusable date
     * @throws ToolFailure for every other refusal, with a translated reason
     */
    public function plan(Folder $userFolder, string $uid, array $arguments): array {
        $change = $this->prepare($userFolder, $uid, $arguments);
        return [
            'action' => $change['action'],
            'path' => $change['path'],
            'isDir' => $change['folder'],
            'with' => $change['recipient']->toArray(),
            'shareId' => $change['existing']?->getFullId(),
            'before' => $change['before'],
            'after' => $change['after'],
            'expiresSource' => $change['expiresSource'],
            'notifies' => $change['action'] === self::CREATE,
            'timezone' => $change['timezone']->getName(),
            'warnings' => array_map(static fn (string $message): array => ['message' => $message], $change['warnings']),
            'message' => $change['action'] === self::NONE ? FilesMessages::planShareNothing() : CommonMessages::planNothingChanged(),
        ];
    }

    /**
     * Applies the plan after checking everything again: createShare, updateShare, or nothing when nothing changes.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param array{path:string, with:string, permission?:string, expires?:string, note?:string} $arguments validated arguments
     * @return array<string, mixed> the share as {@see ShareFormatter::item()} shows it, plus `action` and `changed`
     * @throws ArgumentValidationException as {@see self::plan()}
     * @throws ToolFailure as {@see self::plan()}, and with a translated reason when the core refuses
     */
    public function apply(Folder $userFolder, string $uid, array $arguments): array {
        $change = $this->prepare($userFolder, $uid, $arguments);
        $share = $change['existing'];
        if ($change['action'] !== self::NONE) {
            $share = $this->write($change, $uid);
        }
        return ['action' => $change['action'], 'changed' => $change['action'] !== self::NONE]
            + $this->formatter->item($share, $change['path'], $this->access->isRemovable($share, $uid));
    }

    /**
     * Everything the plan shows and the write needs, checked in the order the person would want the reason: who, may
     * I, which file, what the server allows, what I may give, until when. The node comes before any other refusal
     * that depends on it, so a hidden node always answers "not found".
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param array<string, mixed> $arguments validated arguments
     * @return array{action:string, node:Node, path:string, folder:bool, recipient:ShareRecipient, existing:IShare|null, bits:int, expires:\DateTime|null, note:string|null, before:array<string, mixed>|null, after:array<string, mixed>, expiresSource:string, timezone:\DateTimeZone, warnings:list<string>}
     * @throws ArgumentValidationException|ToolFailure for any refusal
     */
    private function prepare(Folder $userFolder, string $uid, array $arguments): array {
        $recipient = $this->recipients->resolve((string)$arguments['with'], 'with');
        $this->access->assertGranted($uid, $recipient->shareType());
        if ($recipient->kind === ShareRecipient::LINK) {
            return $this->prepareLink($userFolder, $uid, $arguments, $recipient);
        }
        $node = $this->access->ownNode($userFolder, $uid, (string)$arguments['path']);
        if ($recipient->kind === ShareRecipient::USER && $recipient->id === $uid) {
            throw new ToolFailure(FilesMessages::shareWithSelf());
        }
        $this->assertServerAllows($uid, $recipient);
        $folder = NodeAccess::isFolder($node);
        $level = (string)($arguments['permission'] ?? SharePermission::VIEW);
        $bits = SharePermission::bits($level, $folder);
        if (!$node->isShareable()) {
            throw new ToolFailure(FilesMessages::shareNodeNotShareable());
        }
        if (($bits & ~(int)$node->getPermissions()) !== 0) {
            throw new ToolFailure(FilesMessages::shareAboveOwnPermissions());
        }
        $zone = $this->zones->forUser($uid);
        $expires = isset($arguments['expires']) ? $this->expiry((string)$arguments['expires'], $zone) : null;
        $note = isset($arguments['note']) ? (string)$arguments['note'] : null;
        $existing = $this->access->findExisting($uid, $node, $recipient);

        $before = $existing === null ? null : [
            'permission' => SharePermission::classify((int)$existing->getPermissions(), $folder),
            'reshare' => SharePermission::reshare((int)$existing->getPermissions()),
            'expires' => $existing->getExpirationDate()?->format('Y-m-d'),
            'note' => (string)$existing->getNote(),
        ];
        [$afterExpires, $source] = match (true) {
            $expires !== null => [$expires->format('Y-m-d'), 'requested'],
            $before !== null => [$before['expires'], $before['expires'] === null ? 'none' : 'kept'],
            $this->shareManager->shareApiInternalDefaultExpireDate() => [$this->today($zone)
                ->modify('+' . $this->shareManager->shareApiInternalDefaultExpireDays() . ' days')->format('Y-m-d'), 'default'],
            default => [null, 'none'],
        };
        $after = [
            'permission' => $level,
            'reshare' => false,
            'expires' => $afterExpires,
            'note' => $note ?? $before['note'] ?? null,
        ];
        $unchanged = $existing !== null
            && (int)$existing->getPermissions() === $bits
            && $afterExpires === $before['expires']
            && ($note === null || $note === $before['note']);

        return [
            'action' => $existing === null ? self::CREATE : ($unchanged ? self::NONE : self::UPDATE),
            'node' => $node,
            'path' => PathGuard::normalize((string)$arguments['path']),
            'folder' => $folder,
            'recipient' => $recipient,
            'existing' => $existing,
            'bits' => $bits,
            'expires' => $expires,
            'note' => $note,
            'before' => $before,
            'after' => $after,
            'expiresSource' => $source,
            'timezone' => $zone,
            'warnings' => $this->warnings($recipient, $folder, $level),
        ];
    }

    /**
     * The extension point of public links. The link ticket replaces this body; until then a link is a clear refusal.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user, already granted `link`
     * @param array<string, mixed> $arguments validated arguments
     * @param ShareRecipient $recipient the `link` recipient
     * @return array<string, mixed> the change, in the shape {@see self::prepare()} returns
     * @throws ToolFailure always, for now
     */
    private function prepareLink(Folder $userFolder, string $uid, array $arguments, ShareRecipient $recipient): array {
        throw new ToolFailure(FilesMessages::shareLinkNotAvailable());
    }

    /**
     * The administrator's sharing rules the core would refuse with an untranslated exception (Manager::canShare,
     * userCreateChecks and groupCreateChecks of stable33), read through the public getters.
     *
     * @param string $uid authenticated user, the sharer
     * @param ShareRecipient $recipient person or group
     * @throws ToolFailure naming the rule that refuses
     */
    private function assertServerAllows(string $uid, ShareRecipient $recipient): void {
        if (!$this->shareManager->shareApiEnabled()) {
            throw new ToolFailure(FilesMessages::shareDisabled());
        }
        if ($this->shareManager->sharingDisabledForUser($uid)) {
            throw new ToolFailure(FilesMessages::shareDisabledForYou());
        }
        if ($recipient->kind === ShareRecipient::GROUP && !$this->shareManager->allowGroupSharing()) {
            throw new ToolFailure(FilesMessages::shareGroupsDisabled());
        }
        if (!$this->shareManager->shareWithGroupMembersOnly()) {
            return;
        }
        $sharer = $this->userManager->get($uid);
        $excluded = $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList();
        if ($recipient->kind === ShareRecipient::GROUP) {
            $group = $this->groupManager->get($recipient->id);
            if ($sharer === null || $group === null || in_array($recipient->id, $excluded, true) || !$group->inGroup($sharer)) {
                throw new ToolFailure(FilesMessages::shareOnlyOwnGroups());
            }
            return;
        }
        $other = $this->userManager->get($recipient->id);
        $common = $sharer === null || $other === null ? [] : array_diff(
            array_intersect($this->groupManager->getUserGroupIds($sharer), $this->groupManager->getUserGroupIds($other)),
            $excluded,
        );
        if ($common === []) {
            throw new ToolFailure(FilesMessages::shareOnlyGroupMembers());
        }
    }

    /**
     * The last day of the share, as the user wrote it: a real date of the calendar, from tomorrow in the user's zone,
     * and within the administrator's maximum when one is enforced.
     *
     * @param string $date Y-m-d
     * @param \DateTimeZone $zone the user's zone
     * @return \DateTime noon of that day in the user's zone, the instant handed to the core
     * @throws ArgumentValidationException with field `expires` and the rule broken
     */
    private function expiry(string $date, \DateTimeZone $zone): \DateTime {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date, $zone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ArgumentValidationException('Invalid argument: expires', 'expires', Translator::t('expected a date as YYYY-MM-DD'));
        }
        $today = $this->today($zone);
        if ($date <= $today->format('Y-m-d')) {
            throw new ArgumentValidationException('Invalid argument: expires', 'expires', Translator::t('tomorrow or later'));
        }
        if ($this->shareManager->shareApiInternalDefaultExpireDateEnforced()) {
            $days = $this->shareManager->shareApiInternalDefaultExpireDays();
            if ($date > $today->modify('+' . $days . ' days')->format('Y-m-d')) {
                throw new ArgumentValidationException('Invalid argument: expires', 'expires',
                    Translator::t('at most %s days from today (administrator rule)', [$days]));
            }
        }
        return new \DateTime($date . ' ' . self::EXPIRY_HOUR, $zone);
    }

    /** @return \DateTimeImmutable midnight of today in the user's zone */
    private function today(\DateTimeZone $zone): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone)->setTime(0, 0);
    }

    /**
     * @param ShareRecipient $recipient person or group
     * @param bool $folder whether the node is a folder
     * @param string $level view or edit
     * @return list<string> what the person must know before saying yes
     */
    private function warnings(ShareRecipient $recipient, bool $folder, string $level): array {
        $warnings = [];
        if ($recipient->kind === ShareRecipient::GROUP) {
            $warnings[] = FilesMessages::shareGroupWarning($recipient->displayName);
        }
        if ($folder && $level === SharePermission::EDIT) {
            $warnings[] = FilesMessages::shareFolderEditWarning($recipient->displayName);
        }
        return $warnings;
    }

    /**
     * The write itself, through IShareManager so the administrator's rules apply (invariant 3). A refusal of the core
     * becomes a translated message; the log keeps the exception class only, never its message, which can name paths.
     *
     * @param array<string, mixed> $change what {@see self::prepare()} returned, action create or update
     * @param string $uid authenticated user, the sharer
     * @return IShare the share as the core stored it
     * @throws ToolFailure when the core refuses
     */
    private function write(array $change, string $uid): IShare {
        try {
            if ($change['action'] === self::UPDATE) {
                $share = $change['existing'];
                $share->setPermissions($change['bits']);
                if ($change['expires'] !== null) {
                    $share->setExpirationDate($change['expires']);
                }
                if ($change['note'] !== null) {
                    $share->setNote($change['note']);
                }
                return $this->shareManager->updateShare($share);
            }
            $share = $this->shareManager->newShare();
            $share->setNode($change['node'])
                ->setShareType($change['recipient']->shareType())
                ->setSharedWith($change['recipient']->id)
                ->setSharedBy($uid)
                ->setPermissions($change['bits']);
            if ($change['expires'] !== null) {
                $share->setExpirationDate($change['expires']);
            }
            if ($change['note'] !== null && $change['note'] !== '') {
                $share->setNote($change['note']);
            }
            return $this->shareManager->createShare($share);
        } catch (\Exception $e) {
            $this->logger->warning('MCP share refused by Nextcloud', ['app' => 'mcp', 'exception_class' => $e::class]);
            throw new ToolFailure($e instanceof AlreadySharedException ? FilesMessages::shareAlreadyHasAccess() : FilesMessages::shareRefused());
        }
    }
}
