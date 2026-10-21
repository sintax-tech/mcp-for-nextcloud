<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Versions;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;

final class VersionPlanRenderer {
    public static function render(string $tool, array $plan): ?string {
        try {
            $lines = match ($tool) {
                'files_version_restore' => self::restore($plan),
                default => null,
            };
            return $lines === null ? null : implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private static function restore(array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        $version = $plan['version'] ?? null;
        if ($path === null || !is_array($version) || !isset($version['timestamp'])) {
            return null;
        }
        $when = PlanFormatting::moment((string)$version['timestamp'], PlanFormatting::text($plan, 'timezone'));
        $lines = [Translator::t('Bring %s back to the version of %s.', [PlanFormatting::bold($path), $when]), ''];
        if (isset($version['size'])) {
            $current = is_array($plan['current'] ?? null) && isset($plan['current']['size']) ? (int)$plan['current']['size'] : null;
            $lines[] = '- ' . ($current === null
                ? Translator::t('Size: %s', [PlanFormatting::bytes((int)$version['size'])])
                : Translator::t('Size: %s → %s', [PlanFormatting::bytes($current), PlanFormatting::bytes((int)$version['size'])]));
        }
        $lines[] = '- ' . Translator::t('The current content is replaced by that version.');
        return array_merge($lines, PlanFormatting::backup($plan), PlanFormatting::sharing($plan));
    }
}
