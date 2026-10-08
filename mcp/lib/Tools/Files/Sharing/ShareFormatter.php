<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCP\IURLGenerator;
use OCP\Share\IShare;

/**
 * The item a share list returns for one share.
 *
 * The password never leaves (invariant 2): an item says only whether the share has one. The note is left out
 * too, since it is free text for the recipient. Stateless: one instance serves every user.
 */
final class ShareFormatter {
    /** Public route of the core that opens a link share by its token. */
    public const LINK_ROUTE = 'files_sharing.sharecontroller.showShare';

    public function __construct(
        private ShareRecipientResolver $recipients,
        private IURLGenerator $urls,
    ) {}

    /**
     * @param IShare $share share of type user, group, link or room
     * @param string $path the shared node as the user sees it, user-relative
     * @param bool $removable whether files_unshare may remove it ({@see ShareAccess::isRemovable()})
     * @return array{shareId:string, type:string, with:array{id:string, displayName:string}, permission:string, reshare:bool, expires:string|null, hasPassword:bool, url?:string, removable:bool, path:string}
     *   `shareId` is the full id files_unshare takes; `permission` is view, edit or custom
     *   ({@see SharePermission::classify()}); `reshare` is the SHARE bit, which on a link means outgoing
     *   federation and not a re-share; `expires` is Y-m-d or null; `url` exists for a link only
     * @throws \InvalidArgumentException for a share type outside user, group, link and room
     */
    public function item(IShare $share, string $path, bool $removable): array {
        $recipient = $this->recipients->ofShare($share);
        $bits = (int)$share->getPermissions();
        $password = $share->getPassword();
        $item = [
            'shareId' => (string)$share->getFullId(),
            'type' => $recipient->kind,
            'with' => ['id' => $recipient->id, 'displayName' => $recipient->displayName],
            'permission' => SharePermission::classify($bits, $share->getNodeType() === 'folder'),
            'reshare' => SharePermission::reshare($bits),
            'expires' => $share->getExpirationDate()?->format('Y-m-d'),
            'hasPassword' => $password !== null && $password !== '',
        ];
        $token = $share->getToken();
        if ($recipient->kind === ShareRecipient::LINK && $token !== null && $token !== '') {
            $item['url'] = $this->urls->linkToRouteAbsolute(self::LINK_ROUTE, ['token' => $token]);
        }
        return $item + ['removable' => $removable, 'path' => $path];
    }
}
