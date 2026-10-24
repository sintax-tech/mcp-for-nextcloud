<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

/**
 * Shapes the rows the Mail tools answer with. Account and folder names are written by the user and by whoever runs
 * the mail server, so they are untrusted data: control and bidirectional formatting characters are removed (they
 * can hide or reorder text in what the model and the person read) and every name is bounded. The JSON envelope
 * keeps them as values, never as text the server speaks in.
 */
final class MailRows {
	/** Longest name kept, in characters; Mail stores folder names in a 255-character column. */
	public const MAX_NAME = 255;

	/** Special-use attributes of RFC 6154 the tools report, by the lowercase attribute without its backslash. */
	private const SPECIAL_USES = ['sent' => 'sent', 'drafts' => 'drafts', 'trash' => 'trash', 'junk' => 'junk', 'archive' => 'archive', 'all' => 'all', 'flagged' => 'flagged'];

	/** Role reported for the inbox, which RFC 3501 names INBOX in any case and RFC 6154 gives no attribute. */
	private const INBOX = 'inbox';

	/**
	 * An account row with its name and address cleaned.
	 *
	 * @param array{id:int, name:string, email:string} $account row from {@see MailGateway::accounts()}
	 * @return array{id:int, name:string, email:string} the row the tool answers with
	 */
	public function account(array $account): array {
		return [
			'id' => $account['id'],
			'name' => self::text($account['name']),
			'email' => self::text($account['email']),
		];
	}

	/**
	 * A folder row: the full path, its last segment, its role and the counts the Mail app stored at its last
	 * synchronization.
	 *
	 * @param array{id:int, name:string, delimiter:string|null, specialUse:string, unread:int|null, total:int|null, selectable:bool|null} $mailbox row from {@see MailGateway::mailboxes()}
	 * @return array{id:int, path:string, name:string, delimiter:string|null, special:string|null, unread:int|null, total:int|null, selectable:bool|null} the row the tool answers with
	 */
	public function mailbox(array $mailbox): array {
		$path = self::text($mailbox['name']);
		$delimiter = $mailbox['delimiter'] === null || $mailbox['delimiter'] === '' ? null : self::text($mailbox['delimiter']);
		$segments = $delimiter === null || $delimiter === '' ? [$path] : explode($delimiter, $path);
		return [
			'id' => $mailbox['id'],
			'path' => $path,
			'name' => (string)end($segments),
			'delimiter' => $delimiter,
			'special' => self::special($mailbox['name'], $mailbox['specialUse']),
			'unread' => $mailbox['unread'],
			'total' => $mailbox['total'],
			'selectable' => $mailbox['selectable'],
		];
	}

	/**
	 * Orders folders the way a mail client shows them: the inbox first, then by path, case-insensitively.
	 *
	 * @param list<array{id:int, path:string, name:string, delimiter:string|null, special:string|null, unread:int|null, total:int|null, selectable:bool|null}> $rows rows from {@see self::mailbox()}
	 * @return list<array{id:int, path:string, name:string, delimiter:string|null, special:string|null, unread:int|null, total:int|null, selectable:bool|null}> the same rows, sorted
	 */
	public function sorted(array $rows): array {
		usort($rows, static fn (array $a, array $b): int => [$a['special'] !== self::INBOX, mb_strtolower($a['path']), $a['id']]
			<=> [$b['special'] !== self::INBOX, mb_strtolower($b['path']), $b['id']]);
		return $rows;
	}

	/**
	 * The role of a folder: inbox by its name, otherwise the first known special-use attribute the server announced.
	 *
	 * @param string $name raw folder name
	 * @param string $specialUse JSON list of attributes, as the Mail app stores them
	 * @return string|null inbox, sent, drafts, trash, junk, archive, all, flagged, or null for an ordinary folder
	 */
	private static function special(string $name, string $specialUse): ?string {
		if (strtolower($name) === 'inbox') {
			return self::INBOX;
		}
		$attributes = json_decode($specialUse, true);
		foreach (is_array($attributes) ? $attributes : [] as $attribute) {
			$key = is_string($attribute) ? strtolower(ltrim($attribute, '\\')) : '';
			if (isset(self::SPECIAL_USES[$key])) {
				return self::SPECIAL_USES[$key];
			}
		}
		return null;
	}

	/**
	 * Untrusted text made safe to show: invalid UTF-8 replaced, control and bidirectional formatting characters
	 * removed, length bounded.
	 *
	 * @param string $value raw value from the Mail app
	 * @return string the cleaned value
	 */
	public static function text(string $value): string {
		$value = mb_scrub($value, 'UTF-8');
		$value = (string)preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);
		return mb_substr($value, 0, self::MAX_NAME);
	}
}
