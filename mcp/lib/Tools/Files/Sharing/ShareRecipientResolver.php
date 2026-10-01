<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IShare;

/**
 * Turns the `with` argument of the sharing tools into a {@see ShareRecipient}, and names the recipient of an
 * existing share.
 *
 * Input accepted: `user:<uid>`, `group:<gid>` or `link`. Every refusal is an {@see ArgumentValidationException}
 * with the declared field and a fixed rule, never the submitted value. Stateless: one instance serves every user.
 */
final class ShareRecipientResolver {
    public function __construct(
        private IUserManager $userManager,
        private IGroupManager $groupManager,
    ) {}

    /**
     * @param string $with `user:<uid>`, `group:<gid>` or `link`; surrounding spaces are ignored
     * @param string $field argument path reported on a refusal
     * @return ShareRecipient the recipient, whose account or group exists
     * @throws ArgumentValidationException for another format, an unknown account or an unknown group
     */
    public function resolve(string $with, string $field = 'with'): ShareRecipient {
        $with = trim($with);
        if ($with === ShareRecipient::LINK) {
            return new ShareRecipient(ShareRecipient::LINK, '', FilesMessages::publicLink());
        }
        [$kind, $id] = array_pad(explode(':', $with, 2), 2, '');
        if ($id === '' || !in_array($kind, [ShareRecipient::USER, ShareRecipient::GROUP], true)) {
            throw new ArgumentValidationException('Invalid argument: ' . $field, $field,
                Translator::t('expected user:<uid>, group:<gid> or link'));
        }
        if ($kind === ShareRecipient::USER) {
            $user = $this->userManager->get($id);
            if ($user === null) {
                throw new ArgumentValidationException('Invalid argument: ' . $field, $field, Translator::t('unknown account'));
            }
            return new ShareRecipient(ShareRecipient::USER, $user->getUID(), $user->getDisplayName());
        }
        $group = $this->groupManager->get($id);
        if ($group === null) {
            throw new ArgumentValidationException('Invalid argument: ' . $field, $field, Translator::t('unknown group'));
        }
        return new ShareRecipient(ShareRecipient::GROUP, $group->getGID(), $group->getDisplayName());
    }

    /**
     * The recipient of an existing share, named for the person. An account or a group removed since then
     * keeps its id as the name; a conversation uses the name the Talk provider gave the share.
     *
     * @param IShare $share share of type user, group, link or room
     * @return ShareRecipient the recipient as the share records it
     * @throws \InvalidArgumentException for a share type the 0.10 does not handle
     */
    public function ofShare(IShare $share): ShareRecipient {
        $kind = ShareRecipient::kindOf($share->getShareType()) ?? throw new \InvalidArgumentException('Unsupported share type');
        $id = (string)$share->getSharedWith();
        $name = match ($kind) {
            ShareRecipient::USER => $this->userManager->get($id)?->getDisplayName(),
            ShareRecipient::GROUP => $this->groupManager->get($id)?->getDisplayName(),
            ShareRecipient::LINK => FilesMessages::publicLink(),
            ShareRecipient::ROOM => (string)$share->getSharedWithDisplayName(),
        };
        return new ShareRecipient($kind, $kind === ShareRecipient::LINK ? '' : $id, $name === null || $name === '' ? $id : $name);
    }
}
