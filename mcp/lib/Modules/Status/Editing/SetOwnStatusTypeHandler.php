<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Editing;

use OCA\Mcp\Modules\Status\Integration\StatusEditor;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Presentation\StatusTypes;
use OCA\Mcp\Modules\Status\Reading\StatusSnapshot;
use OCP\AppFramework\Utility\ITimeFactory;

/** Applies a confirmed type change and reads the stored row back. */
final class SetOwnStatusTypeHandler {
	/**
	 * @param StatusGateway $native select used for the read-back
	 * @param ITimeFactory $time clock for the projected expired flag
	 * @param StatusSnapshot $snapshot read-back shape
	 * @param StatusEditor $editor confirmed native write
	 */
	public function __construct(
		private StatusGateway $native,
		private ITimeFactory $time,
		private StatusSnapshot $snapshot,
		private StatusEditor $editor,
	) {
	}

	/**
	 * @param string $actor authenticated owner
	 * @param string $status requested native type
	 * @return array<string, mixed> configured snapshot plus whether a backup row was removed
	 * @throws \InvalidArgumentException when the type is not native
	 */
	public function apply(string $actor, string $status): array {
		StatusTypes::require($status);
		$removed = $this->editor->setOwnType($actor, $status);
		$snapshot = $this->snapshot->project($this->native->find($actor), $this->time->getTime());
		return $snapshot + ['removed_backup' => $removed];
	}
}
