<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\Modules\Files\Checkout\CheckoutPlanRenderer;
use OCA\Mcp\Modules\Files\Creation\CreationPlanRenderer;
use OCA\Mcp\Modules\Files\Editing\EditingPlanRenderer;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationPlanRenderer;
use OCA\Mcp\Modules\Files\Sharing\SharingPlanRenderer;
use OCA\Mcp\Modules\Files\Versions\VersionPlanRenderer;

final class FilesPlanRenderer {
    /**
     * @param string $tool tool name
     * @param array<string, mixed> $plan the plan returned by FilesModule::preview()
     * @return string|null Markdown body, or null when the tool is not a Files write or the plan lacks what it needs
     */
    public static function render(string $tool, array $plan): ?string {
        if (in_array($tool, ['files_mkdir', 'files_copy', 'files_move', 'files_move_batch', 'files_undo_batch'], true)) {
            return ReorganizationPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_share', 'files_unshare'], true)) {
            return SharingPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_upload', 'files_create'], true)) {
            return CreationPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_checkout'], true)) {
            return CheckoutPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_version_restore'], true)) {
            return VersionPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_edit', 'files_replace'], true)) {
            return EditingPlanRenderer::render($tool, $plan);
        }
        return null;
    }
}
