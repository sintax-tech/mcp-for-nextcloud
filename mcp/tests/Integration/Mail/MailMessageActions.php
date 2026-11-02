<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The actions of mail-actor.php that the message tools tests need, run in the actor's own process as one fixture user,
 * through the real ToolRegistry and inside the policy operation of the user, as the endpoint does:
 * - tools: the tools listed for the user, with their annotations and input properties;
 * - calls: the answers of a list of [tool, arguments] calls, each with the Unix time it started and ended;
 * - pages: every page of one listing or search, following nextCursor.
 */
final class MailMessageActions {
	/** Most pages one `pages` action follows. */
	private const MAX_PAGES = 100;

	/**
	 * Runs one action.
	 *
	 * @param string $action tools, calls or pages
	 * @param string $uid fixture user the process acts as
	 * @param IAppManager $apps app manager, with Mail loaded
	 * @param array<string, mixed> $in JSON input of the action
	 * @return list<mixed> the output of the action
	 * @throws RuntimeException for an unknown action
	 */
	public static function run(string $action, string $uid, IAppManager $apps, array $in): array {
		$policy = Server::get(GrantPolicy::class);
		$registry = new ToolRegistry([Server::get(MailModule::class)], $policy, $apps, Server::get(IUserManager::class),
			Server::get(LoggerInterface::class), null, Server::get(\OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry::class));
		return match ($action) {
			'tools' => self::tools($registry, $policy, $uid),
			'calls' => self::calls($registry, $policy, $uid, $in['calls']),
			'pages' => self::pages($registry, $policy, $uid, (string)$in['tool'], $in['arguments']),
			default => throw new RuntimeException('Unknown Mail message action ' . $action),
		};
	}

	/**
	 * The tools listed for the user.
	 *
	 * @param ToolRegistry $registry registry with the Mail module
	 * @param GrantPolicy $policy access policy
	 * @param string $uid fixture user
	 * @return list<array{name:string, annotations:array<string, mixed>, properties:list<string>}> the tools
	 */
	private static function tools(ToolRegistry $registry, GrantPolicy $policy, string $uid): array {
		return array_map(static fn (array $tool): array => ['name' => $tool['name'], 'annotations' => $tool['annotations'],
			'properties' => array_keys((array)$tool['inputSchema']['properties'])],
			$policy->within($uid, static fn (): array => $registry->list($uid)));
	}

	/**
	 * The answers of a list of calls, with their timing.
	 *
	 * @param ToolRegistry $registry registry with the Mail module
	 * @param GrantPolicy $policy access policy
	 * @param string $uid fixture user
	 * @param list<array{0:string, 1:array<string, mixed>}> $calls tool and arguments of each call
	 * @return list<array{result:array<string, mixed>, started:float, ended:float}> one entry per call
	 */
	private static function calls(ToolRegistry $registry, GrantPolicy $policy, string $uid, array $calls): array {
		$out = [];
		foreach ($calls as [$tool, $arguments]) {
			$started = microtime(true);
			$result = self::call($registry, $policy, $uid, $tool, $arguments);
			$out[] = ['result' => $result, 'started' => $started, 'ended' => microtime(true)];
		}
		return $out;
	}

	/**
	 * Every page of one listing or search.
	 *
	 * @param ToolRegistry $registry registry with the Mail module
	 * @param GrantPolicy $policy access policy
	 * @param string $uid fixture user
	 * @param string $tool mail_list_messages or mail_search_messages
	 * @param array<string, mixed> $arguments arguments of the first page
	 * @return list<array<string, mixed>> the MCP result of each page
	 */
	private static function pages(ToolRegistry $registry, GrantPolicy $policy, string $uid, string $tool, array $arguments): array {
		$out = [];
		$cursor = null;
		do {
			$result = self::call($registry, $policy, $uid, $tool, $arguments + ($cursor === null ? [] : ['cursor' => $cursor]));
			$page = isset($result['content'][0]['text']) ? json_decode($result['content'][0]['text'], true) : null;
			$out[] = $result;
			$cursor = is_array($page) && !($result['isError'] ?? false) ? $page['nextCursor'] : null;
		} while ($cursor !== null && count($out) < self::MAX_PAGES);
		return $out;
	}

	/**
	 * One call through the registry, inside the policy operation of the user.
	 *
	 * @param ToolRegistry $registry registry with the Mail module
	 * @param GrantPolicy $policy access policy
	 * @param string $uid fixture user
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments tool arguments
	 * @return array<string, mixed> the MCP result, or ['refused' => true] for a call the registry rejects
	 */
	private static function call(ToolRegistry $registry, GrantPolicy $policy, string $uid, string $tool, array $arguments): array {
		try {
			return $policy->within($uid, static fn (): array => $registry->call($tool, $arguments, $uid));
		} catch (InvalidArgumentException) {
			return ['refused' => true];
		}
	}
}
