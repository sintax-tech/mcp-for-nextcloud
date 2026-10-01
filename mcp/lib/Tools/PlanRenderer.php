<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use OCA\Mcp\L10n\Translator;

/** Common confirmation envelope, with an optional module body and a bounded generic fallback. */
final class PlanRenderer {
    /** Metadata rendered separately from the generic body. */
    private const ENVELOPE_KEYS = ['tool', 'title', 'requiresConfirmation', 'message', 'action', 'etag', 'warnings', 'sharedCalendars', 'suggestedCalendar'];
    /** Text writes show their size rather than disclosing complete contents through a diff or snippet. */
    private const CONTENT_KEYS = ['content', 'old', 'new', 'diff'];

    /**
     * Module renderer failures must never prevent a person from seeing the generic confirmation plan.
     *
     * @param ToolModule $module module serving the tool
     * @param string $tool tool name
     * @param array<string, mixed> $plan complete structured plan, left unchanged
     * @return string Markdown ready to show to the user
     */
    public static function render(ToolModule $module, string $tool, array $plan): string {
        $body = null;
        if ($module instanceof RendersPlans) {
            try {
                $body = $module->renderPlan($tool, $plan);
            } catch (\Throwable) {
                // Rendering is presentation only: a broken custom body falls back without losing the plan.
            }
        }
        $parts = [];
        if (isset($plan['title']) && is_scalar($plan['title'])) {
            $parts[] = '**' . self::text($plan['title']) . '**';
        }
        $body ??= self::fields($plan, $tool);
        if ($body !== '') {
            $parts[] = $body;
        }
        $warnings = [];
        foreach ((array)($plan['warnings'] ?? []) as $warning) {
            if (is_array($warning) && isset($warning['message']) && is_scalar($warning['message'])) {
                $warnings[] = '- ' . self::text($warning['message']);
            }
        }
        if ($warnings !== []) {
            $parts[] = '### ' . Translator::t('Warnings') . "\n\n" . implode("\n", $warnings);
        }
        $calendars = [];
        foreach ((array)($plan['sharedCalendars'] ?? []) as $calendar) {
            // The person recognizes a calendar by its name (and its owner, when it is not theirs); the path is for the model.
            if (is_array($calendar) && isset($calendar['name']) && is_scalar($calendar['name'])) {
                $owner = isset($calendar['owner']) && is_scalar($calendar['owner']) ? trim((string)$calendar['owner']) : '';
                $calendars[] = '- ' . ($owner === ''
                    ? self::text($calendar['name'])
                    : Translator::t('%s, of %s', [self::text($calendar['name']), self::text($owner)]));
            }
        }
        if ($calendars !== []) {
            $parts[] = '### ' . Translator::t('Calendars shared with participants') . "\n\n" . implode("\n", $calendars);
        }
        if (is_array($plan['suggestedCalendar'] ?? null) && isset($plan['suggestedCalendar']['name'])) {
            $parts[] = Translator::t('Suggestion: use %s', ['*' . self::text($plan['suggestedCalendar']['name']) . '*']);
        }
        $footer = Translator::t('Nothing was changed. Confirm to execute.');
        if (isset($plan['message']) && is_scalar($plan['message']) && $plan['message'] !== '') {
            $instruction = trim((string)$plan['message']);
            // The common model advice starts with the same no-change sentence the person just read.
            // Strip only that exact sentence, leaving all remaining confirmation advice intact.
            $firstSentence = explode('.', $footer, 2)[0] . '.';
            if ($instruction === $firstSentence || str_starts_with($instruction, $firstSentence . ' ')
                || str_starts_with($instruction, $firstSentence . "\n")) {
                $instruction = trim(substr($instruction, strlen($firstSentence)));
            }
            if ($instruction !== '') {
                $footer .= "\n\n*" . self::text($instruction, false) . '*';
            }
        }
        $parts[] = $footer;
        return implode("\n\n", $parts);
    }

