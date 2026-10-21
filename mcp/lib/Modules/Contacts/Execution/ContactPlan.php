<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Execution;

use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCard;
final class ContactPlan
{
    public function __construct(private SharedGuard $guard, private ContactCard $cards)
    {
    }
    public function build(
        string $name,
        Calendar $book,
        ?array $row,
        ?array $before,
        ?VCard $card,
        string $userId,
    ): array
    {
        $notice = $this->guard->forCollection($book, $userId);
        $shared = $notice === null ? [] : [$notice];
        $plan = [
            'requiresConfirmation' => true,
            'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
            'action' => $name,
            'addressbook' => (array) $book,
            'etag' => $row['etag'] ?? null,
            'before' => $before,
            'after' => $card === null ? null : $this->cards->item($card),
            'shared' => $shared,
            'recoverable' => false,
            'consequence' => Translator::t('Contacts have no native trash or edit history; review the changes carefully.'),
        ];
        return $plan;
    }
    public function assertAcknowledged(array $plan, array $arguments): void
    {
        if ($plan['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure(Translator::t('Confirm the change to the shared collection with confirm_shared=true.'));
        }
    }
}
