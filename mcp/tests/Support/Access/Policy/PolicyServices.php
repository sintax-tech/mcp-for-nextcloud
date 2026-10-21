<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Access\Policy;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Access\Grants\Commands\GrantCommands;
use OCA\Mcp\Access\Grants\Commands\GrantMutations;
use OCA\Mcp\Access\Grants\Commands\GrantInheritance;
use OCA\Mcp\Access\Grants\Commands\ServiceCommands;
use OCA\Mcp\Access\Grants\Groups\GroupGrantCommands;
use OCA\Mcp\Access\Grants\Groups\LifecycleHolds;
use OCA\Mcp\Access\Policy\Receipts\ReceiptStore;
use OCA\Mcp\Access\Policy\Receipts\ReceiptUndo;
use OCA\Mcp\Access\ResourceScope\ScopeMutations;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\OAuth\Revocation\CredentialCuts;
use OCA\Mcp\OAuth\Revocation\CredentialCommands;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\AppFramework\Utility\ITimeFactory;

/** Test composition of the real command owners. Calls go directly to an owner, never through a forwarding facade. */
final class PolicyServices {
    public readonly GrantCommands $grants;
    public readonly ServiceCommands $service;
    public readonly GroupGrantCommands $groups;
    public readonly CredentialCommands $credentials;
    public readonly ReceiptUndo $undo;
    public readonly LifecycleHolds $holds;
    public readonly GrantMutations $mutations;
    public readonly ScopeMutations $scopeMutations;

    public function __construct(
        public readonly PolicyTransaction $transaction,
        IConfig $config, IAppConfig $apps, IUserManager $users, ITimeFactory $time,
        PolicyStateMapper $state, PolicyEventsMapper $events, AuthUsersMapper $authUsers,
        RecheckMapper $rechecks, GroupGrantsMapper $grants, GroupLifeMapper $life,
        OAuthStore $oauth, ResourceScopeMapper $scopes, ScopeActivation $activation = new ScopeActivation(),
    ) {
        $known = new PolicyUsers($users);
        $this->holds = new LifecycleHolds($life);
        $cuts = new CredentialCuts($authUsers, $state, $rechecks);
        $this->mutations = new GrantMutations($config, $apps, $cuts, $this->holds);
        $inheritance = new GrantInheritance();
        $this->scopeMutations = new ScopeMutations($scopes);
        $this->grants = new GrantCommands($transaction, $this->mutations, $inheritance, $known);
        $this->service = new ServiceCommands($transaction, $this->mutations);
        $this->credentials = new CredentialCommands($transaction, $authUsers, $rechecks, $config, $known, $this->mutations, $cuts, $this->holds);
        $this->groups = new GroupGrantCommands($transaction, $life, $grants, $authUsers, $oauth, $time, $events, $cuts);
        $this->undo = new ReceiptUndo($transaction, new ReceiptStore($events, $time), $this->mutations, $inheritance, $known, $this->scopeMutations, $activation);
    }
}
