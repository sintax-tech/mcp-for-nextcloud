<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\PlanText;

/**
 * Turns the plan of a Files write into the Markdown body the person reads before confirming.
 *
 * Only the body: the title, the warnings and the footer belong to the envelope. The text names the file
 * or folder the way the person knows it, says what changes ("before → after") and repeats the consequences
 * the plan already carries: the backup copy, who else is reached. A plan without the keys a tool needs
 * answers null, so the envelope falls back to its generic body; nothing here throws.
 */
final class FilesPlanRenderer {
    /** Longest excerpt of file content shown, in characters. */
    private const EXCERPT = 200;
    /** Longest path or name shown in bold, in characters: long enough to tell two deep paths apart. */
    private const NAME = 400;

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
                'files_edit', 'files_replace' => self::content($tool, $plan),
                'files_checkout' => self::checkout($plan),
                'files_copy' => self::copy($plan),
                'files_mkdir' => self::mkdir($plan),
                'files_move' => self::move($plan),
                'files_move_batch' => self::batch($plan),
                'files_undo_batch' => self::undo($plan),
                'files_version_restore' => self::restore($plan),
                'files_share' => self::share($plan),
                'files_unshare' => self::unshare($plan),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
        return $lines === null ? null : implode("\n", $lines);
    }

    /** @return list<string>|null */
    private static function content(string $tool, array $plan): ?array {
        $path = self::text($plan, 'path');
        if ($path === null || !isset($plan['diff']) || !is_string($plan['diff'])) {
            return null;
        }
        $lines = [$tool === 'files_edit'
            ? Translator::t('Overwrite the content of %s with the new text.', [self::bold($path)])
            : Translator::t('Replace a passage of %s.', [self::bold($path)]), ''];
        [$removed, $added] = self::changes($plan['diff']);
        if ($removed === [] && $added === []) {
            $lines[] = '- ' . Translator::t('The text does not change.');
        } else {
            $lines[] = '- ' . Translator::t('Lines removed: %s; lines added: %s.', [count($removed), count($added)]);
            if ($removed !== []) {
                $lines[] = '- ' . Translator::t('Before: %s', [self::quote(implode(' ', $removed))]);
            }
            if ($added !== []) {
                $lines[] = '- ' . Translator::t('After: %s', [self::quote(implode(' ', $added))]);
            }
        }
        $size = $plan['size'] ?? null;
        if (is_array($size) && isset($size['before'], $size['after'])) {
            $lines[] = '- ' . Translator::t('Size: %s → %s', [self::bytes((int)$size['before']), self::bytes((int)$size['after'])]);
        }
        return array_merge($lines, self::backup($plan), self::sharing($plan));
    }

    /** @return list<string>|null */
    private static function checkout(array $plan): ?array {
        $path = self::text($plan, 'path');
        if ($path === null) {
            return null;
        }
        $lines = [Translator::t('Prepare %s to be downloaded, changed and sent back.', [self::bold($path)]), ''];
        if (isset($plan['size'])) {
            $lines[] = '- ' . Translator::t('Size: %s', [self::bytes((int)$plan['size'])]);
        }
        if (isset($plan['downloadTtlSeconds'], $plan['uploadTtlSeconds'])) {
            $lines[] = '- ' . Translator::t('The download link works for %s minutes and the upload link for %s minutes.', [
                (int)ceil((int)$plan['downloadTtlSeconds'] / 60),
                (int)ceil((int)$plan['uploadTtlSeconds'] / 60),
            ]);
        }
        if (isset($plan['uploadMaxBytes'])) {
            $lines[] = '- ' . Translator::t('The new version can weigh up to %s.', [self::bytes((int)$plan['uploadMaxBytes'])]);
        }
        $lines[] = '- ' . Translator::t('Nothing is changed until the new version is uploaded.');
        return array_merge($lines, self::backup($plan), self::sharing($plan));
    }

    /** @return list<string>|null */
    private static function copy(array $plan): ?array {
        $from = self::text($plan, 'from');
        $to = self::text($plan, 'to');
        if ($from === null || $to === null) {
            return null;
        }
        $lines = [Translator::t('Copy %s to %s. The original stays where it is.', [self::bold($from), self::bold($to)]), ''];
        if (isset($plan['nodes'])) {
            $lines[] = '- ' . Translator::n('%n item, %s in total.', '%n items, %s in total.', (int)$plan['nodes'], [self::bytes((int)($plan['bytes'] ?? 0))]);
        }
        return array_merge($lines, self::sharing($plan));
    }

    /** @return list<string>|null */
    private static function mkdir(array $plan): ?array {
        $path = self::text($plan, 'path');
        if ($path === null) {
            return null;
        }
        $lines = [Translator::t('Create the folder %s.', [self::bold($path)]), ''];
        $levels = self::strings($plan['created'] ?? []);
        if (count($levels) > 1) {
            $lines[] = '- ' . Translator::t('Folders that will be created: %s', [implode(', ', array_map([self::class, 'bold'], $levels))]);
        }
        if (isset($plan['createdIn']) && is_string($plan['createdIn'])) {
            $lines[] = '- ' . Translator::t('Inside: %s', [self::bold($plan['createdIn'] === '' ? '/' : $plan['createdIn'])]);
        }
        return array_merge($lines, self::sharing($plan));
    }

    /** @return list<string>|null */
    private static function move(array $plan): ?array {
        $from = self::text($plan, 'from');
        $to = self::text($plan, 'to');
        if ($from === null || $to === null) {
            return null;
        }
        $lines = [($plan['isDir'] ?? false) === true
            ? Translator::t('Move the folder %s to %s.', [self::bold($from), self::bold($to)])
            : Translator::t('Move %s to %s.', [self::bold($from), self::bold($to)]), ''];
        if (isset($plan['versions']) && is_int($plan['versions']) && $plan['versions'] > 0) {
            $lines[] = '- ' . Translator::n('Its %n earlier version goes along.', 'Its %n earlier versions go along.', $plan['versions']);
        }
        return array_merge($lines, self::sharing($plan));
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
                $lines[] = '- ' . self::bold((string)$item['from']) . ' → ' . self::bold((string)$item['to']);
            }
        }
        $created = [];
        foreach ((array)($plan['mkdirs'] ?? []) as $dir) {
            if (is_array($dir) && ($dir['willCreate'] ?? false) === true && isset($dir['path'])) {
                $created[] = self::bold((string)$dir['path']);
            }
        }
        if ($created !== []) {
            $lines[] = '';
            $lines[] = Translator::t('Folders that will be created first: %s', [implode(', ', $created)]);
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
                $reason = isset($item['reason']) ? ' — ' . self::excerpt((string)$item['reason']) : '';
                $lines[] = '- ' . self::bold((string)($item['from'] ?? '')) . ' → ' . self::bold((string)($item['to'] ?? '')) . $reason;
            }
        }
        $shared = array_filter((array)($plan['shared'] ?? []), 'is_array');
        if ($shared !== []) {
            $lines[] = '';
            $lines[] = Translator::t('These items belong to someone else or are shared, so moving them also needs the shared-content confirmation:');
            foreach ($shared as $item) {
                $lines[] = '- ' . self::bold((string)($item['from'] ?? '')) . ' → ' . self::bold((string)($item['to'] ?? ''));
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
                $lines[] = '- ' . self::bold((string)$item['to']) . ' → ' . self::bold((string)$item['from']);
            }
        }
        $removed = self::strings($plan['removed_dirs'] ?? []);
        if ($removed !== []) {
            $lines[] = '';
            $lines[] = Translator::t('Empty folders the batch created will be removed: %s', [implode(', ', array_map([self::class, 'bold'], $removed))]);
        }
        $conflicts = array_filter((array)($plan['conflicts'] ?? []), 'is_array');
        if ($conflicts !== []) {
            $lines[] = '';
            $lines[] = Translator::t('The undo is blocked because these items changed since the batch:');
            foreach ($conflicts as $item) {
                $reason = isset($item['reason']) ? ' — ' . self::excerpt((string)$item['reason']) : '';
                $lines[] = '- ' . self::bold((string)($item['to'] ?? $item['from'] ?? '')) . $reason;
            }
        }
        return $lines;
    }

    /** @return list<string>|null */
    private static function restore(array $plan): ?array {
        $path = self::text($plan, 'path');
        $version = $plan['version'] ?? null;
        if ($path === null || !is_array($version) || !isset($version['timestamp'])) {
            return null;
        }
        $when = self::moment((string)$version['timestamp'], self::text($plan, 'timezone'));
        $lines = [Translator::t('Bring %s back to the version of %s.', [self::bold($path), $when]), ''];
        if (isset($version['size'])) {
            $current = is_array($plan['current'] ?? null) && isset($plan['current']['size']) ? (int)$plan['current']['size'] : null;
            $lines[] = '- ' . ($current === null
                ? Translator::t('Size: %s', [self::bytes((int)$version['size'])])
                : Translator::t('Size: %s → %s', [self::bytes($current), self::bytes((int)$version['size'])]));
        }
        $lines[] = '- ' . Translator::t('The current content is replaced by that version.');
        return array_merge($lines, self::backup($plan), self::sharing($plan));
    }

    /**
     * @param array<string, mixed> $plan plan of a tool that copies the file before writing
     * @return list<string> the line naming the backup folder, when the plan carries one
     */
    private static function backup(array $plan): array {
        $backup = self::text($plan, 'backup');
        return $backup === null ? [] : ['- ' . Translator::t('A copy of the current file is saved first in %s.', [self::bold($backup)])];
    }

    /**
     * @param array<string, mixed> $plan plan with an optional `shared` list of access descriptions
     * @return list<string> who else is reached, and the extra confirmation when the plan asks for it
     */
    private static function sharing(array $plan): array {
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
     * The plan of files_share: who receives what, before → after on an update, and what the person must know. A
     * re-share right the update takes away is said in words, since a share made on the web carries it.
     *
     * @return list<string>|null
     */
    private static function share(array $plan): ?array {
        $path = self::text($plan, 'path');
        $with = is_array($plan['with'] ?? null) ? $plan['with'] : [];
        $name = self::text($with, 'displayName');
        $after = is_array($plan['after'] ?? null) ? $plan['after'] : null;
        $before = is_array($plan['before'] ?? null) ? $plan['before'] : null;
        $action = $plan['action'] ?? null;
        if ($path === null || $name === null || $after === null || !in_array($action, ['create', 'update', 'none'], true)
            || ($action !== 'create' && $before === null)) {
            return null;
        }
        $isDir = ($plan['isDir'] ?? false) === true;
        if (($with['type'] ?? null) === 'link') {
            return self::link($plan, $path, $action, $before, $after, $isDir);
        }
        $who = ($with['type'] ?? null) === 'group' ? Translator::t('the group %s', [self::bold($name)]) : self::bold($name);
        $lines = [match ($action) {
            'create' => Translator::t('Share %s with %s.', [self::bold($path), $who]),
            'update' => Translator::t('Change how %s is shared with %s.', [self::bold($path), $who]),
            'none' => Translator::t('%s is already shared with %s exactly like this: nothing to change.', [self::bold($path), $who]),
        }, ''];
        $access = self::access($after['permission'] ?? null, $isDir);
        if ($action === 'update' && ($before['permission'] ?? null) !== ($after['permission'] ?? null)) {
            $lines[] = '- ' . Translator::t('Access: %s → %s', [self::access($before['permission'] ?? null, $isDir), $access]);
        } else {
            $lines[] = '- ' . Translator::t('Access: %s', [$access]);
        }
        if ($action === 'update' && ($before['reshare'] ?? false) === true) {
            $lines[] = '- ' . Translator::t('Passing it on: %s can share it with other people today and will no longer be able to.', [self::bold($name)]);
        }
        $lines[] = self::validityLine($plan, $action, $before, $after);
        array_push($lines, ...self::noteLines($action, $before, $after));
        if ($action === 'create') {
            $lines[] = '- ' . (($with['type'] ?? null) === 'group'
                ? Translator::t('Nextcloud notifies the members of %s.', [self::bold($name)])
                : Translator::t('Nextcloud notifies %s.', [self::bold($name)]));
        }
        return $lines;
    }

    /**
     * The plan of a public link: what anyone with it can do, until when, and what happens to the password. The plan
     * never holds a password, only whether one will be generated; the warning that anyone can open it is in the
     * envelope with the other warnings.
     *
     * @param array<string, mixed> $plan the plan
     * @param string $path path of the node, as the plan carries it
     * @param string $action create, update or none
     * @param array<string, mixed>|null $before the link as it is, null for a new one
     * @param array<string, mixed> $after the link as it will be
     * @param bool $isDir whether the node is a folder
     * @return list<string>
     */
    private static function link(array $plan, string $path, string $action, ?array $before, array $after, bool $isDir): array {
        $lines = [match ($action) {
            'create' => Translator::t('Create a public link to %s.', [self::bold($path)]),
            'update' => Translator::t('Change the public link of %s.', [self::bold($path)]),
            'none' => Translator::t('%s already has a public link exactly like this: nothing to change.', [self::bold($path)]),
        }, ''];
        $access = self::access($after['permission'] ?? null, $isDir);
        $lines[] = '- ' . ($action === 'update' && ($before['permission'] ?? null) !== ($after['permission'] ?? null)
            ? Translator::t('Access: %s → %s', [self::access($before['permission'] ?? null, $isDir), $access])
            : Translator::t('Access: %s', [$access]));
        $lines[] = self::validityLine($plan, $action, $before, $after);
        $password = is_array($plan['password'] ?? null) ? $plan['password'] : [];
        $lines[] = '- ' . match (true) {
            ($password['after'] ?? null) === 'new' && ($password['before'] ?? null) === true
                => Translator::t('Password: the current one will be replaced by a new one, shown only once, in the result.'),
            ($password['after'] ?? null) === 'new' && ($password['required'] ?? false) === true
                => Translator::t('Password: required by the administrator; a new one will be generated and shown only once, in the result.'),
            ($password['after'] ?? null) === 'new' => Translator::t('Password: a new one will be generated and shown only once, in the result.'),
            ($password['after'] ?? null) === 'kept' => Translator::t('Password: unchanged; it is never shown.'),
            default => Translator::t('Password: none.'),
        };
        array_push($lines, ...self::noteLines($action, $before, $after));
        return $lines;
    }

    /**
     * @param array<string, mixed> $plan the plan, for `expiresSource`
     * @param string $action create, update or none
     * @param array<string, mixed>|null $before the share as it is
     * @param array<string, mixed> $after the share as it will be
     * @return string the line with the last day of the share, before → after on a change
     */
    private static function validityLine(array $plan, string $action, ?array $before, array $after): string {
        $until = self::until($after['expires'] ?? null);
        if ($action === 'update' && ($before['expires'] ?? null) !== ($after['expires'] ?? null)) {
            return '- ' . Translator::t('Valid until: %s → %s.', [self::until($before['expires'] ?? null), $until]);
        }
        if ($action === 'create' && ($plan['expiresSource'] ?? null) === 'default') {
            return '- ' . Translator::t('Valid until: %s (the administrator\'s default).', [$until]);
        }
        return '- ' . Translator::t('Valid until: %s.', [$until]);
    }

    /**
     * @param string $action create, update or none
     * @param array<string, mixed>|null $before the share as it is
     * @param array<string, mixed> $after the share as it will be
     * @return list<string> the note for the recipient on a new share, or its change on an update
     */
    private static function noteLines(string $action, ?array $before, array $after): array {
        $note = is_string($after['note'] ?? null) ? $after['note'] : '';
        $old = is_string($before['note'] ?? null) ? $before['note'] : '';
        if ($action === 'create' && $note !== '') {
            return ['- ' . Translator::t('Note for the recipient: %s', [self::quote($note)])];
        }
        if ($action === 'update' && $note !== $old) {
            return ['- ' . Translator::t('Note for the recipient: %s → %s', [
                $old === '' ? Translator::t('none') : self::quote($old),
                $note === '' ? Translator::t('none') : self::quote($note),
            ])];
        }
        return [];
    }

    /**
     * The plan of files_unshare: who loses access to what, in words by kind of recipient, and that the file stays.
     *
     * @return list<string>|null
     */
    private static function unshare(array $plan): ?array {
        $path = self::text($plan, 'path');
        $with = is_array($plan['with'] ?? null) ? $plan['with'] : [];
        $name = self::text($with, 'displayName');
        if ($path === null || $name === null) {
            return null;
        }
        $what = self::bold($path);
        $who = self::bold($name);
        return [match ($with['type'] ?? null) {
            'link' => Translator::t('The link to %s will stop working; anyone who has the link loses access.', [$what]),
            'group' => Translator::t('Every member of the group %s will lose access to %s.', [$who, $what]),
            default => Translator::t('%s will no longer have access to %s.', [$who, $what]),
        }, '', '- ' . (($plan['isDir'] ?? false) === true
            ? Translator::t('Nothing is deleted: the folder and everything in it stay exactly as they are.')
            : Translator::t('Nothing is deleted: the file stays exactly as it is.'))];
    }

    /** @return string what a share level lets the recipient do, in words */
    private static function access(mixed $level, bool $isDir): string {
        return match ($level) {
            'view' => Translator::t('can view and download'),
            'edit' => $isDir ? Translator::t('can view, add, edit and delete inside it') : Translator::t('can view and edit'),
            'custom' => Translator::t('custom access set elsewhere'),
            default => PlanText::inline(is_scalar($level) ? (string)$level : ''),
        };
    }

    /** @return string the last day of a share as the person reads it, or that there is none */
    private static function until(mixed $date): string {
        if (!is_string($date) || $date === '') {
            return Translator::t('no end date');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed === false || $parsed->format('Y-m-d') !== $date ? PlanText::inline($date) : $parsed->format(Translator::t('m/d/Y'));
    }

    /**
     * @param string $diff unified diff
     * @return array{list<string>, list<string>} removed and added lines, without the markers
     */
    private static function changes(string $diff): array {
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
    private static function moment(string $iso, ?string $timezone): string {
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
    private static function excerpt(string $text): string {
        return PlanText::inline($text, self::EXCERPT);
    }

    private static function quote(string $text): string {
        return '«' . self::excerpt($text) . '»';
    }

    /** @return string a file, folder or person name in bold, inert as Markdown */
    private static function bold(string $text): string {
        return PlanText::strong($text, self::NAME);
    }

    private static function bytes(int $bytes): string {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return ($i === 0 ? (string)$bytes : rtrim(rtrim(number_format($bytes / 1024 ** $i, 1, '.', ''), '0'), '.')) . ' ' . $unit;
            }
        }
        return $bytes . ' B';
    }

    /** @return string|null the non-empty string under $key */
    private static function text(array $plan, string $key): ?string {
        return isset($plan[$key]) && is_string($plan[$key]) && $plan[$key] !== '' ? $plan[$key] : null;
    }

    /** @return list<string> the string members of a list */
    private static function strings(mixed $value): array {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
