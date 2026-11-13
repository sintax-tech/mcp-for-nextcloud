<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Editing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Status\Integration\StatusEditor;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Presentation\StatusTypes;
use OCA\Mcp\Modules\Status\Reading\StatusSnapshot;
use OCP\AppFramework\Utility\ITimeFactory;

/** Plans a type change without writing, and applies it only on the confirmed call. */
final class SetOwnStatusTypeHandler {
	/**
	 * @param StatusGateway $native select used for the plan and the read-back
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
	 * @return array<string, mixed> plan; nothing is written
	 * @throws \InvalidArgumentException when the type is not native
	 */
	public function plan(string $actor, string $status): array {
		$this->known($status);
		$row = $this->native->find($actor);
		return [
			'requiresConfirmation' => true,
			'action' => 'status_set_type',
			'before' => $row?->status,
			'after' => $status,
			'message_kept' => true,
			'removes_backup' => true,
			'message' => Translator::t('This changes only your own status type. The stored message stays. A temporary backup of your status is removed, as in the status menu.'),
		];
	}

	/**
	 * @param string $actor authenticated owner
	 * @param string $status requested native type
	 * @return array<string, mixed> configured snapshot plus whether a backup row was removed
	 * @throws \InvalidArgumentException when the type is not native
	 */
	public function apply(string $actor, string $status): array {
		$this->known($status);
		$removed = $this->editor->setOwnType($actor, $status);
		$snapshot = $this->snapshot->project($this->native->find($actor), $this->time->getTime());
		return $snapshot + ['removed_backup' => $removed];
	}

	/** @throws \InvalidArgumentException when the type is not one of the six native values */
	private function known(string $status): void {
		if (!StatusTypes::known($status)) {
			throw new \InvalidArgumentException(Translator::t('The status type is not supported.'));
		}
	}
}