    /**
     * Arrays become sublists at no more than two nested levels; deeper values are summarized.
     *
     * @param array<array-key, mixed> $fields fields to render
     * @param string $tool tool name, for content and move special cases
     * @param int $depth current sublist depth
     * @return string generic Markdown body
     */
    private static function fields(array $fields, string $tool, int $depth = 0): string {
        $lines = [];
        foreach ($fields as $key => $value) {
            if ($value === null || $value === [] || ($depth === 0 && in_array($key, self::ENVELOPE_KEYS, true))) {
                continue;
            }
            if (in_array($tool, ['files_edit', 'files_replace'], true) && in_array($key, self::CONTENT_KEYS, true)) {
                continue;
            }
            $prefix = str_repeat('  ', $depth) . '- ';
            if ($tool === 'files_move_batch' && $key === 'moves' && is_array($value)) {
                $lines[] = $prefix . self::label('moves') . ':';
                foreach ($value as $move) {
                    if (is_array($move) && isset($move['from'], $move['to'])) {
                        $lines[] = str_repeat('  ', min($depth + 1, 2)) . '- ' . Translator::t('from %s → to %s', [self::text($move['from']), self::text($move['to'])]);
                    }
                }
                continue;
            }
            $label = is_int($key) ? (string)($key + 1) : self::label($key);
            if (!is_array($value)) {
                $lines[] = $prefix . $label . ': ' . self::text($value);
            } elseif (array_is_list($value) && count(array_filter($value, static fn (mixed $item): bool => !is_scalar($item) && $item !== null)) === 0) {
                $items = array_filter($value, static fn (mixed $item): bool => $item !== null);
                if ($items !== []) {
                    $lines[] = $prefix . $label . ': ' . self::text(implode(', ', array_map(self::scalar(...), $items)));
                }
            } else {
                $nested = $depth < 2 ? self::fields($value, $tool, $depth + 1) : '...';
                if ($nested !== '') {
                    $lines[] = $prefix . $label . ':' . ($depth < 2 ? "\n" : ' ') . $nested;
                }
            }
        }
        return implode("\n", $lines);
    }

    /** @param mixed $value plan value @return string translated scalar, with unsupported values omitted */
    private static function scalar(mixed $value): string {
        if (is_bool($value)) {
            return $value ? Translator::t('yes') : Translator::t('no');
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /** @param mixed $value plan text @param bool $truncate bound generic values @return string inert Markdown text */
    private static function text(mixed $value, bool $truncate = true): string {
        $text = preg_replace('/\s+/u', ' ', self::scalar($value)) ?? '';
        if ($truncate && mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300) . '...';
        }
        return str_replace(['\\', '*', '_', '`', '[', ']', '<', '>', '#', '|'], ['\\\\', '\\*', '\\_', '\\`', '\\[', '\\]', '&lt;', '&gt;', '\\#', '\\|'], $text);
    }

    /** @param string $key plan key @return string translated known label or humanized unknown key */
    private static function label(string $key): string {
        return match ($key) {
            'arguments' => Translator::t('Arguments'),
            'destination' => Translator::t('Destination'),
            'path' => Translator::t('Path'),
            'size' => Translator::t('Size (bytes)'),
            'before' => Translator::t('Before'),
            'after' => Translator::t('After'),
            'moves' => Translator::t('Moves'),
            'from' => Translator::t('From'),
            'to' => Translator::t('To'),
            'recoverable' => Translator::t('Recoverable'),
            'shared' => Translator::t('Shared'),
            'owner' => Translator::t('Owner'),
            'backup' => Translator::t('Backup'),
            'consequence' => Translator::t('Consequence'),
            'count' => Translator::t('Count'),
            'name' => Translator::t('Name'),
            'description' => Translator::t('Description'),
            'enabled' => Translator::t('Enabled'),
            'tags' => Translator::t('Tags'),
            default => self::text(ucfirst(strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace(['_', '-'], ' ', $key)) ?? $key))),
        };
    }
}
