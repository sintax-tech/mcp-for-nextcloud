<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;
use OCA\Mcp\Mcp\Confirmation\PlanText;

final class CreationPlanRenderer {
    public static function render(string $tool, array $plan): ?string {
        try {
            $lines = match ($tool) {
                'files_upload' => self::upload($plan),
                'files_create' => self::create($plan),
                default => null,
            };
            return $lines === null ? null : implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The plan of files_upload: the new file, where it goes, how long the link lasts and what it may weigh.
     *
     * @return list<string>|null
     */
    private static function upload(array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        if ($path === null) {
            return null;
        }
        $lines = [Translator::t('Create the new file %s with the content you send.', [PlanFormatting::bold($path)]), ''];
        $lines = array_merge($lines, PlanFormatting::folderLine($plan));
        if (isset($plan['size'])) {
            $lines[] = '- ' . Translator::t('Size: %s', [PlanFormatting::bytes((int)$plan['size'])]);
        }
        if (isset($plan['uploadTtlSeconds'])) {
            $lines[] = '- ' . Translator::t('The upload link works once, for %s minutes.', [(int)ceil((int)$plan['uploadTtlSeconds'] / 60)]);
        }
        if (isset($plan['uploadMaxBytes'])) {
            $lines[] = '- ' . Translator::t('The file can weigh up to %s.', [PlanFormatting::bytes((int)$plan['uploadMaxBytes'])]);
        }
        $lines[] = '- ' . CreationMessages::planNewFileConsequence();
        return array_merge($lines, PlanFormatting::sharing($plan));
    }

    /**
     * The plan of files_create: the new text file, where it goes, its size and the beginning of its text as a quote.
     *
     * @return list<string>|null
     */
    private static function create(array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        if ($path === null) {
            return null;
        }
        $lines = [Translator::t('Create the text file %s.', [PlanFormatting::bold($path)]), ''];
        $lines = array_merge($lines, PlanFormatting::folderLine($plan));
        if (isset($plan['size'])) {
            $lines[] = '- ' . Translator::t('Size: %s', [PlanFormatting::bytes((int)$plan['size'])]);
        }
        $lines[] = '- ' . CreationMessages::planNewFileConsequence();
        $lines = array_merge($lines, PlanFormatting::sharing($plan));
        $preview = is_string($plan['preview'] ?? null) ? PlanText::quote($plan['preview'], FileCreation::PREVIEW_CHARS) : '';
        if ($preview === '') {
            return array_merge($lines, ['', Translator::t('The file is empty.')]);
        }
        $cut = ($plan['previewTruncated'] ?? false) === true;
        $lines[] = '';
        $lines[] = $cut ? Translator::t('Beginning of the content:') : Translator::t('Content:');
        $lines[] = $preview . ($cut && !str_ends_with($preview, '…') ? '…' : '');
        return $lines;
    }
}
