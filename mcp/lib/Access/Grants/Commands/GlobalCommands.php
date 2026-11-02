<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCA\Mcp\Db\PolicyEventsMapper;

/** Global switch commands with the existing epoch and audit effects. */
final class GlobalCommands implements GlobalCommandsContract {
	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param CellWriter $cells Shared raw cell writes and internal commands.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private CellWriter $cells,
	) {}

	/** {@inheritDoc} */
	public function setGlobalEnabled(bool $enabled): void {
		$this->cells->change(null, GrantPolicy::SERVICE_KEY, $enabled ? '1' : '0');
	}

	/** {@inheritDoc} */
	public function adminSetGlobalEnabled(string $expectedRevision, string $actor, bool $enabled): array {
		if (!PolicyEventPayload::validUid($actor)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$value = $enabled ? '1' : '0';
		$result = $this->execution->runAdmin($expectedRevision, [], fn () => null,
			function ($snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $value): bool {
				$change = $this->cells->writeCell(null, GrantPolicy::SERVICE_KEY, $value);
				if ($change === null) {
					return false;
				}
				$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_SERVICE,
					$current['revision'], PolicyExecution::next($current['revision']), null, null, [$change]));
				return true;
			});
		return ['revision' => $result['revision'], 'changed' => $result['changed']];
	}
}
