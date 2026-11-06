<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Creation;

/** Closed draft HTML vocabulary and removal units. */
final class MailDraftHtmlAllow {
	/** Elements that remain after filtering. */
	public const ELEMENTS = ['p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'a', 'ul', 'ol', 'li',
		'blockquote', 'h1', 'h2', 'h3', 'pre', 'code', 'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'];
	/** Elements removed with their entire subtree. */
	public const SUBTREES = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'template', 'noscript',
		'textarea', 'title', 'head', 'meta', 'link', 'base', 'img', 'picture', 'video', 'audio', 'source', 'canvas'];
	/** Removal counters; wrapper denotes normalization only. */
	public const REMOVED = ['subtree' => 0, 'conditional' => 0, 'comment' => 0, 'unknown-tag' => 0,
		'unsafe-url' => 0, 'attribute' => 0, 'invisible' => 0, 'wrapper' => 0, 'unwrapped-element' => 0];
	/** Invisible, directional and disallowed control characters; ZWJ/ZWNJ remain. */
	public const INVISIBLE = '/[\x{200B}\x{200E}\x{200F}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u';

	/** Static vocabulary only. @return void */
	private function __construct() {}
}
