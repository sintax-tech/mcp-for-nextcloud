<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Throwable;

/** Patches the parsed card, preserving untouched properties, parameters, groups and version. */
class ContactCard {
    private const FIELDS = [
        'name' => 'FN',
        'organization' => 'ORG',
        'title' => 'TITLE',
        'note' => 'NOTE',
        'url' => 'URL',
    ];

    /**
     * Reads a vCard with the identity properties required for safe contact operations.
     *
     * @param string $data complete serialized DAV object
     * @return VCard
     * @throws ToolFailure when the vCard is malformed or lacks required identity properties
     */
    public function parse(string $data): VCard {
        try {
            $card = Reader::read($data);
        } catch (Throwable $error) {
            throw new ToolFailure(Translator::t('The contact could not be read safely.'), 0, $error);
        }
        if (!$card instanceof VCard || !isset($card->UID) || !isset($card->FN)) {
            throw new ToolFailure(Translator::t('The contact could not be read safely.'));
        }
        return $card;
    }

    /**
     * Creates a vCard with a new UID and applies the supplied contact fields.
     *
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @return VCard
     */
    public function create(array $arguments): VCard {
        $card = new VCard(
            [
                'VERSION' => '3.0',
                'UID' => bin2hex(random_bytes(16)),
                'FN' => $arguments['name'],
            ]
        );
        return $this->patch($card, $arguments);
    }

    /**
     * Clones a contact and edits supplied fields without discarding unrelated properties.
     *
     * @param VCard $original original object, which remains unchanged
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @return VCard
     */
    public function patch(VCard $original, array $arguments): VCard {
        $card = clone $original;
        foreach (self::FIELDS as $argument => $property) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            // Modifying the first property preserves its parameters/group; other occurrences stay intact.
            if ($property === 'ORG' && isset($card->ORG)) {
                $parts = $card->ORG->getParts();
                $parts[0] = $arguments[$argument];
                $card->ORG->setParts($parts);
            } elseif (isset($card->{$property})) {
                $card->{$property}->setValue($arguments[$argument]);
            } else {
                $card->add($property, $arguments[$argument]);
            }
        }
        foreach (['emails' => 'EMAIL', 'phones' => 'TEL'] as $argument => $property) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            $existingProperties = array_values($card->select($property));
            foreach ($arguments[$argument] as $index => $value) {
                if (isset($existingProperties[$index])) {
                    $existingProperties[$index]->setValue($value);
                } else {
                    $card->add($property, $value);
                }
            }
            // Array replaces only this understood property; labels/custom properties are retained.
            foreach (array_slice($existingProperties, count($arguments[$argument])) as $removed) {
                $card->remove($removed);
            }
        }
        return $card;
    }

    /**
     * Returns understood fields, every original property and the complete serialized vCard.
     *
     * @param VCard $card parsed contact card
     * @return array<string, mixed>
     */
    public function item(VCard $card): array {
        $item = ['uid' => (string) $card->UID];
        foreach (self::FIELDS as $argument => $property) {
            $item[$argument] = isset($card->{$property}) ? (string) $card->{$property} : '';
        }
        if (isset($card->ORG)) {
            $item['organization'] = $card->ORG->getParts()[0] ?? '';
        }
        foreach (['emails' => 'EMAIL', 'phones' => 'TEL'] as $argument => $property) {
            $item[$argument] = array_values(array_map(static fn ($property) => (string) $property, $card->select($property)));
        }
        $item['properties'] = [];
        foreach ($card->children() as $property) {
            $item['properties'][] = [
                'name' => $property->name,
                'group' => $property->group,
                'value' => $property->getValue(),
                'serialized' => $property->serialize(),
            ];
        }
        $item['vcard'] = $card->serialize();
        return $item;
    }
}
