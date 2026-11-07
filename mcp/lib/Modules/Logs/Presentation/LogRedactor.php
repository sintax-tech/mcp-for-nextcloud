<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Presentation;

use OCA\Mcp\Modules\Logs\LogLevels;

/**
 * Minimizes what a log entry carries before it reaches the model, without losing its diagnostic value.
 *
 * - IP addresses are masked: IPv4 keeps the first two octets (`203.0.x.x`), IPv6 its /48 prefix, in the remoteAddr
 *   field and inside any text; in the user agent an address right after a slash is a version ("Chrome/141.0.0.0") and
 *   stays, since the version of the client is what makes it useful.
 * - Control characters, ANSI escape sequences and invisible or bidirectional marks are removed: a file name or a
 *   message is written by users, and those are the characters that hide or disguise text.
 * - A text is cut at a limit; an exception keeps class, message, code, place and ten frames of class->function and
 *   file:line, never the arguments.
 * - With messages hidden, the message of an entry and of each of its exceptions, and the user agent, become
 *   {@see self::HIDDEN} whole: free text cannot tell where a file name starts or ends, so none of it is shown. The
 *   method stays only when it is a known HTTP or WebDAV verb. The URL keeps only its fixed route
 *   prefix ({@see self::url()}). The class, the place and the frames of an exception are the server's own PHP code and
 *   stay.
 *
 * Text cleaning is {@see LogTextRedactor}; exception summaries are {@see LogExceptionRedactor}. The constants those
 * classes own stay reachable here, with the same values.
 */
final class LogRedactor {
	/** Frames of a stack trace kept. */
	public const TRACE_FRAMES = LogExceptionRedactor::TRACE_FRAMES;
	/** Previous exceptions kept below the one logged: three levels in all. */
	public const PREVIOUS_LEVELS = LogExceptionRedactor::PREVIOUS_LEVELS;
	/** Longest exception message, as the longest log message. */
	public const MESSAGE_LIMIT = LogExceptionRedactor::MESSAGE_LIMIT;
	/** Maximum bytes inspected per field before any regex. */
	public const PROCESSING_LIMIT = LogTextRedactor::PROCESSING_LIMIT;
	/** Shown in place of a hidden message or of the user's part of a URL. */
	public const HIDDEN = LogExceptionRedactor::HIDDEN;
	/**
	 * Route prefixes of Nextcloud, longest first, all of them fixed text: no segment the request chose is kept (the
	 * app of /apps/<app>/, the account of /remote.php/dav/files/<account>/, the token of a public share), since free
	 * text cannot tell any of them from a folder named alike. Only the prefix stays when messages are hidden: whatever
	 * follows it in a URL becomes {@see self::HIDDEN}. The account of an entry stays in its user field.
	 */
	private const ROUTE_PREFIXES = [
		'~^/(?:index\.php/)?apps/~i',
		'~^/ocs/v[12]\.php/(?:apps/|cloud/)?~i',
		'~^/remote\.php/dav/(?:files|uploads|trashbin|versions|calendars|addressbooks|principals|comments|systemtags|systemtags-relations)/~i',
		'~^/remote\.php/(?:webdav/|dav/)?~i',
		'~^/public\.php/(?:dav/files/|webdav/)?~i',
		'~^/index\.php/(?:s/|f/)?~i',
		'~^/(?:s|f|ocs)/~i',
		'~^/(?:status|cron)\.php~i',
	];
	/**
	 * HTTP and WebDAV verbs kept while messages are hidden. The method of an entry is whatever the client sent, copied
	 * by the core without a check, so anything else is the client's text.
	 */
	private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT', 'PROPFIND', 'PROPPATCH',
		'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'REPORT', 'SEARCH', 'MKCALENDAR', 'ACL'];
	/** A URL: scheme and host, then the rest. */
	private const URL = '~^([a-z][a-z0-9+.-]*://[^/?#\s]+)(.*)$~i';

	/**
	 * Facade only; never instantiated.
	 */
	private function __construct() {
	}

	/**
	 * @param string $address the remoteAddr of an entry
	 * @return string the masked address; '' and '--' unchanged; anything that is not an address becomes 'x'
	 */
	public static function ip(string $address): string {
		return LogTextRedactor::ip($address);
	}

	/**
	 * A text fit to show: valid UTF-8, no control or invisible characters, addresses masked, cut at the limit.
	 *
	 * @param string $text a message, a URL, a user agent
	 * @param int $limit longest result in characters, before the marker of a cut
	 * @param bool $userAgent true for a user agent: an address right after a slash is a version ("Chrome/141.0.0.0")
	 *     and stays; any other address is masked as in every text
	 * @return string the cleaned text, with '…' when it was cut
	 */
	public static function text(string $text, int $limit, bool $userAgent = false): string {
		return LogTextRedactor::text($text, $limit, $userAgent);
	}

