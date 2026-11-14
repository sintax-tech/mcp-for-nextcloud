<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Throwable;

/**
 * The content of a new file could not be written after Nextcloud created the file: an empty or incomplete file stays
 * at its path. It is never deleted and the write is never repeated; the message names the file and keeps the reason.
 */
final class FileCreationFailure extends ToolFailure {
	/**
	 * @param string $message the reason, when there is one, and the file left, written for the user
	 * @param string|null $path the path of the file left as the actor sees it, null when it is not known
	 * @param int|null $fileId the id of the file left, null when it could not be read
	 * @param Throwable|null $previous the refusal that stopped the content write, which keeps its HTTP meaning
	 */
	public function __construct(
		string $message,
		public readonly ?string $path = null,
		public readonly ?int $fileId = null,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
