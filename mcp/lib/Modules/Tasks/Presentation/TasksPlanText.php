<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Presentation;

use DateTimeImmutable;
use Exception;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class TasksPlanText
{
    public const EXCERPT = 200;
    public const FIELDS = [
        'summary',
        'description',
        'start',
        'due',
        'priority',
        'percentComplete',
        'categories',
    ];
    public function changes(
        array $lines,
        array $before,
        array $after,
        DateTimeZone $zone,
    ): string
    {
        foreach (self::FIELDS as $field) {
            $from = $this->value($before[$field] ?? null, $field, $zone);
            $to = $this->value($after[$field] ?? null, $field, $zone);
            // Categories are compared as lists: two different lists may read alike once joined.
            $same = $field === 'categories' ? $this->names($before[$field] ?? null) === $this->names($after[$field] ?? null) : $from === $to;
            if ($same) {
                continue;
            }
            $lines[] = '- ' . $this->label($field) . ': ' . ($from ?? Translator::t('empty')) . ' → ' . ($to ?? Translator::t('empty'));
        }
        return implode("\n", $lines);
    }
    public function shared(
        string $body,
        array $plan,
        string $calendar,
    ): string
    {
        foreach ((array) ($plan['shared'] ?? []) as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $owner = $this->text($notice['ownerDisplayName'] ?? $notice['owner'] ?? '');
            if ($owner === '') {
                continue;
            }
            $resource = $this->text($notice['resource'] ?? '') ?: $calendar;
            return $body . "\n" . Translator::t(
                'The calendar %s belongs to %s and is shared with you, so the change affects other people.',
                [$this->emphasis($resource), $this->strong($owner)]
            );
        }
        return $body;
    }
    public function name(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        $name = $this->text($value['name'] ?? '');
        return $name === '' ? null : $name;
    }
    public function task(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
    public function value(
        mixed $value,
        string $field,
        DateTimeZone $zone,
    ): ?string
    {
        if ($field === 'percentComplete') {
            return is_numeric($value) ? $this->text($value) . '%' : null;
        }
        if ($field === 'priority') {
            return is_numeric($value) ? $this->priority((int) $value) : null;
        }
        if (in_array($field, ['start', 'due', 'completed'], true)) {
            return $this->date($value, $zone);
        }
        if ($field === 'categories') {
            return $this->categories($value);
        }
        $text = $this->text($value);
        return $text === '' ? null : PlanText::inline($text, self::EXCERPT);
    }
    public function date(mixed $value, DateTimeZone $zone): ?string
    {
        $text = $this->text($value);
        if ($text === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($text);
        } catch (Exception) {
            return PlanText::inline($text, self::EXCERPT);
        }
        // A date without a time is a whole day for everybody; moving it to another zone would move the day.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }
        // The person's own language decides the order of day and month: "02/10" is two different days in two countries.
        $formatted = (new IntlDateFormatter(
            Translator::locale(),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
            $zone
        ))->format($date);
        return is_string($formatted) ? $formatted : $date->setTimezone($zone)->format('Y-m-d H:i');
    }
    public function categories(mixed $value): ?string
    {
        $names = array_map(PlanText::quoted(...), $this->names($value));
        return $names === [] ? null : implode(', ', $names);
    }
    public function names(mixed $value): array
    {
        return array_values(array_filter(array_map($this->text(...), is_array($value) ? $value : []), static fn(string $name) => $name !== ''));
    }
    public function priority(int $priority): string
    {
        // One literal per call: the translation tool extracts the source texts from here.
        return match (true) {
            $priority <= 0 => Translator::t('without priority'),
            $priority === 1 => Translator::t('highest priority'),
            $priority <= 4 => Translator::t('high priority'),
            $priority === 5 => Translator::t('normal priority'),
            $priority <= 8 => Translator::t('low priority'),
            default => Translator::t('lowest priority'),
        };
    }
    public function zone(array $plan): DateTimeZone
    {
        $name = $this->text($plan['timezone'] ?? '');
        if ($name === '') {
            return new DateTimeZone('UTC');
        }
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
    public function label(string $field): string
    {
        // One literal per call: the translation tool extracts the source texts from here.
        return match ($field) {
            'summary' => Translator::t('Title'),
            'description' => Translator::t('Description'),
            'start' => Translator::t('Starts'),
            'due' => Translator::t('Due'),
            'priority' => Translator::t('Priority'),
            'percentComplete' => Translator::t('Progress'),
            'completed' => Translator::t('Completed at'),
            'categories' => Translator::t('Categories'),
            default => Translator::t('Title'),
        };
    }
    public function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
    public function strong(string $text): string
    {
        return PlanText::strong($text);
    }
    public function emphasis(string $text): string
    {
        return PlanText::em($text);
    }
}
