<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * One fixture user of the Mail integration test, in its own CLI request (see MailActor). Actions:
 * - accounts: the Mail accounts of the user, read natively through the Mail AccountService (fixture lookup);
 * - observe: the Mail tools listed for the user and the answers of mail_list_accounts and of mail_list_mailboxes for
 *   the own account, another user's account and an account that does not exist, through the real ToolRegistry.
 */

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Mail\MailActor;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/bootstrap.php';
[, $uid, $action] = $argv;
if (!in_array($uid, [MailActor::ALICE, MailActor::BOB], true)) {
	throw new RuntimeException('Not a disposable Mail fixture actor');
}
Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$apps = Server::get(IAppManager::class);
if (!$apps->isEnabledForAnyone('mail')) {
	throw new RuntimeException('Mail must be provisioned; this scenario never skips inside a Mail cell');
}
$apps->loadApp('mail');
$in = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

/**
 * Calls one tool through the registry, inside the policy operation of the user, as the endpoint does.
 *
 * @param ToolRegistry $registry registry with the Mail module
 * @param GrantPolicy $policy access policy
 * @param string $uid fixture user
 * @param string $tool tool name
 * @param array<string, mixed> $arguments tool arguments
 * @return array<string, mixed> the MCP result, or ['refused' => true] for a call the registry rejects
 */
function callTool(ToolRegistry $registry, GrantPolicy $policy, string $uid, string $tool, array $arguments): array {
	try {
		return $policy->within($uid, static fn (): array => $registry->call($tool, $arguments, $uid));
	} catch (InvalidArgumentException) {
		return ['refused' => true];
	}
}

switch ($action) {
	case 'accounts':
		$service = Server::get('OCA\\Mail\\Service\\AccountService');
		$out = ['accounts' => array_map(static fn (object $account): array => ['id' => (int)$account->getId(), 'email' => (string)$account->getEmail()],
			$service->findByUserId($uid))];
		break;
	case 'observe':
		$policy = Server::get(GrantPolicy::class);
		$registry = new ToolRegistry([Server::get(MailModule::class)], $policy, $apps, Server::get(IUserManager::class),
			Server::get(LoggerInterface::class));
		$out = [
			'tools' => $policy->within($uid, static fn (): array => array_column($registry->list($uid), 'name')),
			'accounts' => callTool($registry, $policy, $uid, 'mail_list_accounts', []),
			'own' => callTool($registry, $policy, $uid, 'mail_list_mailboxes', ['account_id' => $in['own']]),
			'foreign' => callTool($registry, $policy, $uid, 'mail_list_mailboxes', ['account_id' => $in['foreign']]),
			'missing' => callTool($registry, $policy, $uid, 'mail_list_mailboxes', ['account_id' => $in['missing']]),
		];
		break;
	default:
		throw new RuntimeException('Unknown Mail actor action ' . $action);
}
echo json_encode($out, JSON_THROW_ON_ERROR);
