<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;

final class CheckoutPlanRenderer {
    public static function render(string $tool, array $plan): ?string {
        try {
            $lines = match ($tool) {
                'files_checkout' => self::checkout($plan),
                default => null,
            };
            return $lines === null ? null : implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private static function checkout(array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        if ($path === null) {
            return null;
        }
        $lines = [Translator::t('Prepare %s to be downloaded, changed and sent back.', [PlanFormatting::bold($path)]), ''];
        if (isset($plan['size'])) {
            $lines[] = '- ' . Translator::t('Size: %s', [PlanFormatting::bytes((int)$plan['size'])]);
        }
        if (isset($plan['downloadTtlSeconds'], $plan['uploadTtlSeconds'])) {
            $lines[] = '- ' . Translator::t('The download link works for %s minutes and the upload link for %s minutes.', [
                (int)ceil((int)$plan['downloadTtlSeconds'] / 60),
                (int)ceil((int)$plan['uploadTtlSeconds'] / 60),
            ]);
        }
        if (isset($plan['uploadMaxBytes'])) {
            $lines[] = '- ' . Translator::t('The new version can weigh up to %s.', [PlanFormatting::bytes((int)$plan['uploadMaxBytes'])]);
        }
        $lines[] = '- ' . Translator::t('Nothing is changed until the new version is uploaded.');
        return array_merge($lines, PlanFormatting::backup($plan), PlanFormatting::sharing($plan));
    }
}
