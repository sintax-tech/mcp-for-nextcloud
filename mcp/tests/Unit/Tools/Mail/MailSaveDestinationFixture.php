<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Platform\FileAccess\NewFileDestination;

/** Metadata-only destination for existing Mail plan characterization; save effects use the real Files adapter in new tests. */
final class MailSaveDestinationFixture implements NewFileDestination {
	/** {@inheritDoc} */
	public function inspect(string $userId, string $path): array { return ['path' => $path, 'folderId' => 1, 'access' => [], 'shared' => [], 'requiresSharedConfirmation' => false]; }
	/** {@inheritDoc} */
	public function create(string $userId, string $path, string $content, bool $shared, int $folderId): array { throw new \LogicException('Use the protected Files fixture for save effects'); }
}
