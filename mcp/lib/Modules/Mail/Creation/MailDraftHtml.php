<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Creation;

use DOMDocument;
use DOMElement;
use DOMNode;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;

/** Filters draft fragments before and after the optional engine, failing closed on unaccounted changes. */
final class MailDraftHtml {
	/**
	 * @param MailDraftHtmlPurifier $engine optional Mail engine behind its integration boundary
	 * @return void
	 */
	public function __construct(private MailDraftHtmlPurifier $engine) {}

	/**
	 * Produces the saved fragment and a complete removal report, then checks a second interpretation and idempotence.
	 *
	 * @param string $input valid UTF-8 source, without NUL
	 * @return array{body:string, removed:array<string,int>, removedUrls:list<string>, unwrappedElements:list<string>, sizes:array<string,int>}
	 * @throws ToolFailure for unavailable engine, unsafe recovery, unreported loss, empty or oversized output
	 */
	public function sanitize(string $input): array {
		if (!mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0") || preg_match('/<\\?|<!doctype\\b/i', $input) === 1 || mb_strlen($input) > MailDraftRequest::MAX_BODY) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		$result = $this->pass($input);
		$body = $result['body'];
		if (mb_strlen($body) > MailDraftRequest::MAX_BODY) {
			throw new ToolFailure(MailDraftMessages::htmlTooLong());
		}
		if ($input !== '' && preg_match('/[\p{L}\p{N}\p{P}\p{S}]/u', MailDraftHtmlDocument::parse($body)->textContent) !== 1) {
			throw new ToolFailure(MailDraftMessages::htmlEmpty());
		}
		if ($this->pass($body)['body'] !== $body) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		return $result + ['sizes' => ['input' => mb_strlen($input), 'saved' => mb_strlen($body)]];
	}

	/**
	 * One deterministic pass; all mutable report data belongs to this call.
	 *
	 * @param string $input UTF-8 fragment
	 * @return array{body:string, removed:array<string,int>, removedUrls:list<string>, unwrappedElements:list<string>}
	 * @throws ToolFailure for unsafe parsing or an engine change outside the report
	 */
	private function pass(string $input): array {
		$removed = MailDraftHtmlAllow::REMOVED;
		$urls = [];
		$unwrapped = [];
		$marker = 'mcp-conditional-' . bin2hex(random_bytes(16));
		$source = $this->conditionalMarkers($input, $marker);
		$document = MailDraftHtmlDocument::parse($source['html']);
		$explicitHead = $source['head'];
		$removed['wrapper'] = $source['wrappers'];
		foreach (iterator_to_array($document->getElementsByTagName('head')) as $head) {
			if ($explicitHead) {
				$removed['attribute'] += $head->attributes->length;
				$head->parentNode->removeChild($head);
				$removed['subtree']++;
			} else {
				$this->filter($head, $removed, $urls, $unwrapped, $marker);
			}
		}
		$this->filter($document, $removed, $urls, $unwrapped, $marker);
		// Unwrapping may leave adjacent text nodes; compare their serialized semantics, without changing characters.
		$document->normalizeDocument();
		// Both inventories use the same fragment parse, including paragraphs legacy libxml inserts around bare text.
		$document = MailDraftHtmlDocument::parse($this->fragment($document));
		$before = $this->fragment($document);
		$purified = $this->engine->purify($before);
		$after = MailDraftHtmlDocument::parse($purified);
		$engineRemoved = MailDraftHtmlAllow::REMOVED;
		$engineUnwrapped = [];
		$this->filter($after, $engineRemoved, $urls, $engineUnwrapped);
		// Loss by the engine is not silently called normalization: the policy prefilter accounts for every removal.
		if (array_sum($engineRemoved) !== 0 || $this->inventory($document) !== $this->inventory($after)) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		$body = $this->fragment($after);
		$check = MailDraftHtmlDocument::parse($body);
		$checkRemoved = MailDraftHtmlAllow::REMOVED;
		$checkUrls = [];
		$checkUnwrapped = [];
		$this->filter($check, $checkRemoved, $checkUrls, $checkUnwrapped);
		if (array_sum($checkRemoved) !== 0 || $this->fragment($check) !== $body) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		return ['body' => $body, 'removed' => $removed, 'removedUrls' => $urls, 'unwrappedElements' => $unwrapped];
	}

	/**
	 * Marks revealed conditional delimiters without editing comments or quoted tag attributes.
	 *
	 * Markers become comments only for parsing. The subtree walk removes them under its normal precedence; a marker
	 * inside script, head or another discarded subtree never becomes a separately counted removal.
	 *
	 * @param string $input source fragment
	 * @param string $marker unpredictable per-pass marker, never emitted
	 * @return array{html:string,head:bool,wrappers:int} marked source and explicit wrappers outside discarded subtrees
	 * @throws ToolFailure when the marker scan fails
	 */
	private function conditionalMarkers(string $input, string $marker): array {
		$head = false;
		$wrappers = 0;
		$blocked = implode('|', MailDraftHtmlAllow::SUBTREES);
		$pattern = '~<!--[\s\S]*?-->|<(' . $blocked . ')\b[^>]*>[\s\S]*?</\1\s*>|<!\[(?:if\b[^\]]*|endif)\]>|<[a-zA-Z/][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>~i';
		$html = preg_replace_callback($pattern, static function (array $match) use ($marker, &$head, &$wrappers): string {
			if (preg_match('~^<!\[(?:if\b|endif\])~i', $match[0]) === 1) {
				return '<!--' . $marker . '-->';
			}
			$head = $head || preg_match('~^<head(?:\s|>)~i', $match[0]) === 1;
			$wrappers += preg_match('~^<(?:html|body)(?:\s|>)~i', $match[0]);
			return $match[0];
		}, $input);
		if ($html === null) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
		return ['html' => $html, 'head' => $head, 'wrappers' => $wrappers];
	}

	/**
	 * Walks before unwrapping, so a forbidden subtree's descendants never survive or count twice.
	 *
	 * @param DOMNode $parent parent to filter
	 * @param array<string,int> $removed report counters
	 * @param list<string> $urls removed destinations
	 * @param list<string> $unwrapped permitted wrappers removed from the fragment
	 * @param string $marker private marker for revealed conditional tokens
	 * @return void
	 */
	private function filter(DOMNode $parent, array &$removed, array &$urls, array &$unwrapped, string $marker = ''): void {
		foreach (iterator_to_array($parent->childNodes) as $node) {
			if ($node->nodeType === XML_COMMENT_NODE) {
				$parent->removeChild($node);
				$removed[$marker !== '' && $node->nodeValue === $marker ? 'conditional' : 'comment']++;
				continue;
			}
			if ($node->nodeType === XML_TEXT_NODE) {
				$node->nodeValue = preg_replace(MailDraftHtmlAllow::INVISIBLE, '', $node->nodeValue, -1, $count);
				$removed['invisible'] += $count;
				continue;
			}
			if (!$node instanceof DOMElement) {
				// Only the document type exists here, outside the emitted fragment; the encoding hint is already detached.
				continue;
			}
			$tag = strtolower($node->tagName);
			if (in_array($tag, MailDraftHtmlAllow::SUBTREES, true)) {
				// An implicit empty head is parser scaffolding, not removed source content.
				if ($tag !== 'head' || $node->hasChildNodes()) {
					$removed['subtree']++;
				}
				$parent->removeChild($node);
				continue;
			}
			$this->filter($node, $removed, $urls, $unwrapped, $marker);
			if (in_array($tag, ['html', 'body'], true)) {
				// The wrapper and each discarded attribute are different removal units.
				$removed['attribute'] += $node->attributes->length;
				continue;
			}
			if (!in_array($tag, MailDraftHtmlAllow::ELEMENTS, true)) {
				$this->unwrap($node);
				$removed['unknown-tag']++;
				continue;
			}
			foreach (iterator_to_array($node->attributes) as $attribute) {
				$name = strtolower($attribute->name);
				$value = $attribute->value;
				if ($tag === 'a' && $name === 'href') {
					if (preg_match('/^(?:https?:\/\/[^\s\\\\]+|mailto:[^\s\\\\]+)$/iu', $value) === 1
						&& preg_match(MailDraftHtmlAllow::INVISIBLE, $value) !== 1) {
						continue;
					}
					$removed['unsafe-url']++;
					$urls[] = $value;
				} elseif (in_array($tag, ['th', 'td'], true) && in_array($name, ['colspan', 'rowspan'], true)
					&& preg_match('/^[1-9][0-9]?$/D', $value) === 1 && (int)$value <= 50) {
					continue;
				} else {
					$removed['attribute']++;
				}
				$node->removeAttributeNode($attribute);
			}
			if ($tag === 'a' && !$node->hasAttribute('href')) {
				$removed['unwrapped-element']++;
				$unwrapped[] = $tag;
				$this->unwrap($node);
			}
		}
	}

	/**
	 * Replaces a wrapper by its already filtered children, preserving text node escaping.
	 *
	 * @param DOMNode $node wrapper
	 * @return void
	 */
	private function unwrap(DOMNode $node): void {
		while ($node->firstChild !== null) {
			$node->parentNode->insertBefore($node->firstChild, $node);
		}
		$node->parentNode->removeChild($node);
	}

	/**
	 * Serializes only body children; no encoding instruction, doctype, head or document wrapper escapes.
	 *
	 * @param DOMDocument $document filtered document
	 * @return string UTF-8 fragment
	 */
	private function fragment(DOMDocument $document): string {
		$body = $document->getElementsByTagName('body')->item(0);
		$out = '';
		foreach ($body?->childNodes ?? [] as $child) {
			$out .= $document->saveHTML($child);
		}
		return $out;
	}

	/**
	 * Captures emitted tags, attributes and text, rejecting any engine loss not already covered by the report.
	 *
	 * @param DOMDocument $document filtered document
	 * @return array<string,int> canonical multiset of fragment content
	 */
	private function inventory(DOMDocument $document): array {
		$items = [];
		$walk = function (DOMNode $node) use (&$walk, &$items): void {
			if ($node instanceof DOMElement) {
				$key = strtolower($node->tagName);
				if (!in_array($key, ['html', 'body', 'head'], true)) {
					$items['tag:' . $key] = ($items['tag:' . $key] ?? 0) + 1;
					foreach ($node->attributes as $attribute) {
						$id = $key . ':' . $attribute->name . '=' . $attribute->value;
						$items[$id] = ($items[$id] ?? 0) + 1;
					}
				}
			} elseif ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue) !== '') {
				$key = 'text:' . $node->nodeValue;
				$items[$key] = ($items[$key] ?? 0) + 1;
			}
			foreach ($node->childNodes as $child) {
				$walk($child);
			}
		};
		$walk($document);
		ksort($items);
		return $items;
	}
}
