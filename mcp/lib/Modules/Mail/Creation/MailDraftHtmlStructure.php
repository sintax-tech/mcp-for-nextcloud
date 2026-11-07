<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;

/**
 * Order of the optional html, head and body wrappers in one draft source, read token by token.
 *
 * Both parsers drop or move content after </body> or </html>, merge a second wrapper into the first and close head
 * implicitly in different places, so the draft would lose content silently. Accepted: at most one of each wrapper,
 * html before anything but comments, head (with only head elements, closed by </head>) and body before any content,
 * and nothing but whitespace after </body> or </html>.
 */
final class MailDraftHtmlStructure {
	/** Elements a head may hold. */
	private const HEAD = ['meta', 'title', 'style', 'link', 'base', 'script'];

	/** @var array<string,bool> wrappers whose start tag was read */
	private array $opened = [];
	/** @var array<string,bool> wrappers whose end tag was read */
	private array $closed = [];
	/** Whether body content (text or an element outside the wrappers) was read. */
	private bool $content = false;
	/** Depth of head elements open inside head. */
	private int $headDepth = 0;
	/** Whether a head element was read before any body content, outside an explicit head (an implied head). */
	private bool $impliedHead = false;

	/**
	 * Reads text outside tags.
	 *
	 * @param string $text source text
	 * @return void
	 * @throws ToolFailure for content inside head or after </body> or </html>
	 */
	public function text(string $text): void {
		if (trim($text, " \t\n") === '') {
			return;
		}
		$this->content(true);
	}

	/**
	 * Whitespace before any body content or after </body> or </html> shows nothing; the parsers put it in different
	 * places (an implied paragraph or the implied head), so it is dropped before parsing.
	 *
	 * @param string $text source text
	 * @return string the text without whitespace outside the body content
	 */
	public function visible(string $text): string {
		if (!$this->content) {
			return ltrim($text, " \t\n");
		}
		return trim($text, " \t\n") === '' && (($this->closed['body'] ?? false) || ($this->closed['html'] ?? false)) ? '' : $text;
	}

	/**
	 * Reads a comment or conditional delimiter.
	 *
	 * A revealed conditional delimiter becomes a marker the parsers place differently inside head, so it is refused there.
	 *
	 * @param bool $conditional whether the token is a revealed conditional delimiter
	 * @return void
	 * @throws ToolFailure after </body> or </html>, or for a conditional delimiter inside head
	 */
	public function comment(bool $conditional): void {
		$this->refuseIf(($this->closed['body'] ?? false) || ($this->closed['html'] ?? false) || ($conditional && $this->inHead()));
	}

	/**
	 * Reads a start tag.
	 *
	 * @param string $name lowercase element name
	 * @param bool $void whether the element has no end tag
	 * @return void
	 * @throws ToolFailure for a repeated or misplaced wrapper, or content in the wrong place
	 */
	public function start(string $name, bool $void): void {
		if ($name === 'html') {
			$this->refuseIf(($this->opened['html'] ?? false) || $this->opened !== [] || $this->content || $this->impliedHead);
		} elseif ($name === 'head') {
			$this->refuseIf(($this->opened['head'] ?? false) || ($this->opened['body'] ?? false) || $this->content || $this->impliedHead);
		} elseif ($name === 'body') {
			$this->refuseIf(($this->opened['body'] ?? false) || $this->inHead() || $this->content);
		} elseif ($this->inHead()) {
			$this->refuseIf(!in_array($name, self::HEAD, true));
			$this->headDepth += $void ? 0 : 1;
			return;
		} elseif (!$this->content && !($this->opened['body'] ?? false) && in_array($name, self::HEAD, true)) {
			// Before body content both parsers put head elements in an implied head; they are not content.
			$this->refuseIf(($this->closed['body'] ?? false) || ($this->closed['html'] ?? false));
			$this->impliedHead = true;
			return;
		} else {
			$this->content(false);
			return;
		}
		$this->refuseIf(($this->closed['body'] ?? false) || ($this->closed['html'] ?? false));
		$this->opened[$name] = true;
	}

	/**
	 * Reads an end tag.
	 *
	 * @param string $name lowercase element name
	 * @return void
	 * @throws ToolFailure for a wrapper end tag without its start tag, or any tag after </body> or </html>
	 */
	public function end(string $name): void {
		if (!in_array($name, ['html', 'head', 'body'], true)) {
			if ($this->inHead()) {
				$this->refuseIf($this->headDepth === 0);
				$this->headDepth--;
				return;
			}
			if (!$this->content && $this->impliedHead && in_array($name, self::HEAD, true)) {
				return;
			}
			$this->content(false);
			return;
		}
		$this->refuseIf(!($this->opened[$name] ?? false) || ($this->closed[$name] ?? false) || ($name === 'head' && $this->headDepth !== 0)
			|| ($name !== 'html' && ($this->closed['html'] ?? false)) || ($name === 'head' && $this->content));
		$this->closed[$name] = true;
	}

	/**
	 * Checks the end of the source: an open head never received its end tag.
	 *
	 * @return void
	 * @throws ToolFailure for a head left open
	 */
	public function finish(): void {
		$this->refuseIf($this->inHead());
	}

	/** @return bool whether a head is open */
	private function inHead(): bool {
		return ($this->opened['head'] ?? false) && !($this->closed['head'] ?? false);
	}

	/**
	 * Marks body content.
	 *
	 * @param bool $text whether the content is text
	 * @return void
	 * @throws ToolFailure for content inside head or after </body> or </html>
	 */
	private function content(bool $text): void {
		$this->refuseIf(($text && $this->inHead()) || ($this->closed['body'] ?? false) || ($this->closed['html'] ?? false));
		$this->content = true;
	}

	/**
	 * @param bool $condition refusal condition
	 * @return void
	 * @throws ToolFailure when the condition holds
	 */
	private function refuseIf(bool $condition): void {
		if ($condition) {
			throw new ToolFailure(MailDraftMessages::htmlInvalid());
		}
	}
}
