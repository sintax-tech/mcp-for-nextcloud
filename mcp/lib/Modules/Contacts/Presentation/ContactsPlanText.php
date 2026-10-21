<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class ContactsPlanText
{
    public const EXCERPT = 200;
    public const FIELDS = [
        'name',
        'organization',
        'title',
        'emails',
        'phones',
        'url',
        'note',
    ];
    public const LISTS = ['emails', 'phones'];
    public function shared(
        string $body,
        array $plan,
        string $book,
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
            $resource = $this->text($notice['resource'] ?? '') ?: $book;
            return $body . "\n" . Translator::t(
                'The address book %s belongs to %s and is shared with you, so the change affects other people.',
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
    public function card(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
    public function value(mixed $value, bool $list): ?string
    {
        if ($list) {
            if (!is_array($value)) {
                return null;
            }
            $values = array_values(
                array_filter(
                    array_map(fn(mixed $item): string => $this->text($item), $value),
                    static fn(string $item): bool => $item !== ''
                )
            );
            return $values === [] ? null : PlanText::inline(implode(', ', $values), 300);
        }
        $text = $this->text($value);
        return $text === '' ? null : PlanText::inline($text, self::EXCERPT);
    }
    public function label(string $field): string
    {
        // One literal per call: the translation tool extracts the source texts from here.
        return match ($field) {
            'name' => Translator::t('Name'),
            'organization' => Translator::t('Organization'),
            'title' => Translator::t('Job title'),
            'emails' => Translator::t('E-mails'),
            'phones' => Translator::t('Phones'),
            'url' => Translator::t('Website'),
            'note' => Translator::t('Note'),
            default => Translator::t('Name'),
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
