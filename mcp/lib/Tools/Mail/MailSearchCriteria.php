<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Tools\ToolFailure;

/**
 * The filters of one message search, validated, and their translation into the search query of the Mail app.
 *
 * Every filter except `body` is answered by the message cache of the Mail app. Within one address field the fragments
 * are alternatives, as the Mail mapper ORs them; different fields, the dates and the flags must all match. `body` is the Mail app's own body
 * search, an IMAP SEARCH TEXT on each folder searched, which reads and changes no flag (RFC 3501 §6.4.4). When both
 * `subject` and `body` are given, the Mail cache matches either of them, as the Mail app's own search does.
 * Dates are inclusive and compared with the date the Mail app stores for a message, which is the date the mail server
 * received it (IMAP INTERNALDATE, `IMAPMessage::getSentDate()`), not the Date header; a date without a time covers the
 * whole day in the user's time zone.
 */
final class MailSearchCriteria {
	/** Longest text filter accepted, in characters. */
	public const MAX_TEXT = 256;

	/** Most addresses accepted per address filter. */
	public const MAX_ADDRESSES = 10;

	/**
	 * Holds validated filters.
	 *
	 * @param list<string> $from sender address or name fragments, any of which matches
	 * @param list<string> $to recipient fragments, any of which matches
	 * @param list<string> $cc copy recipient fragments, any of which matches
	 * @param string|null $subject subject fragment
	 * @param string|null $body body text, searched on the mail server
	 * @param int|null $since Unix time of the oldest stored date, inclusive
	 * @param int|null $until Unix time of the newest stored date, inclusive
	 * @param bool|null $unread true for unread messages only, false for read ones only
	 * @param bool|null $flagged true for flagged messages only, false for unflagged ones only
	 * @param bool $withAttachments true for messages the Mail app found attachments in
	 * @return void
	 */
	public function __construct(
		public readonly array $from = [],
		public readonly array $to = [],
		public readonly array $cc = [],
		public readonly ?string $subject = null,
		public readonly ?string $body = null,
		public readonly ?int $since = null,
		public readonly ?int $until = null,
		public readonly ?bool $unread = null,
		public readonly ?bool $flagged = null,
		public readonly bool $withAttachments = false,
	) {
	}

	/**
	 * Validates the filter arguments of mail_search_messages.
	 *
	 * @param array<string, mixed> $arguments arguments already checked against the tool schema
	 * @param DateTimeZone $zone time zone of the user, for dates without a time
	 * @return self the filters
	 * @throws ToolFailure when a value cannot be used
	 */
	public static function fromArguments(array $arguments, DateTimeZone $zone): self {
		$since = isset($arguments['since']) ? self::instant((string)$arguments['since'], $zone, false) : null;
		$until = isset($arguments['until']) ? self::instant((string)$arguments['until'], $zone, true) : null;
		if ($since !== null && $until !== null && $since > $until) {
			throw new ToolFailure(MailMessages::invalidDateRange());
		}
		return new self(
			self::texts($arguments['from'] ?? []),
			self::texts($arguments['to'] ?? []),
			self::texts($arguments['cc'] ?? []),
			self::optionalText($arguments['subject'] ?? null),
			self::optionalText($arguments['body'] ?? null),
			$since,
			$until,
			isset($arguments['unread']) ? (bool)$arguments['unread'] : null,
			isset($arguments['flagged']) ? (bool)$arguments['flagged'] : null,
			($arguments['has_attachments'] ?? false) === true,
		);
	}

	/**
	 * Whether the body search of the mail server is needed.
	 *
	 * @return bool true when a body filter is set
	 */
	public function searchesBody(): bool {
		return $this->body !== null;
	}

	/**
	 * A stable fingerprint of the filters, part of the cursor fingerprint.
	 *
	 * @return array<string, mixed> the filters in a fixed order
	 */
	public function fingerprintData(): array {
		return [$this->from, $this->to, $this->cc, $this->subject, $this->body, $this->since, $this->until, $this->unread, $this->flagged, $this->withAttachments];
	}