	/**
	 * @param string $url the url field of an entry, already passed through {@see self::text()}
	 * @return string scheme, host and the route prefix of Nextcloud; the rest of the path and the whole query hidden
	 */
	public static function url(string $url): string {
		$head = '';
		if (preg_match(self::URL, $url, $m) === 1) {
			[$head, $url] = [$m[1], $m[2]];
		}
		$cut = strcspn($url, '?#');
		$path = substr($url, 0, $cut);
		$prefix = self::routePrefix($path);
		if ($prefix === '' && str_starts_with($path, '/')) {
			$prefix = '/';
		}
		return $head . $prefix . (substr($path, strlen($prefix)) !== '' ? self::HIDDEN : '') . ($cut < strlen($url) ? '?' . self::HIDDEN : '');
	}

	/**
	 * The route prefix of Nextcloud a path starts with, or '' when none matches.
	 *
	 * @param string $path path of a URL, without its query
	 * @return string the matching prefix, or '' when none matches
	 */
	private static function routePrefix(string $path): string {
		foreach (self::ROUTE_PREFIXES as $pattern) {
			if (preg_match($pattern, $path, $m) === 1) {
				return $m[0];
			}
		}
		return '';
	}

	/**
	 * @param mixed $exception the `exception` field of an entry, as json_decode() gave it
	 * @param bool $hideMessages whether the message of the exception and of the previous ones is hidden whole
	 * @return array<string, mixed>|null class, message, code, place, the first frames and the previous exceptions; null
	 *     when the value is not an exception
	 */
	public static function exception(mixed $exception, bool $hideMessages = false): ?array {
		return LogExceptionRedactor::exception($exception, $hideMessages);
	}

	/**
	 * The fields of an entry the logs tools show, redacted: the extra `data` of the core and the version stay behind.
	 *
	 * @param \stdClass $entry one well-formed entry of the log
	 * @param bool $hideMessages whether the messages are hidden whole and the URL keeps only its route prefix
	 * @return array<string, mixed> time, level by name, app, user, method, url, message, reqId, userAgent, masked
	 *     remoteAddr and, when the entry has one, the exception summary
	 */
	public static function entry(\stdClass $entry, bool $hideMessages = false): array {
		$field = static fn (string $name, int $limit, bool $userAgent = false): string => is_scalar($entry->{$name} ?? null) ? self::text((string)$entry->{$name}, $limit, $userAgent) : '';
		$row = [
			'time' => $field('time', 64),
			'level' => LogLevels::NAMES[(int)$entry->level] ?? (string)$entry->level,
			'app' => $field('app', 100),
			'user' => $field('user', 100),
			'method' => $hideMessages ? self::method($entry->method ?? null) : $field('method', 16),
			'url' => is_scalar($entry->url ?? null) ? self::shownUrl((string)$entry->url, self::MESSAGE_LIMIT, $hideMessages) : '',
			'message' => $hideMessages ? self::HIDDEN : $field('message', self::MESSAGE_LIMIT),
			'reqId' => $field('reqId', 64),
			// The user agent is free text of the client ("BackupClient/1.0 source=…"), so it goes with the messages.
			'userAgent' => $hideMessages ? self::HIDDEN : $field('userAgent', 300, true),
			'remoteAddr' => is_string($entry->remoteAddr ?? null) ? self::ip($entry->remoteAddr) : '',
		];
		$exception = self::exception($entry->exception ?? null, $hideMessages);
		if ($exception !== null) {
			$row['exception'] = $exception;
		}
		return $row;
	}

	/**
	 * @param string $url the url field of an entry
	 * @param int $limit longest result in characters
	 * @param bool $hideMessages whether everything after the route prefix is hidden
	 * @return string the URL as the tools show it
	 */
	public static function shownUrl(string $url, int $limit, bool $hideMessages): string {
		$url = self::text($url, PHP_INT_MAX);
		if ($hideMessages) {
			$url = self::url($url);
		}
		return mb_strlen($url) > $limit ? mb_substr($url, 0, $limit) . '…' : $url;
	}

	/**
	 * @param mixed $method the method field of an entry
	 * @return string a known HTTP or WebDAV verb in capitals; '' when there is none; {@see self::HIDDEN} for anything else
	 */
	private static function method(mixed $method): string {
		if (!is_string($method) || $method === '') {
			return '';
		}
		$verb = strtoupper($method);
		return in_array($verb, self::METHODS, true) ? $verb : self::HIDDEN;
	}
}
