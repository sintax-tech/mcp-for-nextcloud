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
 * Whether a link belongs to this Nextcloud.
 *
 * A relative path does. An absolute URL does only when its host is loopback, one of trusted_domains (with their
 * wildcards), overwrite.cli.url or the URL generator's own host. ITrustedDomainHelper is not used: with overwritehost
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
	 * @param string $reference a path or a URL copied from an event, a card or a message
	 * @return bool whether it is a relative path or an absolute URL of this instance
	 */
	public function accepts(string $reference): bool {
		$reference = trim($reference);
		if ($reference === '' || str_starts_with(strtolower($reference), 'data:')) {
			return false;
		}
		if (str_starts_with($reference, '//')) {
			$parts = parse_url('https:' . $reference);
			return is_array($parts) && $this->isLocal((string)($parts['host'] ?? ''), isset($parts['port']) ? (int)$parts['port'] : null);
		}
		if (!str_contains($reference, '://')) {
			return true;
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
