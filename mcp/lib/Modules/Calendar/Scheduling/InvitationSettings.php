<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Scheduling;

use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;

/** Reads the native DAV invitation switch across legacy string and boolean configuration types. */
final class InvitationSettings {
	/**
	 * Older DAV releases use the literal "yes"; the newer lexicon declares a boolean defaulting to true.
	 * Use the stored/declared type before reading, because a mismatched typed getter throws.
	 * Include lazy keys: filtering them out would mistake a stored disabled value for an absent key.
	 *
	 * @param IAppConfig $config Native app configuration; never modified by this reader.
	 * @return bool Whether the server enables invitation emails, true when the setting is absent.
	 */
	public static function enabled(IAppConfig $config): bool {
		try {
			$type = $config->getValueType('dav', 'sendInvitations', null);
		} catch (AppConfigUnknownKeyException) {
			return true;
		}
		if (($type & IAppConfig::VALUE_BOOL) !== 0) {
			return $config->getValueBool('dav', 'sendInvitations', true);
		}
		return $config->getValueString('dav', 'sendInvitations', 'yes') === 'yes';
	}
}
