<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCP\Files\File;

/**
 * The file-type rule of the Collectives tools (decision C4): only the Markdown file of a page the page service
 * resolved is ever written, with valid UTF-8 text. It replaces, for these tools only, the Files allowlist of issue
 * #15; `files_*` tools keep that allowlist for the very same folder. Bound explicitly in CollectivesRegistration.
 */
class CollectivesMarkdownPolicy {
	/** Largest page read or written, in bytes. */
	public const MAX_BYTES = 262144;

	/**
	 * @param File $file the page file the page service resolved
	 * @param string $content what would be stored
	 * @throws ToolFailure when the file is not Markdown, the text is not UTF-8 or it is over the size limit
	 */
	public function assertWritable(File $file, string $content): void {
		if (!str_ends_with($file->getName(), '.md')) {
			throw new ToolFailure(CollectivesMessages::notMarkdown());
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			throw new ToolFailure(CollectivesMessages::invalidUtf8());
		}
		if (strlen($content) > self::MAX_BYTES) {
			throw new ToolFailure(CollectivesMessages::pageTooLarge(self::MAX_BYTES));
		}
	}
}
