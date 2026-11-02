<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Presentation;

/**
 * Cleans one log text: masked addresses, no control or invisible characters, cut at a limit.
 *
 * The patterns and the preparation live here. Callers keep using {@see LogRedactor}, which delegates.
 */
final class LogTextRedactor {
	/** Maximum bytes inspected per field before any regex. */
	public const PROCESSING_LIMIT = 16384;
	/** Shown in place of a value that is not an IP address. */
	private const NOT_AN_ADDRESS = 'x';
	/**
	 * IPv4 addresses inside a text, not glued to a word or to another number: "1.2.3.4.5" and "v31.0.9.1" are versions,
	 * while a full stop, a comma or a colon after the address is punctuation and stays outside the match. %s is where
	 * the characters the address may not follow go: a slash too in a user agent, where "Chrome/141.0.0.0" is a version.
	 */
	private const IPV4 = '/(?<![\w.%s])\d{1,3}(?:\.\d{1,3}){3}(?!\.?\d)(?!\w)/';
	/**
	 * Candidates for an IPv6 address inside a text; filter_var() decides, so a clock time or a MAC address stays. A
	 * trailing colon may be punctuation ("Remote IP: 2001:db8::9:"), so the callback tries once without it.
	 * CIDR suffixes are consumed and reduced to the same canonical /48.
	 */
	private const IPV6 = '/(?<![\w:.%s])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?!\w)(?!\.\w)(?!:[0-9a-f])(?:\/\d+)?/i';
	/**
	 * An IPv6 candidate glued to a label by a colon ("IP:2001:db8::1", "clientAddress:2001:db8::9"), which the pattern
	 * above cannot see, since an address never starts right after a colon there. The label is consumed once, possessively, with no length cap;
	 * the boundary excludes every label character (including hyphens), so matching never restarts inside it.
	 * Together these make the pass linear even for a long label with no valid address.
	 */
	private const LABELED_IPV6 = '/(?<![\w:.%s-])([a-z_][\w-]*+):((?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?!\w)(?!\.\w)(?!:[0-9a-f])(?:\/\d+)?)/i';
	/** ANSI escape sequences (CSI and OSC). */
	private const ANSI = '/\x{1B}(?:\[[0-9;?]*[ -\/]*[@-~]|\][^\x{07}\x{1B}]*(?:\x{07}|\x{1B}\\\\)?)?/u';
	/**
	 * Control characters, C1 controls, the soft hyphen, every bidirectional control (ALM, LRM, RLM, the embeddings,
	 * overrides and isolates) and the zero-width characters, the Mongolian vowel separator and the BOM included.
	 */
	private const INVISIBLE = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';
	/** Line breaks, tabs and the Unicode line and paragraph separators: each becomes a space. */
	private const BREAKS = ["\r\n", "\r", "\n", "\t", "\u{2028}", "\u{2029}"];

	/**
	 * Static redactor only; never instantiated.
	 */
	private function __construct() {
	}

	/**
	 * @param string $address the remoteAddr of an entry
	 * @return string the masked address; '' and '--' unchanged; anything that is not an address becomes 'x'
	 */
	public static function ip(string $address): string {
		$address = trim($address);
		if ($address === '' || $address === '--') {
			return $address;
		}
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$octets = explode('.', $address);
			return $octets[0] . '.' . $octets[1] . '.x.x';
		}
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
			return self::NOT_AN_ADDRESS;
		}
		// An IPv4-mapped address is the IPv4 one.
		if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
			return self::ip($mapped[1]);
		}
		$prefix = substr((string)inet_pton($address), 0, 6) . str_repeat("\0", 10);
		return inet_ntop($prefix) . '/48';
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
		$processingCut = strlen($text) > self::PROCESSING_LIMIT;
		if ($processingCut) {
			// Drop the final unfinished token: it could be an address crossing the boundary.
			$text = preg_replace('/\S+$/', '', substr($text, 0, self::PROCESSING_LIMIT)) ?? '';
		}
		// Invalid bytes become U+FFFD, so the patterns below, which need valid UTF-8, never fail on them.
		$text = (string)json_decode(json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
		$text = preg_replace(self::ANSI, '', $text) ?? '';
		$text = str_replace(self::BREAKS, ' ', $text);
		$text = preg_replace(self::INVISIBLE, '', $text) ?? '';
		$notAfter = $userAgent ? '\/' : '';
		$text = preg_replace_callback(sprintf(self::IPV4, $notAfter), static function (array $m): string {
			$masked = self::ip($m[0]);
			return $masked === self::NOT_AN_ADDRESS ? $m[0] : $masked;
		}, $text) ?? '';
		$text = preg_replace_callback(sprintf(self::IPV6, $notAfter), static fn (array $m): string => self::maskedIpv6($m[0]) ?? $m[0], $text) ?? '';
		$text = preg_replace_callback(sprintf(self::LABELED_IPV6, $notAfter), static function (array $m): string {
			// A hexadecimal label may actually be the first group of the whole address.
			return self::maskedIpv6($m[0]) ?? ($m[1] . ':' . (self::maskedIpv6($m[2]) ?? $m[2]));
		}, $text) ?? '';
		$text = trim($text);
		return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text . ($processingCut ? '…' : '');
	}

	/**
	 * Masks an IPv6 token. Only our exact canonical /48 output is already redacted; every other CIDR still needs masking.
	 *
	 * @param string $candidate a token that may be an IPv6 address, with an optional CIDR suffix
	 * @return string|null the masked address, or null when the token is not an address
	 */
	private static function maskedIpv6(string $candidate): ?string {
		$address = explode('/', $candidate, 2)[0];
		$suffix = '';
		if (!self::isIpv6($address) && str_ends_with($address, ':') && !str_ends_with($address, '::')) {
			$address = substr($address, 0, -1);
			$suffix = ':';
		}
		if (!self::isIpv6($address)) {
			return null;
		}
		$masked = self::ip($address);
		return $candidate === $masked ? $candidate : $masked . $suffix;
	}

	/**
	 * Whether the whole string is an IPv6 address.
	 *
	 * @param string $candidate the whole token to test
	 * @return bool true when the token is an IPv6 address
	 */
	private static function isIpv6(string $candidate): bool {
		return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
	}
}
