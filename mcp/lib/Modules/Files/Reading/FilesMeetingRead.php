<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Files\Tools\ReadFile;
use OCA\Mcp\Platform\Meetings\MeetingFileRead;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;

/**
 * The file read of a meeting dossier, through the work class of files_read.
 *
 * The registry's own check (grant and Guests) runs again, then the published schema. Scope, aliases and the
 * actor's view stay inside that reader. The call reads one path; it does not write the node and it does not touch
 * a comment read marker.
 */
final class FilesMeetingRead implements MeetingFileRead {
	/**
	 * @param ToolAccess $access the registry's own decision on grant and Guests
	 * @param FileTextListing $files the read of files_read
	 */
	public function __construct(
		private ToolAccess $access,
		private FileTextListing $files,
	) {
	}

	/** {@inheritDoc} */
	public function read(string $userId, array $arguments): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(ReadFile::definition()->name));
		}
		return $this->files->handle(ArgumentValidator::validate(ReadFile::definition()->inputSchema, $arguments), $userId);
	}

	/**
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call files_read now
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(string $userId): bool {
		return $this->access->allowed(ReadFile::definition()->toArray(), $userId);
	}
}
