<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\L10n\Translator;
final class ContactSaveRenderer
{
    public function __construct(private ContactsPlanText $text)
    {
    }
    public function create(string $book, array $plan): ?string
    {
        $after = $this->text->card($plan['after'] ?? null);
        $name = $after === null ? '' : $this->text->text($after['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $lines = [Translator::t('Creating the contact %s in the address book %s.', [$this->text->strong($name), $this->text->emphasis($book)])];
        foreach (ContactsPlanText::FIELDS as $field) {
            if ($field === 'name') {
                continue;
            }
            $value = $this->text->value($after[$field] ?? null, in_array($field, ContactsPlanText::LISTS, true));
            if ($value !== null) {
                $lines[] = '- ' . $this->text->label($field) . ': ' . $value;
            }
        }
        return implode("\n", $lines);
    }
    public function edit(string $book, array $plan): ?string
    {
        $before = $this->text->card($plan['before'] ?? null);
        $after = $this->text->card($plan['after'] ?? null);
        if ($before === null || $after === null) {
            return null;
        }
        $name = $this->text->text($before['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $lines = [
            Translator::t(
                'Editing the contact %s in the address book %s.',
                [$this->text->strong($name), $this->text->emphasis($book)]
            ),
        ];
        foreach (ContactsPlanText::FIELDS as $field) {
            $from = $this->text->value($before[$field] ?? null, in_array($field, ContactsPlanText::LISTS, true));
            $to = $this->text->value($after[$field] ?? null, in_array($field, ContactsPlanText::LISTS, true));
            if ($from === $to) {
                continue;
            }
            $lines[] = '- ' . $this->text->label($field) . ': ' . ($from ?? Translator::t('empty')) . ' → ' . ($to ?? Translator::t('empty'));
        }
        return implode("\n", $lines);
    }
}
