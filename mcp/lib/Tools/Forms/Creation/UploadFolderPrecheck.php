<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Reads exactly the native title helper's universe, without projecting Files data or creating directories. */
class UploadFolderPrecheck {
	/** Refuses detected coincidences or unavailable safety reads after S0; does not close the accepted race.
	 * @param CreateFormContext $context Native graph and exact pin.
	 * @param int $id Newly observed form identity.
	 * @param string $uid Actor, already verified to own this new form.
	 * @return void
	 * @throws ToolFailure Generic partial refusal without filenames, counts or folder identifiers.
	 */
	public function assertClear(CreateFormContext $context, int $id, string $uid): void {
		if ($context->version !== '5.4.1') { return; }
		try {
			if ($context->paths->getAllFormFoldersById($id, $uid) !== []) { throw new \RuntimeException('Title stage not admitted'); }
		} catch (\Throwable $error) { throw new ToolFailure(CreateFormMessages::precheck(), 0, $error); }
	}
}
