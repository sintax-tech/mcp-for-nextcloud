<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * The creation warning that follows the installed Forms version.
 *
 * Forms 5.4.1 can rename or merge upload folders when the title is set. Every other supported release does not,
 * so the warning must not attribute those effects to a pin that does not have them.
 */
final class CreateFormVersionNotice {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The native-effects warning for one installed release, without observed folder data.
	 *
	 * @param string $version Installed Forms release the warning names.
	 * @return string Warning in the language of the current user.
	 */
	public static function warning(string $version): string {
		if ($version === '5.4.1') {
			return Translator::t('Creation follows the installed Forms app’s native behavior. In Forms %s, setting the title can rename or merge matching upload folders and remove empty folders with the new form ID prefix. Detected matches stop the title step, leaving a partially created form. Concurrent changes can still trigger native folder changes; these checks do not guarantee that Files remain unchanged.', [$version]);
		}
		return Translator::t('Creation follows the installed Forms app’s native behavior (Forms %s). Setting the title does not rename or merge upload folders in this version. Creation can still leave a partial form. These checks do not guarantee that Files remain unchanged.', [$version]);
	}
}
