<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Throwable;

/**
 * Reads the pages a Files walk found, one by one, with PageService::find: the tree builders of Collectives may write a
 * missing index page and are never called. The call is admitted once for the batch. A page Collectives no longer
 * finds (removed meanwhile, or its index removed) is left out of the answer; any other failure stops the read with the
 * module's answer, never the native message.
 */
class PageReads {
	/** @param CollectivesGateway $gateway the native find */
	public function __construct(private CollectivesGateway $gateway) {
	}

	/**
	 * @param int $cid collective id
	 * @param list<int> $ids page file ids, as {@see \OCA\Mcp\Modules\Collectives\Locating\PageFileWalk} found them
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant of the call
	 * @return list<array<string, mixed>> the pages Collectives found, read now, in the order of the ids
	 * @throws ToolFailure when the call is not admitted, or Collectives fails otherwise than with not found
	 */
	public function read(int $cid, array $ids, string $uid, string $operation): array {
		$this->gateway->admit($uid, $operation);
		$pages = [];
		foreach ($ids as $id) {
			try {
				$pages[] = $this->gateway->find($cid, $id, $uid);
			} catch (Throwable $e) {
				if (!self::notFound($e)) {
					throw CollectivesGateway::failure($e);
				}
			}
		}
		return $pages;
	}

	/**
	 * @param Throwable $e what find threw
	 * @return bool whether it is Collectives' or Files' not found, the only failure that leaves a page out
	 */
	private static function notFound(Throwable $e): bool {
		return str_ends_with($e::class, 'NotFoundException');
	}
}
