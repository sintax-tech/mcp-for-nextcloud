<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\BackupCopies;
use OCA\Mcp\Service\BackupLocation;

/** Describes the copy that this actor's confirmed operation would actually create. */
final class BackupPlan {
	/**
	 * @param BackupLocation|null $location live destination, null retains standalone defaults
	 * @param BackupCopies|null $copies live per-actor preference, null keeps copies mandatory
	 */
	public function __construct(private ?BackupLocation $location = null, private ?BackupCopies $copies = null) {
	}

	/**
	 * @param array<string, mixed> $plan existing tool-specific plan and confirmations
	 * @param string $uid acting user
	 * @return array<string, mixed> plan with honest copy, recovery and consequence fields
	 */
	public function decorate(array $plan, string $uid): array {
		$settings = $this->location?->current() ?? BackupLocation::defaults();
		if ($this->copies?->required($uid) ?? true) {
			$plan['backup'] = '/' . $settings['folder'];
			return $plan;
		}
		return array_replace($plan, [
			'backup' => '',
			'recoverable' => false,
			'message' => BackupPlanInstructions::withoutCopy((string)($plan['action'] ?? '')),
			'consequence' => Translator::t('MCP pre-write copies are suspended. Nextcloud versioning follows its own policy and may not retain a version for every file or backend.'),
		]);
	}
}
