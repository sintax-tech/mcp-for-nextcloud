<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Mcp\Confirmation\PlanText;

final class PlanFormatting {
    private const EXCERPT = 200;
    private const NAME = 400;

    /** @return list<string> the folder that receives a new file, when the plan names it */
    public static function folderLine(array $plan): array {
        $folder = self::text($plan, 'folder');
        return $folder === null ? [] : ['- ' . Translator::t('Inside: %s', [self::bold($folder)])];
    }

    /**
     * @param array<string, mixed> $plan plan of a tool that copies the file before writing
     * @return list<string> the line naming the backup folder, when the plan carries one
     */
    public static function backup(array $plan): array {
        $backup = self::text($plan, 'backup');
        return $backup === null ? [] : ['- ' . Translator::t('A copy of the current file is saved first in %s.', [self::bold($backup)])];
    }

    /**
     * @param array<string, mixed> $plan plan with an optional `shared` list of access descriptions
     * @return list<string> who else is reached, and the extra confirmation when the plan asks for it
     */
    public static function sharing(array $plan): array {
        $lines = [];
        foreach ((array)($plan['shared'] ?? []) as $info) {
            if (!is_array($info)) {
                continue;
            }
            $who = (string)($info['sharedBy'] ?? $info['ownerDisplayName'] ?? '');
            $line = match ($info['scope'] ?? null) {
                NodeAccessInfo::TEAM => Translator::t('It is in the team folder %s, so other members see the change.', [self::bold((string)($info['teamFolder'] ?? ''))]),
                NodeAccessInfo::EXTERNAL => Translator::t('It is on external storage.'),
                NodeAccessInfo::SHARED => Translator::t('It was shared with you by %s, so the change reaches them too.', [self::bold($who)]),
                default => null,
            };
            if ($line !== null) {
                $lines[] = '- ' . $line;
            }
        }
        if (($plan['requiresSharedConfirmation'] ?? false) === true) {
            $lines[] = '- ' . Translator::t('Because other people are affected, an extra confirmation of the shared content is needed.');
        }
        return $lines;
    }

    /**
     * @param string $diff unified diff
     * @return array{list<string>, list<string>} removed and added lines, without the markers
     */
    public static function changes(string $diff): array {
        $removed = [];
        $added = [];
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                continue;
            }
            if (str_starts_with($line, '-')) {
                $removed[] = trim(substr($line, 1));
            } elseif (str_starts_with($line, '+')) {
                $added[] = trim(substr($line, 1));
            }
        }
        return [array_values(array_filter($removed, 'strlen')), array_values(array_filter($added, 'strlen'))];
    }

    /** @return string the instant as the person reads it, in their timezone when the plan names one */
    public static function moment(string $iso, ?string $timezone): string {
        try {
            $date = new \DateTimeImmutable($iso);
            if ($timezone !== null) {
                $date = $date->setTimezone(new \DateTimeZone($timezone));
            }
            return $date->format(Translator::t('m/d/Y H:i'));
        } catch (\Throwable) {
            return PlanText::inline($iso);
        }
    }

    /** @return string the text shortened to the excerpt limit, free of Markdown and line breaks */
    public static function excerpt(string $text): string {
        return PlanText::inline($text, self::EXCERPT);
    }

    public static function quote(string $text): string {
        return '«' . self::excerpt($text) . '»';
    }

    /** @return string a file, folder or person name in bold, inert as Markdown */
    public static function bold(string $text): string {
        return PlanText::strong($text, self::NAME);
    }

    public static function bytes(int $bytes): string {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return ($i === 0 ? (string)$bytes : rtrim(rtrim(number_format($bytes / 1024 ** $i, 1, '.', ''), '0'), '.')) . ' ' . $unit;
            }
        }
        return $bytes . ' B';
    }

    /** @return string|null the non-empty string under $key */
    public static function text(array $plan, string $key): ?string {
        return isset($plan[$key]) && is_string($plan[$key]) && $plan[$key] !== '' ? $plan[$key] : null;
    }

    /** @return list<string> the string members of a list */
    public static function strings(mixed $value): array {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
