<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Presentation;

final class ContactsPlanRenderer
{
    public function render(string $tool, array $plan): ?string
    {
        $text = new ContactsPlanText();
        $book = $text->name($plan['addressbook'] ?? null);
        if ($book === null) {
            return null;
        }
        $body = match ($tool) {
            'contacts_create_contact' => (new ContactSaveRenderer($text))->create($book, $plan),
            'contacts_edit_contact' => (new ContactSaveRenderer($text))->edit($book, $plan),
            'contacts_delete_contact' => (new ContactDeletionRenderer($text))->delete($book, $plan),
            default => null,
        };
        if ($body === null) {
            return null;
        }
        return $text->shared($body, $plan, $book);
    }
}
