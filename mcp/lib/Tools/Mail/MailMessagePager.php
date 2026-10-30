<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Tools\ToolFailure;

/**
 * One page of messages, newest first, over one or more folders, without losing or repeating a message.
 *
 * The Mail cache orders by the date it stores (the server's arrival date) only and pages with `sent_at < cursor`, so two messages of the same date have
 * no defined order and a page that ended between them would lose one. A page here therefore never ends inside a
 * date: it takes the newest `limit` messages of all folders, and then every message of the date of the last one,
 * read with that date as both bounds. The next page starts strictly before that date. The proof that nothing is
 * lost: a folder that returned more messages than the page holds returned at least one at or before the last date,
 * so every message it did not return is at or before that date, and the same-date read completes that date.
 *
 * A page can thus be longer than `limit` by the messages of its last date; more than {@see self::MAX_SAME_DATE}
 * messages on one date are refused instead of cut, so nothing is skipped silently.
 */
final class MailMessagePager {
	/** Most messages of one stored date added to the end of a page, over all folders. */
	public const MAX_SAME_DATE = 200;

	/**
	 * One page.
	 *
	 * @param list<callable(int $limit, ?int $before, ?int $since, ?int $until): list<int>> $sources one per folder:
	 *     the ids of its matching messages, newest first, at most `$limit` (a source may return more, never fewer when
	 *     more match), sent strictly before `$before` and within `$since`..`$until` when those are given
	 * @param callable(list<int> $ids): list<object> $load the messages of these ids (OCA\Mail\Db\Message), any order
	 * @param int $limit messages wanted on the page, at least 1
	 * @param int|null $before cursor of the page: only messages sent strictly before this Unix time
	 * @return array{messages:list<object>, hasMore:bool, nextBefore:int|null} the messages newest first (ties by id,
	 *     descending), whether older ones exist, and the cursor time of the next page
	 * @throws ToolFailure when more than MAX_SAME_DATE messages share the date the page ends on
	 */
	public function page(array $sources, callable $load, int $limit, ?int $before): array {
		$ids = [];
		$open = [];
		foreach ($sources as $key => $source) {
			$found = $source($limit + 1, $before, null, null);
			if (count($found) > $limit) {
				$open[] = $key;
			}
			array_push($ids, ...$found);
		}
		$messages = self::sorted($load(array_values(array_unique($ids))));
		if ($messages === [] || count($messages) <= $limit && $open === []) {
			return ['messages' => $messages, 'hasMore' => false, 'nextBefore' => null];
		}
		$boundary = (int)$messages[min($limit, count($messages)) - 1]->getSentAt();
		$page = array_values(array_filter($messages, static fn (object $message): bool => (int)$message->getSentAt() > $boundary));
		$sameDate = array_values(array_filter($messages, static fn (object $message): bool => (int)$message->getSentAt() === $boundary));
		$known = array_map(static fn (object $message): int => (int)$message->getId(), $sameDate);
		$extra = [];
		foreach ($open as $key) {
			array_push($extra, ...$sources[$key](self::MAX_SAME_DATE + 1, null, $boundary, $boundary));
		}
		$extra = array_values(array_diff(array_unique($extra), $known));
		if (count($known) + count($extra) > self::MAX_SAME_DATE) {
			throw new ToolFailure(MailMessages::tooManySameDate(self::MAX_SAME_DATE));
		}
		if ($extra !== []) {
			$sameDate = self::sorted(array_merge($sameDate, array_values(array_filter($load($extra),
				static fn (object $message): bool => (int)$message->getSentAt() === $boundary))));
		}
		$hasMore = count(array_filter($messages, static fn (object $message): bool => (int)$message->getSentAt() < $boundary)) > 0;
		foreach ($hasMore ? [] : $open as $key) {
			if ($sources[$key](1, $boundary, null, null) !== []) {
				$hasMore = true;
				break;
			}
		}
		return ['messages' => array_merge($page, $sameDate), 'hasMore' => $hasMore, 'nextBefore' => $hasMore ? $boundary : null];
	}

	/**
	 * Messages newest first, equal dates by id descending, so a page is the same on every call.
	 *
	 * @param list<object> $messages OCA\Mail\Db\Message objects
	 * @return list<object> the same messages, sorted
	 */
	private static function sorted(array $messages): array {
		usort($messages, static fn (object $a, object $b): int => [(int)$b->getSentAt(), (int)$b->getId()] <=> [(int)$a->getSentAt(), (int)$a->getId()]);
		return array_values($messages);
	}
}
