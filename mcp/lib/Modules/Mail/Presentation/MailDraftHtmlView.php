<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;

use DOMDocument;
use DOMElement;
use DOMNode;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Inert, complete HTML confirmation data and text preview, independent of Mail's MIME text alternative. */
final class MailDraftHtmlView {
	/**
	 * Derives preview and all destinations from the saved fragment, preserving pre/code whitespace.
	 *
	 * @param array<string,mixed> $result sanitizer result
	 * @return array<string,mixed> HTML-only draft plan fields
	 */
	public static function fields(array $result): array {
		$previous = libxml_use_internal_errors(true);
		try {
			$document = new DOMDocument('1.0', 'UTF-8');
			$document->loadHTML('<?xml encoding="UTF-8"><body>' . $result['body'] . '</body>', LIBXML_NONET);
			$links = [];
			foreach ($document->getElementsByTagName('a') as $anchor) {
				$href = $anchor->getAttribute('href');
				$text = self::text($anchor);
				$links[] = ['text' => $text, 'href' => $href, 'differs' => $text !== $href];
			}
			$preview = self::text($document);
			$result['sizes']['preview'] = mb_strlen($preview);
			unset($result['body']);
			return $result + ['preview' => $preview, 'links' => $links];
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/**
	 * Renders every HTML field without truncation; JSON quoting exposes whitespace and prevents active markup/links.
	 *
	 * @param array<string,mixed> $draft HTML draft fields
	 * @return list<string> inert Markdown lines
	 */
	public static function lines(array $draft): array {
		$lines = [Translator::t('Sanitized HTML: %s', [PlanText::quoted($draft['body'])]),
			Translator::t('Text preview: %s', [PlanText::quoted($draft['preview'])])];
		$counts = $draft['removed'];
		$changed = array_sum($counts) - $counts['wrapper'] > 0;
		$lines[] = $changed ? MailDraftMessages::htmlRemoved() : ($counts['wrapper'] > 0
			? MailDraftMessages::htmlNormalized() : MailDraftMessages::htmlUnchanged());
		$lines[] = Translator::t('Removal counts: %s', [PlanText::quoted(json_encode($counts, JSON_THROW_ON_ERROR))]);
		if ($draft['unwrappedElements'] !== []) {
			$lines[] = Translator::t('Unwrapped elements: %s', [PlanText::quoted(implode(', ', $draft['unwrappedElements']))]);
		}
		foreach ($draft['links'] as $link) {
			$lines[] = Translator::t('Link text and destination: %s', [PlanText::quoted(json_encode($link, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))]);
		}
		foreach ($draft['removedUrls'] as $url) {
			$lines[] = Translator::t('Removed destination: %s', [PlanText::quoted($url)]);
		}
		$lines[] = Translator::t('Sizes in characters (input, saved, preview): %s', [PlanText::quoted(implode(', ', $draft['sizes']))]);
		return $lines;
	}

	/**
	 * Keeps pre/code spacing, list markers, table cells and block boundaries without interpreting the source as Markdown.
	 *
	 * @param DOMNode $node already sanitized node
	 * @param bool $preserve whether a pre/code ancestor preserves whitespace
	 * @return string textual representation
	 */
	private static function text(DOMNode $node, bool $preserve = false): string {
		if ($node->nodeType === XML_TEXT_NODE) {
			return $preserve ? $node->nodeValue : (preg_replace('/\s+/u', ' ', $node->nodeValue) ?? '');
		}
		$tag = $node instanceof DOMElement ? strtolower($node->tagName) : '';
		$preserve = $preserve || in_array($tag, ['pre', 'code'], true);
		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= self::text($child, $preserve);
		}
		return match ($tag) {
			'br', 'hr' => "\n",
			'td', 'th' => $out . "\t",
			'li' => '- ' . $out . "\n",
			'p', 'div', 'blockquote', 'h1', 'h2', 'h3', 'pre', 'tr', 'ul', 'ol' => $out . "\n",
			default => $out,
		};
	}
}
