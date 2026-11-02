<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use OCA\Mcp\Access\Policy\PolicyEventProcessor;
use OCA\Mcp\Db\{PolicyEventsMapper, PolicyStateMapper};
use OCP\Config\IUserConfig;
use OCP\{IAppConfig, IDBConnection};

/** Explicit native composition of one writer's execution; owns no policy decisions or transaction of its own. */
final class PolicyAssembly {
	/**
	 * @param IDBConnection $db The official shared connection.
	 * @param PolicyStateMapper $state Global state on that connection.
	 * @param PolicyEventsMapper $events Outbox mapper selected before assembly, including instrumentation.
	 * @param IUserConfig $users Official user cache service.
	 * @param IAppConfig $apps Official app cache service.
	 * @param PolicyEventProcessor $processor Post-commit delivery service.
	 * @param PolicyContext $context One mutable context for this writer and all its participants.
	 * @return PolicyExecution Entry points over the only transaction engine.
	 */
	public static function execution(IDBConnection $db, PolicyStateMapper $state, PolicyEventsMapper $events, IUserConfig $users, IAppConfig $apps, PolicyEventProcessor $processor, PolicyContext $context): PolicyExecution {
		return new PolicyExecution(new PolicyTransaction($db, $state, $events, $users, $apps, $processor, $context));
	}
}
