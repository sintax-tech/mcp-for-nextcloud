<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;

final class EditingPlanRenderer {
    public static function render(string $tool, array $plan): ?string {
        try {
            $lines = match ($tool) {
                'files_edit' => self::content($tool, $plan),
                'files_replace' => self::content($tool, $plan),
                default => null,
            };
            return $lines === null ? null : implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private static function content(string $tool, array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        if ($path === null || !isset($plan['diff']) || !is_string($plan['diff'])) {
            return null;
        }
        $lines = [$tool === 'files_edit'
            ? Translator::t('Overwrite the content of %s with the new text.', [PlanFormatting::bold($path)])
            : Translator::t('Replace a passage of %s.', [PlanFormatting::bold($path)]), ''];
        [$removed, $added] = PlanFormatting::changes($plan['diff']);
        if ($removed === [] && $added === []) {
            $lines[] = '- ' . Translator::t('The text does not change.');
        } else {
            $lines[] = '- ' . Translator::t('Lines removed: %s; lines added: %s.', [count($removed), count($added)]);
            if ($removed !== []) {
                $lines[] = '- ' . Translator::t('Before: %s', [PlanFormatting::quote(implode(' ', $removed))]);
            }
            if ($added !== []) {
                $lines[] = '- ' . Translator::t('After: %s', [PlanFormatting::quote(implode(' ', $added))]);
            }
        }
        $size = $plan['size'] ?? null;
        if (is_array($size) && isset($size['before'], $size['after'])) {
            $lines[] = '- ' . Translator::t('Size: %s → %s', [PlanFormatting::bytes((int)$size['before']), PlanFormatting::bytes((int)$size['after'])]);
        }
        return array_merge($lines, PlanFormatting::backup($plan), PlanFormatting::sharing($plan));
    }
}
