<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Protocol;

use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCP\App\IAppManager;

/**
 * The server identity announced in initialize and in every modern result (SEP-973): the product name, the
 * installed app version and this app's own icons. The icon URLs are absolute and derived from the current
 * request, so a client that renders them loads them from the very host it connected to — a subdomain like
 * nextcloud.example.com included — instead of falling back to a favicon of the registrable domain.
 */
final class ServerIdentity {
	/**
	 * @param IAppManager $apps installed app versions; the core caches info.xml
	 * @param ResourceUrl $urls endpoint URLs of this installation, derived from the current request
	 */
	public function __construct(
		private IAppManager $apps,
		private ResourceUrl $urls,
	) {}

	/**
	 * Identity reported in initialize and in modern results.
	 *
	 * Calling getAppVersion without disabling its cache leaves the manifest read to the core, which already
	 * caches info.xml.
	 *
	 * @return array{name:string, version:string, icons:list<array{src:string, mimeType:string, theme?:string}>}
	 */
	public function info(): array {
		return [
			'name' => McpProtocol::SERVER_NAME,
			'version' => $this->apps->getAppVersion('mcp'),
			'icons' => [
				['src' => $this->urls->asset('img/app.svg'), 'mimeType' => 'image/svg+xml'],
				['src' => $this->urls->asset('img/app-dark.svg'), 'mimeType' => 'image/svg+xml', 'theme' => 'dark'],
			],
		];
	}
}
