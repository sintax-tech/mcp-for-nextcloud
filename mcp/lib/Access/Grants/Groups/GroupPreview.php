<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\ReceiptExpired;
use OCA\Mcp\Access\Policy\ReceiptNotFound;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

/** Loads a stored group preview for the administrator who created it. */
final class GroupPreview {
	/** Seconds a group preview may be saved (contracts §2.3). */
	public const PREVIEW_TTL = 120;

	/**
	 * Composes this participant with the existing event store and clock.
	 *
	 * @param PolicyEventsMapper $events Transactional event mapper.
	 * @param ITimeFactory $time Official clock.
	 */
	public function __construct(
		private PolicyEventsMapper $events,
		private ITimeFactory $time,
	) {}
	/**
	 * Loads a group preview for its own administrator, with the same ownership-before-expiry rule as receipts.
	 *
	 * @return array{gid:string, grants:array, revision:string, memberHash:string, life:?array}
	 * @throws ReceiptNotFound|ReceiptExpired|PolicyUnavailable
	 */
	public function preview(string $previewId, string $actor): array {
		if (!PolicyEventsMapper::validId($previewId)) {
			throw new ReceiptNotFound();
		}
		try {
			$event = $this->events->find($previewId);
			$payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_PREVIEW ? null
				: PolicyEventPayload::validate(PolicyEventsMapper::KIND_PREVIEW, $event['payload']);
		} catch (\UnexpectedValueException $e) {
			throw new PolicyUnavailable('Invalid stored preview', previous: $e);
		} catch (DatabaseException $e) {
			throw new PolicyUnavailable(previous: $e);
		}
		if ($payload === null || $payload['actor'] !== $actor) {
			throw new ReceiptNotFound();
		}
		if ($this->time->getTime() >= $event['createdAt'] + GroupPreview::PREVIEW_TTL) {
			throw new ReceiptExpired();
		}
		return $payload;
	}
}
