<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Integration;

use OCA\Mcp\Platform\Meetings\LocalInstanceLink;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;

/**
 * Participants and explicit links of one event the reader is allowed to show in full.
 *
 * Addresses and URLs are copied as text. Nothing is fetched, and a name is not an account id.
 */
final class MeetingEventFacts {
	/** Most participants copied from one event (D3). */
	public const MAX_PARTICIPANTS = 10;

	/** Most links copied from one event. */
	public const MAX_LINKS = 40;

	/**
	 * @param LocalInstanceLink $local the same tokenizer the dossier uses for a card or a file link
	 */
	public function __construct(private LocalInstanceLink $local) {
	}

	/**
	 * @param VEvent $event the occurrence the reader classified as full
	 * @return array{attendees: list<array{email: string, name?: string}>, attendeesTruncated: bool, links: list<string>, linksTruncated: bool}
	 */
	public function of(VEvent $event): array {
		$people = $this->people($event);
		$links = $this->links($event);
		return [
			'attendees' => array_slice($people, 0, self::MAX_PARTICIPANTS),
			'attendeesTruncated' => count($people) > self::MAX_PARTICIPANTS,
			'links' => array_slice($links, 0, self::MAX_LINKS),
			'linksTruncated' => count($links) > self::MAX_LINKS,
		];
	}

	/**
	 * @param VEvent $event the occurrence
	 * @return list<array{email: string, name?: string}> organizer first, then attendees, one row per address
	 */
	private function people(VEvent $event): array {
		$rows = [];
		$seen = [];
		foreach ([...$event->select('ORGANIZER'), ...$event->select('ATTENDEE')] as $property) {
			$row = $this->person($property);
			if ($row === null || isset($seen[$row['email']])) {
				continue;
			}
			$seen[$row['email']] = true;
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @param mixed $property one ORGANIZER or ATTENDEE
	 * @return array{email: string, name?: string}|null the address, with CN when the property carries one
	 */
	private function person(mixed $property): ?array {
		if (!$property instanceof Property) {
			return null;
		}
		$email = $this->email((string)$property->getValue());
		if ($email === null) {
			return null;
		}
		$row = ['email' => $email];
		$name = trim((string)($property['CN'] ?? ''));
		if ($name !== '') {
			$row['name'] = $name;
		}
		return $row;
	}

	/**
	 * @param string $raw a property value, often a mailto URI
	 * @return string|null the address in lower case
	 */
	private function email(string $raw): ?string {
		$value = trim($raw);
		if (str_starts_with(strtolower($value), 'mailto:')) {
			$value = substr($value, 7);
		}
		$email = strtolower(trim($value, "<> \t"));
		if ($email === '' || str_contains($email, ' ') || !str_contains($email, '@')) {
			return null;
		}
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
	}

	/**
	 * @param VEvent $event the occurrence
	 * @return list<string> URL, a URI attachment, and URLs written in the description or the location
	 */
	private function links(VEvent $event): array {
		$found = [];
		foreach ($event->select('URL') as $url) {
			$this->collect((string)$url->getValue(), $found);
		}
		foreach ($event->select('ATTACH') as $attach) {
			if (!$attach instanceof Property || $this->binary($attach)) {
				continue;
			}
			$this->collect((string)$attach->getValue(), $found);
		}
		foreach (['DESCRIPTION', 'LOCATION'] as $name) {
			$this->collect((string)($event->$name ?? ''), $found);
		}
		return $found;
	}

	/**
	 * @param Property $attach an ATTACH property
	 * @return bool whether the value is inline content rather than a URI
	 */
	private function binary(Property $attach): bool {
		$value = strtoupper((string)($attach['VALUE'] ?? ''));
		$encoding = strtoupper((string)($attach['ENCODING'] ?? ''));
		$raw = strtolower(trim((string)$attach->getValue()));
		return $value === 'BINARY' || $encoding === 'BASE64' || str_starts_with($raw, 'data:');
	}

	/**
	 * @param string $text one property or a description
	 * @param list<string> $found links kept so far
	 */
	private function collect(string $text, array &$found): void {
		foreach ($this->local->references($text) as $token) {
			if (!in_array($token, $found, true)) {
				$found[] = $token;
			}
		}
	}
}
