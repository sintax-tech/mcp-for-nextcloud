<?php
declare(strict_types=1);

namespace OCA\Mcp\L10n;

use OCP\IL10N;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Resolves the translator of the `mcp` app for an authenticated user.
 *
 * Token requests (claude.ai, Desktop) carry no session and no reliable Accept-Language, so the IL10N the container
 * injects is no use here: the language is the one stored in the account, then the server default, then English,
 * which is what IFactory::getUserLanguage() already does.
 */
class UserL10n {
    public function __construct(private IFactory $factory) {
    }

    /**
     * Translator of the `mcp` app in the language of the given account.
     *
     * @param IUser $user authenticated user
     */
    public function forUser(IUser $user): IL10N {
        return $this->factory->get('mcp', $this->factory->getUserLanguage($user));
    }
}
