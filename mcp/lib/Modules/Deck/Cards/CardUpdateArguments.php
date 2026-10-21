<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Deck\Db\Card;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\Service\CardService;

class CardUpdateArguments {
    /**
     * The current values of the card fields a Deck release appended to `CardService::update()` after `done`.
     *
     * Deck 1.18 (Nextcloud 34) added `?string $startdate` and `?string $color` and writes both on every update, so
     * leaving them out clears them; Deck 1.19 (Nextcloud 35) made `color` an `OptionalNullableValue`, null leaving it
     * alone, but still clears a start date left out. Up to 1.17 the signature ends at `done` and the card has neither
     * field, so nothing is sent. The signature is read from the method itself: named arguments would fail on the
     * releases without these parameters. Reading stops at a parameter this class does not know, which keeps its default.
     *
     * @param object $cardService OCA\Deck\Service\CardService
     * @param Card $card Card being updated, as Deck loaded it
     * @return list<mixed> arguments for the parameters after `done`, in order
     */
    public static function fieldsAfterDone(object $cardService, Card $card): array {
        $parameters = (new \ReflectionMethod($cardService, 'update'))->getParameters();
        $names = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);
        $after = array_slice($parameters, (int) array_search('done', $names, true) + 1);
        $arguments = [];
        foreach ($after as $parameter) {
            if ($parameter->getName() === 'startdate') {
                $startdate = $card->getStartdate();
                // Deck reads it back with `new \DateTime($startdate)`; ATOM keeps the instant and its offset.
                $arguments[] = $startdate instanceof \DateTimeInterface ? $startdate->format(DATE_ATOM) : $startdate;
            } elseif ($parameter->getName() === 'color') {
                $type = $parameter->getType();
                $arguments[] = $type instanceof \ReflectionNamedType && $type->getName() === OptionalNullableValue::class ? new OptionalNullableValue($card->getColor()) : $card->getColor();
            } else {
                break;
            }
        }
        return $arguments;
    }
}
