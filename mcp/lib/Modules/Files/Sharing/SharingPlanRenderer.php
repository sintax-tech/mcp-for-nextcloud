<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;
use OCA\Mcp\Mcp\Confirmation\PlanText;

final class SharingPlanRenderer {
    public static function render(string $tool, array $plan): ?string {
        try {
            $lines = match ($tool) {
                'files_share' => self::share($plan),
                'files_unshare' => self::unshare($plan),
                default => null,
            };
            return $lines === null ? null : implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The plan of files_share: who receives what, before → after on an update, and what the person must know. A
     * re-share right the update takes away is said in words, since a share made on the web carries it.
     *
     * @return list<string>|null
     */
    private static function share(array $plan): ?array {
        $path = PlanFormatting::text($plan, 'path');
        $with = is_array($plan['with'] ?? null) ? $plan['with'] : [];
        $name = PlanFormatting::text($with, 'displayName');
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
        $who = ($with['type'] ?? null) === 'group' ? Translator::t('the group %s', [PlanFormatting::bold($name)]) : PlanFormatting::bold($name);
        $lines = [match ($action) {
            'create' => Translator::t('Share %s with %s.', [PlanFormatting::bold($path), $who]),
            'update' => Translator::t('Change how %s is shared with %s.', [PlanFormatting::bold($path), $who]),
            'none' => Translator::t('%s is already shared with %s exactly like this: nothing to change.', [PlanFormatting::bold($path), $who]),
        }, ''];
        $access = self::access($after['permission'] ?? null, $isDir);
        if ($action === 'update' && ($before['permission'] ?? null) !== ($after['permission'] ?? null)) {
            $lines[] = '- ' . Translator::t('Access: %s → %s', [self::access($before['permission'] ?? null, $isDir), $access]);
        } else {
            $lines[] = '- ' . Translator::t('Access: %s', [$access]);
        }
        if ($action === 'update' && ($before['reshare'] ?? false) === true) {
            $lines[] = '- ' . Translator::t('Passing it on: %s can share it with other people today and will no longer be able to.', [PlanFormatting::bold($name)]);
        }
        $lines[] = self::validityLine($plan, $action, $before, $after);
        array_push($lines, ...self::noteLines($action, $before, $after));
        if ($action === 'create') {
            $lines[] = '- ' . (($with['type'] ?? null) === 'group'
                ? Translator::t('Nextcloud notifies the members of %s.', [PlanFormatting::bold($name)])
                : Translator::t('Nextcloud notifies %s.', [PlanFormatting::bold($name)]));
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
            'create' => Translator::t('Create a public link to %s.', [PlanFormatting::bold($path)]),
            'update' => Translator::t('Change the public link of %s.', [PlanFormatting::bold($path)]),
            'none' => Translator::t('%s already has a public link exactly like this: nothing to change.', [PlanFormatting::bold($path)]),
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
            return ['- ' . Translator::t('Note for the recipient: %s', [PlanFormatting::quote($note)])];
        }
        if ($action === 'update' && $note !== $old) {
            return ['- ' . Translator::t('Note for the recipient: %s → %s', [
                $old === '' ? Translator::t('none') : PlanFormatting::quote($old),
                $note === '' ? Translator::t('none') : PlanFormatting::quote($note),
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
        $path = PlanFormatting::text($plan, 'path');
        $with = is_array($plan['with'] ?? null) ? $plan['with'] : [];
        $name = PlanFormatting::text($with, 'displayName');
        if ($path === null || $name === null) {
            return null;
        }
        $what = PlanFormatting::bold($path);
        $who = PlanFormatting::bold($name);
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
}
