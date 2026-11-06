<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

/** Dispatches Files confirmation plans and joins their Markdown lines, falling back on incomplete plans. */
final class FilesPlanRenderer {
	/** Prevents instantiation of this static dispatcher. */
	private function __construct() {
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan returned by FilesModule::preview()
	 * @return string|null Markdown body, or null when the tool is not a Files write or the plan lacks what it needs
	 */
	public static function render(string $tool, array $plan): ?string {
		try {
			$lines = match ($tool) {
				'files_delete' => \OCA\Mcp\Modules\Files\Deletion\DeletePlanText::lines($plan),
				'files_edit', 'files_replace' => EditingPlanText::content($tool, $plan),
				'files_checkout' => CheckoutPlanText::checkout($plan),
				'files_copy' => ReorganizationPlanText::copy($plan),
				'files_mkdir' => ReorganizationPlanText::mkdir($plan),
				'files_move' => ReorganizationPlanText::move($plan),
				'files_move_batch' => ReorganizationPlanText::batch($plan),
				'files_undo_batch' => ReorganizationPlanText::undo($plan),
				'files_version_restore' => VersionPlanText::restore($plan),
				'files_share' => SharingPlanText::share($plan),
				'files_unshare' => SharingPlanText::unshare($plan),
				'files_upload' => CreationPlanText::upload($plan),
				'files_create' => CreationPlanText::create($plan),
				default => null,
			};
		} catch (\Throwable) {
			return null;
		}
		return $lines === null ? null : implode("\n", $lines);
	}
}
