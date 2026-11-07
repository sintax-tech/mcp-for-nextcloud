<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Template\ITemplateManager;

/** Render one admin panel through the public Nextcloud template API. */
final class AdminPanels {
	/** @param ITemplateManager $templates native template renderer, available since Nextcloud 32 */
	public function __construct(private ITemplateManager $templates) {}

	/**
	 * @param string $panel panel name chosen by the main template
	 * @param array<string, mixed> $parameters the main template's current parameters
	 * @return string complete panel markup, escaped by its original template expressions
	 */
	public function render(string $panel, array $parameters): string {
		$template = $this->templates->getTemplate('mcp', 'admin/' . $panel, TemplateResponse::RENDER_AS_BLANK, false);
		// Native template variables win over fetchPage arguments, including the default empty token.
		foreach ($parameters as $name => $value) {
			$template->assign($name, $value);
		}
		return $template->fetchPage($parameters);
	}
}
