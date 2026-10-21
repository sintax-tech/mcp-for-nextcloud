<?php
// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Support;

use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\ResourceScope\{ScopeActivation, NativeResourceEnumerator, ResourceScopeWriter};
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Db\{PolicyStateMapper, PolicyEventsMapper, AuthUsersMapper, RecheckMapper, GroupGrantsMapper, GroupLifeMapper, ResourceScopeMapper};
use OCA\Mcp\OAuth\OAuthStore;
use OCP\{IDBConnection, IConfig, IAppConfig, IUserManager, Server};
use OCP\Config\IUserConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/** Native composition with explicit injection points for database race/failure observations; no forwarding API. */
final class NativePolicyServices {
    public readonly PolicyServices $commands;
    public readonly ResourceScopeWriter $scope;

    public function __construct(IDBConnection $db, PolicyStateMapper $state, PolicyEventsMapper $events,
        IConfig $config, IUserConfig $usersConfig, IAppConfig $apps, IUserManager $users, ITimeFactory $time,
        AuthUsersMapper $auth, RecheckMapper $rechecks, PolicyEventProcessor $processor, GroupGrantsMapper $groups,
        GroupLifeMapper $life, OAuthStore $oauth, ?ResourceScopeMapper $scopes = null,
        ?NativeResourceEnumerator $resources = null, ScopeActivation $activation = new ScopeActivation()) {
        $transaction = new PolicyTransaction($db, $state, $events, $usersConfig, $apps, $processor);
        $this->commands = new PolicyServices($transaction, $config, $apps, $users, $time, $state, $events,
            $auth, $rechecks, $groups, $life, $oauth, $scopes ?? new ResourceScopeMapper($db), $activation);
        $this->scope = new ResourceScopeWriter($transaction, new PolicyUsers($users), $activation,
            $resources ?? Server::get(NativeResourceEnumerator::class), $this->commands->scopeMutations);
    }
}
