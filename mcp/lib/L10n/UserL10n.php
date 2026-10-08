<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\L10n;

use OCP\IL10N;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Resolves the translator of the `mcp` app for an authenticated user.
 *
 * Token requests (claude.ai, Desktop) carry no session and no reliable Accept-Language, so the IL10N the container
 * injects is no use here: the language is resolved in explicit priority:
 * 1. Forced language (if configured on the instance)
 * 2. User account language preference
 * 3. Server default language
 * 4. English (en) fallback
 *
 * Languages not available in the `mcp` app are skipped so Nextcloud factory heuristics (such as inspecting
 * Accept-Language headers) never take precedence over account or server defaults.
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
        try {
            foreach ($this->factory->getLanguageIterator($user) as $lang) {
                if ($lang === "en" || $this->factory->languageExists("mcp", $lang)) {
                    return $this->factory->get("mcp", $lang);
                }
            }
        } catch (\Throwable) {
            // Fall back safely if the iterator is unavailable
        }
        return $this->factory->get("mcp", "en");
    }
}