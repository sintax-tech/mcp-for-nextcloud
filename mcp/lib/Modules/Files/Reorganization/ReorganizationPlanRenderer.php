<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;

final class ReorganizationPlanRenderer {
    public static function render(string $tool, array $plan): ?string {
        try {
            $lines = match ($tool) {
                'files_mkdir' => self::mkdir($plan),
                'files_copy' => self::copy($plan),
                'files_move' => self::move($plan),
                'files_move_batch' => self::batch($plan),
                'files_undo_batch' => self::undo($plan),
                default => null,
            };
            return $lines === null ? null : implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private static function copy(array $plan): ?array {
        $from = PlanFormatting::text($plan, 'from');
        $to = PlanFormatting::text($plan, 'to');
        if ($from === null || $to === null) {
            return null;
        }
        $lines = [Translator::t('Copy %s to %s. The original stays where it is.', [PlanFormatting::bold($from), PlanFormatting::bold($to)]), ''];
        if (isset($plan['nodes'])) {
            $lines[] = '- ' . Translator::n('%n item, %s in total.', '%n items, %s in total.', (int)$plan['nodes'], [PlanFormatting::bytes((int)($plan['bytes'] ?? 0))]);
        }
        return array_merge($lines, PlanFormatting::sharing($plan));
    }

    /** @return list<string>|null */
    private static function mkdir(array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        if ($path === null) {
            return null;
        }
        $lines = [Translator::t('Create the folder %s.', [PlanFormatting::bold($path)]), ''];
        $levels = PlanFormatting::strings($plan['created'] ?? []);
        if (count($levels) > 1) {
            $lines[] = '- ' . Translator::t('Folders that will be created: %s', [implode(', ', array_map([PlanFormatting::class, 'bold'], $levels))]);
        }
        if (isset($plan['createdIn']) && is_string($plan['createdIn'])) {
            $lines[] = '- ' . Translator::t('Inside: %s', [PlanFormatting::bold($plan['createdIn'] === '' ? '/' : $plan['createdIn'])]);
        }
        return array_merge($lines, PlanFormatting::sharing($plan));
    }

    /** @return list<string>|null */
    private static function move(array $plan): ?array {
        $from = PlanFormatting::text($plan, 'from');
        $to = PlanFormatting::text($plan, 'to');
        if ($from === null || $to === null) {
            return null;
        }
        $lines = [($plan['isDir'] ?? false) === true
            ? Translator::t('Move the folder %s to %s.', [PlanFormatting::bold($from), PlanFormatting::bold($to)])
            : Translator::t('Move %s to %s.', [PlanFormatting::bold($from), PlanFormatting::bold($to)]), ''];
        if (isset($plan['versions']) && is_int($plan['versions']) && $plan['versions'] > 0) {
            $lines[] = '- ' . Translator::n('Its %n earlier version goes along.', 'Its %n earlier versions go along.', $plan['versions']);
        }
        return array_merge($lines, PlanFormatting::sharing($plan));
    }

    /** @return list<string>|null */
    private static function batch(array $plan): ?array {
        $order = $plan['order'] ?? $plan['moves'] ?? null;
        if (!is_array($order)) {
            return null;
        }
        // A blocked item is listed once, under its own heading below, and is not counted as a move.
        $blocked = [];
        foreach (['conflicts', 'denied'] as $key) {
            foreach ((array)($plan[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $blocked[(string)($item['from'] ?? '') . "\0" . (string)($item['to'] ?? '')] = true;
                }
            }
        }
        $moving = array_values(array_filter($order, static fn ($item): bool => is_array($item) && isset($item['from'], $item['to'])
            && !isset($blocked[(string)$item['from'] . "\0" . (string)$item['to']])));
        $lines = [];
        if ($moving !== []) {
            $lines = [Translator::n('Move %n item:', 'Move %n items:', count($moving)), ''];
            foreach ($moving as $item) {
                $lines[] = '- ' . PlanFormatting::bold((string)$item['from']) . ' → ' . PlanFormatting::bold((string)$item['to']);
            }
        }
        $created = [];
        foreach ((array)($plan['mkdirs'] ?? []) as $dir) {
            if (is_array($dir) && ($dir['willCreate'] ?? false) === true && isset($dir['path'])) {
                $created[] = PlanFormatting::bold((string)$dir['path']);
            }
        }
        if ($created !== []) {
            $lines[] = '';
            $lines[] = Translator::t('Folders that will be created first: %s', [implode(', ', $created)]);
        }
        $refused = array_filter((array)($plan['mkdirs'] ?? []), static fn ($dir): bool => is_array($dir) && isset($dir['path'], $dir['reason']));
        if ($refused !== []) {
            $lines[] = '';
            $lines[] = Translator::t('Folders that cannot be created, so nothing of the batch runs:');
            foreach ($refused as $dir) {
                $lines[] = '- ' . PlanFormatting::bold((string)$dir['path']) . ' — ' . PlanFormatting::excerpt((string)$dir['reason']);
            }
        }
        foreach (['conflicts', 'denied'] as $key) {
            $items = array_filter((array)($plan[$key] ?? []), 'is_array');
            if ($items === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = $key === 'conflicts'
                ? Translator::t('Cannot be moved, the destination is taken or invalid:')
                : Translator::t('Not allowed by Nextcloud:');
            foreach ($items as $item) {
                $reason = isset($item['reason']) ? ' — ' . PlanFormatting::excerpt((string)$item['reason']) : '';
                $lines[] = '- ' . PlanFormatting::bold((string)($item['from'] ?? '')) . ' → ' . PlanFormatting::bold((string)($item['to'] ?? '')) . $reason;
            }
        }
        $shared = array_filter((array)($plan['shared'] ?? []), 'is_array');
        if ($shared !== []) {
            $lines[] = '';
            $lines[] = Translator::t('These items belong to someone else or are shared, so moving them also needs the shared-content confirmation:');
            foreach ($shared as $item) {
                $lines[] = '- ' . PlanFormatting::bold((string)($item['from'] ?? '')) . ' → ' . PlanFormatting::bold((string)($item['to'] ?? ''));
            }
        }
        $sharedDirs = array_filter((array)($plan['sharedDirs'] ?? []), static fn ($dir): bool => is_array($dir) && isset($dir['path']));
        if ($sharedDirs !== []) {
            $lines[] = '';
            $lines[] = Translator::t('Folders created in a shared location, which also need the shared-content confirmation:');
            foreach ($sharedDirs as $dir) {
                $lines[] = '- ' . PlanFormatting::bold((string)$dir['path']);
            }
        }
        if ($moving !== []) {
            $lines[] = '';
            $lines[] = Translator::t('You can undo the whole batch afterwards.');
        }
        if (($lines[0] ?? null) === '') {
            array_shift($lines);
        }
        return $lines;
    }

    /** @return list<string>|null */
    private static function undo(array $plan): ?array {
        if (!isset($plan['undo']) || !is_array($plan['undo'])) {
            return null;
        }
        $lines = [Translator::n('Undo a batch and put %n item back where it was:', 'Undo a batch and put %n items back where they were:', count($plan['undo'])), ''];
        foreach ($plan['undo'] as $item) {
            if (is_array($item) && isset($item['from'], $item['to'])) {
                $lines[] = '- ' . PlanFormatting::bold((string)$item['to']) . ' → ' . PlanFormatting::bold((string)$item['from']);
            }
        }
        $removed = PlanFormatting::strings($plan['removed_dirs'] ?? []);
        if ($removed !== []) {
            $lines[] = '';
            $lines[] = Translator::t('Empty folders the batch created will be removed: %s', [implode(', ', array_map([PlanFormatting::class, 'bold'], $removed))]);
        }
        $conflicts = array_filter((array)($plan['conflicts'] ?? []), 'is_array');
        if ($conflicts !== []) {
            $lines[] = '';
            $lines[] = Translator::t('The undo is blocked because these items changed since the batch:');
            foreach ($conflicts as $item) {
                $reason = isset($item['reason']) ? ' — ' . PlanFormatting::excerpt((string)$item['reason']) : '';
                $lines[] = '- ' . PlanFormatting::bold((string)($item['to'] ?? $item['from'] ?? '')) . $reason;
            }
        }
        return $lines;
    }
}
