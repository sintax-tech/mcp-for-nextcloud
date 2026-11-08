<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCA\Mcp\Modules\Files\Sharing\ShareFailureLog;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only the native duplicate-share type is quiet; generic failures remain warnings in both callers.
 */
final class ShareFailureLogTest extends TestCase {
	/**
	 * Do not infer severity from a refusal-looking message, including at the unshare boundary.
	 *
	 * @return void
	 */
	public function testOnlyTheKnownDuplicateShareTypeIsLoggedAtDebug(): void {
		$duplicate = new AlreadySharedException('private duplicate', $this->createMock(IShare::class));
		foreach ([$duplicate, new GenericShareException('private duplicate'), new \RuntimeException('private duplicate'),
			new \InvalidArgumentException('private duplicate')] as $failure) {
			foreach (['MCP share refused by Nextcloud', 'MCP unshare refused by Nextcloud'] as $message) {
				$logger = $this->createMock(LoggerInterface::class);
				$expected = $failure instanceof AlreadySharedException;
				$logger->expects(self::once())->method($expected ? 'debug' : 'warning')->with($message . ': {exception_class} (code {exception_code})',
					['app' => 'mcp', 'exception_class' => $failure::class, 'exception_code' => 0]);
				$logger->expects(self::never())->method($expected ? 'warning' : 'debug');
				ShareFailureLog::record($logger, $message, $failure);
			}
		}
	}
}
