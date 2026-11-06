<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess;

use OCA\Mcp\L10n\Translator;

/** Formats binary resource messages. */
final class BinaryResourceMessages {
	/**
	 * @param int $limit binary read limit in bytes
	 * @return string a binary resource over the read limit
	 */
	public static function binaryResourceTooLarge(int $limit): string {
		return Translator::t('Resource exceeds the binary read limit of %s bytes.', [(string)$limit]);
	}

}
