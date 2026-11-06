<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Formats reading messages. */
final class ReadingMessages {
	/** @return string description of files_list */
	public static function listTool(): string {
		return 'List files and folders from a user Nextcloud directory.';
	}

	/** @return string description of files_search */
	public static function searchTool(): string {
		return 'Search files by name or content in the user folder on Nextcloud.';
	}

	/** @return string description of files_read */
	public static function readTool(): string {
		return 'Read the text of a file from Nextcloud (plain text/markdown directly; PDF/DOCX/ODT via text extraction).';
	}

	/** @return string description of files_tree */
	public static function treeTool(): string {
		return 'List the file tree of a folder, with the owner of each item and capacity limits. '
			. 'Use to plan a reorganization without one call per folder. Enforces depth and entry '
			. 'limits, and warns when truncated.';
	}

	/** @return string description of the search query parameter */
	public static function searchQuery(): string {
		return 'Search query to find in file names or content.';
	}

	/** @return string description of the search limit parameter */
	public static function searchLimit(): string {
		return 'Maximum number of results to return (1-100, default 25).';
	}

	/** @return string description of the search mode parameter */
	public static function searchMode(): string {
		return 'Search mode: "auto" uses full-text content search when available, falling back to name search; "name" searches only file names.';
	}

	/** @return string notice when full-text search is not available */
	public static function searchNameOnlyNotice(): string {
		return Translator::t('Full-text search is not available. Search was performed by file name only.');
	}

	/** @return string notice when full-text search threw an error and fell back */
	public static function searchFallbackNotice(): string {
		return Translator::t('Full-text search encountered an error; search was performed by file name only.');
	}

	/** @return string the file could not be opened */
	public static function openFailed(): string {
		return Translator::t('Could not open file.');
	}

	/** @return string the file could not be read */
	public static function readFailed(): string {
		return Translator::t('Could not read file.');
	}

	/** @return string an unsupported format for text extraction */
	public static function unsupportedFormat(): string {
		return Translator::t('Unsupported file format for text extraction.');
	}

	/**
	 * @param int $limit read limit in bytes
	 * @return string a file over the read limit
	 */
	public static function readTooLarge(int $limit): string {
		return Translator::t('File exceeds the read limit of %s bytes.', [(string)$limit]);
	}

	/**
	 * @param string $name file name
	 * @return string a corrupt document whose text could not be extracted
	 */
	public static function notExtracted(string $name): string {
		return Translator::t('[could not extract text from %s]', [$name]);
	}

	/** @return string appends to text cut by files_read */
	public static function textTruncated(): string {
		return Translator::t('[content truncated]');
	}

}
