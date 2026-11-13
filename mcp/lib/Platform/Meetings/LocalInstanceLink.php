<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Whether a link belongs to this Nextcloud, and the only tokenizer of those links.
 *
 * A relative reference is a numeric file id or a path that starts with exactly one slash. An absolute URL starts
 * with a scheme and "://" or with "//", and it belongs here only when its host is loopback, one of trusted_domains
 * (with their wildcards), overwrite.cli.url or the URL generator's own host. A scheme without "//", a backslash
 * before the path, and a host written without a scheme do not. ITrustedDomainHelper is not used: with overwritehost
 * set it trusts every host. A foreign host is not fetched and is not turned into an object of this instance.
 */
final class LocalInstanceLink {
	/**
	 * @param IURLGenerator $urls the absolute URL of this instance
	 * @param IConfig $config trusted_domains and overwrite.cli.url
	 */
	public function __construct(
		private IURLGenerator $urls,
		private IConfig $config,
	) {
	}

	/**
	 * @param string $reference a path, a file id or a URL copied from an event, a card or a message
	 * @return bool whether it is a numeric id, a single-slash path, or an absolute URL of this instance
	 */
	public function accepts(string $reference): bool {
		$reference = trim($reference);
		if ($reference === '' || str_starts_with(strtolower($reference), 'data:') || $this->schemeWithoutSlashes($reference) || $this->backslashBeforePath($reference)) {
			return false;
		}
		if (ctype_digit($reference)) {
			return true;
		}
		if (str_starts_with($reference, '/') && !str_starts_with($reference, '//')) {
			return true;
		}
		if (str_starts_with($reference, '//')) {
			$parts = parse_url('https:' . $reference);
			return is_array($parts) && $this->isLocal((string)($parts['host'] ?? ''), isset($parts['port']) ? (int)$parts['port'] : null);
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $reference) !== 1) {
			return false;
		}
		$parts = parse_url($reference);
		if (!is_array($parts)) {
			return false;
		}
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return false;
		}
		return $this->isLocal((string)($parts['host'] ?? ''), isset($parts['port']) ? (int)$parts['port'] : null);
	}

	/**
	 * Whole tokens of a text, keeping only a reference of this instance.
	 *
	 * Whitespace and angle brackets separate tokens. A quote or an apostrophe is removed only when it is the first
	 * or the last character, so one in the middle cannot end an absolute URL and start a relative path.
	 *
	 * @param string $text a description, a location, or one property value
	 * @return list<string> each kept reference once, in order
	 */
	public function references(string $text): array {
		$found = [];
		foreach ($this->tokens($text) as $token) {
			if (!$this->referenceToken($token) || in_array($token, $found, true)) {
				continue;
			}
			$found[] = $token;
		}
		return $found;
	}

	/**
	 * @param string $text a description, a location, or one property value
	 * @return list<string> tokens with the wrapping quotes removed
	 */
	private function tokens(string $text): array {
		$parts = preg_split('/[\s<>]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts)) {
			return [];
		}
		$tokens = [];
		foreach ($parts as $part) {
			$token = $this->unwrap((string)$part);
			if ($token !== '') {
				$tokens[] = $token;
			}
		}
		return $tokens;
	}

	/**
	 * @param string $token one piece already split on whitespace or angle brackets
	 * @return string the same piece without a quote or an apostrophe at either end
	 */
	private function unwrap(string $token): string {
		while ($token !== '' && str_contains("\"'", $token[0])) {
			$token = substr($token, 1);
		}
		while ($token !== '' && str_contains("\"'", substr($token, -1))) {
			$token = substr($token, 0, -1);
		}
		return $token;
	}

	/**
	 * @param string $token one whole token
	 * @return bool whether it is an absolute URL of this instance or a path of exactly one leading slash
	 */
	private function referenceToken(string $token): bool {
		$absolute = str_starts_with($token, '//') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $token) === 1;
		$relative = str_starts_with($token, '/') && !str_starts_with($token, '//');
		return ($absolute || $relative) && $this->accepts($token);
	}

	/**
	 * @param string $reference a path or a URL
	 * @return bool whether a scheme is written without the two slashes an absolute URL needs
	 */
	private function schemeWithoutSlashes(string $reference): bool {
		return preg_match('#^[a-z][a-z0-9+.-]*:(?!//)#i', $reference) === 1;
	}

	/**
	 * A backslash before the path is a different host under the WHATWG URL parser than under parse_url.
	 *
	 * @param string $reference a path or a URL
	 * @return bool whether the scheme or the authority contains a backslash
	 */
	private function backslashBeforePath(string $reference): bool {
		if (str_starts_with($reference, '/') && !str_starts_with($reference, '//')) {
			return false;
		}
		$rest = $reference;
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rest, $scheme) === 1) {
			$rest = substr($rest, strlen($scheme[0]));
		} elseif (str_starts_with($rest, '//')) {
			$rest = substr($rest, 2);
		}
		$end = strlen($rest);
		foreach (['/', '?', '#'] as $stop) {
			$at = strpos($rest, $stop);
			if ($at !== false && $at < $end) {
				$end = $at;
			}
		}
		return str_contains(substr($rest, 0, $end), '\\');
	}

	/**
	 * Whether a host is this server.
	 *
	 * @param string $host the host of an absolute URL
	 * @param int|null $port the port, when the URL named one
	 * @return bool whether the host is this instance
	 */
	private function isLocal(string $host, ?int $port): bool {
		$host = rtrim(strtolower(trim(rawurldecode($host), '[]')), '.');
		if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1'
			|| (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'))) {
			return $host !== '';
		}
		$candidates = [$host];
		if ($port !== null) {
			$candidates[] = $host . ':' . $port;
		}
		$patterns = $this->config->getSystemValue('trusted_domains', []);
		$patterns = is_array($patterns) ? $patterns : [];
		foreach ([(string)$this->config->getSystemValue('overwrite.cli.url', ''), $this->urls->getAbsoluteURL('/')] as $own) {
			$ownHost = parse_url($own, PHP_URL_HOST);
			if (is_string($ownHost) && $ownHost !== '') {
				$ownPort = parse_url($own, PHP_URL_PORT);
				$patterns[] = is_int($ownPort) ? $ownHost . ':' . $ownPort : $ownHost;
			}
		}
		foreach ($patterns as $pattern) {
			if (!is_string($pattern) || trim($pattern) === '') {
				continue;
			}
			$regex = '/^' . str_replace('\*', '[-.a-z0-9]*', preg_quote(strtolower(trim($pattern)), '/')) . '$/';
			foreach ($candidates as $candidate) {
				if (preg_match($regex, $candidate) === 1) {
					return true;
				}
			}
		}
		return false;
	}
}
