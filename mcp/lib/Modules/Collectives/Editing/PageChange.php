<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Editing;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesMarkdownPolicy;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Platform\Text\UniqueSnippet;

/**
 * The three ways `collectives_edit` changes the Markdown of a page, which exclude each other: `content` rewrites it
 * whole, `append` adds text at the end on a line of its own, and `old`/`new` replaces one passage that appears exactly
 * once. The change is applied to the page as it is when the plan is made and again when it is confirmed.
 */
final class PageChange {
	/** Rewrites the whole page. */
	public const CONTENT = 'content';
	/** Adds text at the end. */
	public const APPEND = 'append';
	/** Replaces one unique passage. */
	public const REPLACE = 'replace';

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/** @return array<string, array<string, mixed>> the schema properties of the three modes, in published order */
	public static function properties(): array {
		return [
			'content' => ['type' => 'string', 'maxLength' => CollectivesMarkdownPolicy::MAX_BYTES, 'description' => CollectivesMessages::PARAM_CONTENT],
			'append' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 65536, 'description' => CollectivesMessages::PARAM_APPEND],
			'old' => ['type' => 'string', 'minLength' => 1, 'maxLength' => UniqueSnippet::MAX_BYTES, 'description' => CollectivesMessages::PARAM_OLD],
			'new' => ['type' => 'string', 'maxLength' => 65536, 'description' => CollectivesMessages::PARAM_NEW],
		];
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @return string the one mode the arguments ask for
	 * @throws ToolFailure when more than one mode is given
	 * @throws InvalidArgumentException when none is, or old comes without new (or the reverse)
	 */
	public static function mode(array $arguments): string {
		$modes = array_keys(array_filter([
			self::CONTENT => isset($arguments['content']),
			self::APPEND => isset($arguments['append']),
			self::REPLACE => isset($arguments['old']) || isset($arguments['new']),
		]));
		if (count($modes) > 1) {
			throw new ToolFailure(CollectivesMessages::editModesExclusive());
		}
		if ($modes === [self::REPLACE] && !isset($arguments['old'], $arguments['new'])) {
			throw new InvalidArgumentException('Missing argument: ' . (isset($arguments['old']) ? 'new' : 'old'));
		}
		return $modes[0] ?? throw new InvalidArgumentException('Missing argument: content');
	}

	/**
	 * @param string $mode one of the mode constants
	 * @param string $current the page as stored now
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @return string the Markdown the change leaves
	 * @throws ToolFailure when the passage to replace is not valid UTF-8, absent or not unique
	 */
	public static function apply(string $mode, string $current, array $arguments): string {
		if ($mode === self::CONTENT) {
			return (string)$arguments['content'];
		}
		if ($mode === self::REPLACE) {
			UniqueSnippet::assertText((string)$arguments['old']);
			return UniqueSnippet::replace($current, (string)$arguments['old'], (string)$arguments['new']);
		}
		$text = (string)$arguments['append'];
		if ($current === '') {
			return $text;
		}
		$position = strpos($current, "\n");
		$eol = $position !== false && $position > 0 && $current[$position - 1] === "\r" ? "\r\n" : "\n";
		$text = str_replace("\n", $eol, str_replace("\r\n", "\n", $text));
		$terminated = str_ends_with($current, "\n");
		$joined = $current . ($terminated ? '' : $eol) . $text;
		return $terminated && !str_ends_with($text, "\n") ? $joined . $eol : $joined;
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @return string the etag a confirmed edit applies to
	 * @throws ToolFailure when the call has none
	 */
	public static function etag(array $arguments): string {
		$etag = trim((string)($arguments['etag'] ?? ''), '" ');
		return $etag !== '' ? $etag : throw new ToolFailure(CollectivesMessages::editNeedsEtag());
	}
}
