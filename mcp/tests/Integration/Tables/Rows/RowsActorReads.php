<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Rows;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/** Binds both readers to the already authenticated actor; native services stay lazy. */
final class RowsActorReads {
	/**
	 * @param string $uid Actor UID, already validated by rows-actor.php.
	 * @param IAppManager $apps Enabled app registry.
	 * @param IUserManager $users User directory of this disposable cell.
	 * @return RowsReadRequest Readers sharing this PHP request and session.
	 */
	public static function forUser(string $uid, IAppManager $apps, IUserManager $users): RowsReadRequest {
		$registry = null;
		return new RowsReadRequest(
			static function (array $arguments) use ($uid, $apps, $users, &$registry): array {
				$policy = Server::get(GrantPolicy::class);
				$registry ??= new ToolRegistry([Server::get(TablesModule::class)], $policy, $apps, $users, Server::get(LoggerInterface::class));
				return $policy->within($uid, static fn (): array => $registry->call('tables_list_rows', $arguments, $uid));
			},
			static function (array $target) use ($uid): array {
				$rows = Server::get('OCA\\Tables\\Service\\RowService');
				try {
					$found = isset($target['view_id']) ? $rows->findAllByView((int)$target['view_id'], $uid, null, null) : $rows->findAllByTable((int)$target['table_id'], $uid, null, null);
					return ['rows' => array_map(static fn (array $row): array => ['id' => (int)$row['id'], 'data' => $row['data']], $rows->formatRows($found))];
				} catch (Throwable $error) {
					return ['error' => $error::class];
				}
			},
		);
	}
}
