<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\Creation\MailDraftHtmlAllow;
use OCA\Mcp\Modules\Mail\Creation\MailDraftHtmlPurifier;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Uses Mail's already loaded HTMLPurifier without vendor paths or global URI registration. */
final class MailDraftHtmlEngine implements MailDraftHtmlPurifier {
	/**
	 * Creates a fresh, restricted configuration for each call, with no definition cache.
	 *
	 * @param string $html UTF-8 fragment after the draft prefilter
	 * @return string purified fragment
	 * @throws ToolFailure when Mail's engine is unavailable or purification fails
	 */
	public function purify(string $html): string {
		if (!class_exists('HTMLPurifier') || !class_exists('HTMLPurifier_Config')) {
			throw new ToolFailure(MailDraftMessages::htmlEngineMissing());
		}
		try {
			$config = \HTMLPurifier_Config::createDefault();
			$config->set('Core.Encoding', 'UTF-8');
			$config->set('Cache.DefinitionImpl', null);
			$allowed = array_map(static fn (string $tag): string => match ($tag) {
				'a' => 'a[href]', 'th', 'td' => $tag . '[colspan|rowspan]', default => $tag,
			}, MailDraftHtmlAllow::ELEMENTS);
			$config->set('HTML.Allowed', implode(',', $allowed));
			$config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
			$config->set('URI.DisableExternalResources', true);
			$config->set('URI.DisableResources', true);
			return (new \HTMLPurifier($config))->purify($html);
		} catch (\Throwable $error) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid(), 0, $error);
		}
	}
}
