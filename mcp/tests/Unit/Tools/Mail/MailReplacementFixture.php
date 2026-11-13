<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Platform\FileAccess\FileReplacement;

/** Replacement port for existing Mail characterization; replacement effects use the real Files adapter in new tests. */
final class MailReplacementFixture implements FileReplacement {
	/** {@inheritDoc} */
	public function inspect(string $userId, string $path, ?string $etag): array { throw new \LogicException('Use the protected Files fixture for replacements'); }
	/** {@inheritDoc} */
	public function replace(string $userId, string $path, string $content, bool $shared, int $fileId, string $etag): array { throw new \LogicException('Use the protected Files fixture for replacements'); }
}
