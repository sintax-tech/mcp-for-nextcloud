<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class ContactDeletionRenderer
{
    public function __construct(private ContactsPlanText $text)
    {
    }
    public function delete(string $book, array $plan): ?string
    {
        $before = $this->text->card($plan['before'] ?? null);
        $name = $before === null ? '' : $this->text->text($before['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $lines = [
            Translator::t(
                'Deleting the contact %s from the address book %s.',
                [$this->text->strong($name), $this->text->emphasis($book)]
            ),
        ];
        $warning = $this->text->text($plan['warning'] ?? '');
        if ($warning !== '') {
            $lines[] = PlanText::inline($warning, 300);
        }
        $backup = is_array($plan['backup'] ?? null) ? $plan['backup'] : [];
        $directory = $this->text->text($backup['directory'] ?? '');
        $format = $this->text->text($backup['format'] ?? '');
        if ($directory !== '') {
            $lines[] = Translator::t(
                'A copy of the card (%s) is saved in %s before the deletion; if that copy fails, nothing is deleted.',
                ['.' . PlanText::inline($format === '' ? 'vcf' : ltrim($format, '.'), 20), $this->text->emphasis($directory)]
            );
        } else {
            $message = $this->text->text($backup['message'] ?? '');
            if ($message !== '') {
                $lines[] = PlanText::inline($message, 300);
            }
        }
        $message = $this->text->text($backup['message'] ?? '');
        if ($directory !== '' && $message !== '') {
            $lines[] = Translator::t('The card stays there: import the file through Contacts to bring the contact back.');
        }
        return implode("\n", $lines);
    }
}
