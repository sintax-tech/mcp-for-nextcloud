<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCP\Share\IShare;

/**
 * Who a share is for: a person, a group, a public link or (read only, from Talk) a conversation.
 *
 * Built by {@see ShareRecipientResolver}, which checks that the account or the group exists before a
 * caller ever sees one. Immutable; it carries no state of the user who shares.
 */
final class ShareRecipient {
    /** A Nextcloud account, written `user:<uid>`. */
    public const USER = 'user';
    /** A Nextcloud group, written `group:<gid>`. */
    public const GROUP = 'group';
    /** A public link, written `link`. */
    public const LINK = 'link';
    /** A Talk conversation; listed, never accepted as input. */
    public const ROOM = 'room';

    /** IShare::TYPE_* of each kind. */
    private const SHARE_TYPES = [
        self::USER => IShare::TYPE_USER,
        self::GROUP => IShare::TYPE_GROUP,
        self::LINK => IShare::TYPE_LINK,
        self::ROOM => IShare::TYPE_ROOM,
    ];

    /**
     * @param string $kind one of the class constants
     * @param string $id uid, gid or conversation token; '' for a link
     * @param string $displayName name to show the person, never empty
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $displayName,
    ) {}

    /**
     * @param int $shareType IShare::TYPE_* of an existing share
     * @return string|null the kind of that type, null for a type the 0.10 does not handle (e-mail, federated)
     */
    public static function kindOf(int $shareType): ?string {
        $kind = array_search($shareType, self::SHARE_TYPES, true);
        return $kind === false ? null : $kind;
    }

    /** @return int IShare::TYPE_* of this recipient */
    public function shareType(): int {
        return self::SHARE_TYPES[$this->kind];
    }

    /** @return string the recipient as the tools take it: `user:<uid>`, `group:<gid>`, `link` or `room:<token>` */
    public function spec(): string {
        return $this->kind === self::LINK ? self::LINK : $this->kind . ':' . $this->id;
    }

    /**
     * Whether an existing share is for this recipient. Any link matches `link`, since the 0.10 keeps one link per file.
     *
     * @param IShare $share existing share
     * @return bool true when the type and, except for a link, the recipient id are the same
     */
    public function matches(IShare $share): bool {
        if ($share->getShareType() !== $this->shareType()) {
            return false;
        }
        return $this->kind === self::LINK || $share->getSharedWith() === $this->id;
    }

    /** @return array{type:string, id:string, displayName:string} the recipient as a tool result shows it */
    public function toArray(): array {
        return ['type' => $this->kind, 'id' => $this->id, 'displayName' => $this->displayName];
    }
}
