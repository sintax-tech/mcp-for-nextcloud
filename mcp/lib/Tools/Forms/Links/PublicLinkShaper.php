<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Links;

use OCA\Forms\Constants;
use OCP\IURLGenerator;
use OCP\Share\IShare;

/** Whitelists participation credentials from TYPE_LINK only, after native editor admission by the caller. */
final class PublicLinkShaper {
	/** Receives the official route generator.
	 * @param IURLGenerator $urls Generates public and embedded native routes.
	 */
	public function __construct(private IURLGenerator $urls) {
	}

	/** Excludes every recipient and non-link share field, including names and raw shareWith.
	 * @param array<array<string,mixed>> $shares Native getShares result, never serialized wholesale.
	 * @return list<array<string,mixed>> Existing public routes, not new shares.
	 */
	public function of(array $shares): array {
		$result = [];
		foreach ($shares as $share) {
			if (($share['shareType'] ?? null) !== IShare::TYPE_LINK) { continue; }
			$link = ['shareId' => (int)$share['id'], 'url' => $this->urls->linkToRouteAbsolute('forms.page.publicLinkView', ['hash' => $share['shareWith']])];
			if (in_array(Constants::PERMISSION_EMBED, $share['permissions'], true)) {
				$link['embedUrl'] = $this->urls->linkToRouteAbsolute('forms.page.embeddedFormView', ['hash' => $share['shareWith']]);
			}
			$result[] = $link;
		}
		return $result;
	}
}