	/**
	 * A new search query of the Mail app with these filters, for one folder.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param string $userId authenticated user UID
	 * @param bool $isTrash whether the folder is the trash, where deleted messages are kept in the results
	 * @param int|null $since lower bound replacing the filter, for the same-date page boundary
	 * @param int|null $until upper bound replacing the filter, for the same-date page boundary
	 * @param int|null $before cursor: only messages sent strictly before this Unix time
	 * @return object OCA\Mail\Service\Search\SearchQuery
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function toQuery(MailServices $services, string $userId, bool $isTrash, ?int $since = null, ?int $until = null, ?int $before = null): object {
		$query = $services->newSearchQuery($userId);
		foreach ($this->from as $value) {
			$query->addFrom($value);
		}
		foreach ($this->to as $value) {
			$query->addTo($value);
		}
		foreach ($this->cc as $value) {
			$query->addCc($value);
		}
		if ($this->subject !== null) {
			$query->addSubject($this->subject);
		}
		if ($this->body !== null) {
			$query->addBody($this->body);
		}
		$start = $since ?? $this->since;
		$end = $until ?? $this->until;
		if ($start !== null) {
			$query->setStart((string)$start);
		}
		if ($end !== null) {
			$query->setEnd((string)$end);
		}
		if ($before !== null) {
			$query->setCursor($before);
		}
		if ($this->withAttachments) {
			$query->setHasAttachments(true);
		}
		if ($this->unread !== null) {
			$query->addFlag($services->flag($userId, 'SEEN', !$this->unread));
		}
		if ($this->flagged !== null) {
			$query->addFlag($services->flag($userId, 'FLAGGED', $this->flagged));
		}
		// As in the Mail app: messages marked deleted are only listed in the trash.
		if (!$isTrash) {
			$query->addFlag($services->flag($userId, 'DELETED', false));
		}
		// Mail 5.5 and later group the folder query by thread unless told otherwise, which would list only the newest
		// message of each conversation; every message is wanted. Mail 5.0.0 has no thread grouping in
		// MessageMapper::findIdsByQuery(), so there is nothing to turn off there.
		if (method_exists($query, 'setThreaded')) {
			$query->setThreaded(false);
		}
		return $query;
	}

	/**
	 * A list of cleaned text filters.
	 *
	 * @param mixed $values strings from the schema
	 * @return list<string> the non-empty values
	 * @throws ToolFailure when a value is empty after cleaning
	 */
	private static function texts(mixed $values): array {
		$out = [];
		foreach (is_array($values) ? $values : [] as $value) {
			$out[] = self::requiredText((string)$value);
		}
		return $out;
	}

	/**
	 * A cleaned optional text filter.
	 *
	 * @param mixed $value string from the schema or null
	 * @return string|null the value, or null when absent
	 * @throws ToolFailure when the value is empty after cleaning
	 */
	private static function optionalText(mixed $value): ?string {
		return $value === null ? null : self::requiredText((string)$value);
	}

	/**
	 * A text filter without control characters and surrounding blanks, not empty.
	 *
	 * @param string $value raw filter
	 * @return string the cleaned filter
	 * @throws ToolFailure when nothing is left
	 */
	private static function requiredText(string $value): string {
		$clean = trim((string)preg_replace('/\p{Cc}/u', ' ', mb_scrub($value, 'UTF-8')));
		if ($clean === '') {
			throw new ToolFailure(MailMessages::emptyFilter());
		}
		return mb_substr($clean, 0, self::MAX_TEXT);
	}

	/**
	 * The Unix time of a date filter.
	 *
	 * @param string $value YYYY-MM-DD, or an ISO 8601 date and time with an offset
	 * @param DateTimeZone $zone time zone of the user, for a date without a time
	 * @param bool $endOfDay true to take the last second of a date without a time
	 * @return int the Unix time
	 * @throws ToolFailure when the value is no such date
	 */
	private static function instant(string $value, DateTimeZone $zone, bool $endOfDay): int {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
			if ($date !== false && $date->format('Y-m-d') === $value) {
				return $endOfDay ? $date->modify('+1 day')->getTimestamp() - 1 : $date->getTimestamp();
			}
		} elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value) === 1) {
			$date = date_create_immutable($value);
			if ($date !== false) {
				return $date->getTimestamp();
			}
		}
		throw new ToolFailure(MailMessages::invalidDate($value));
	}
}
