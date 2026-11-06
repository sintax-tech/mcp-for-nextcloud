<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use RuntimeException;

/** Isolated real users, policy and native actor processes shared by the two Deck issue proofs. */
final class FunctionalDeckScene {
	/** @var array<string,string> Synthetic account IDs and their local login passwords. */
	public array $accounts = [];
	/** @var array<string,string> Exact app settings restored after the scenario. */
	private array $settings = [];
	/** @var list<array{uid:string,board:int}> Native boards owned by this fixture. */
	private array $boards = [];

	/** Create isolated users through core and grant Deck access through the real policy API. */
	public function __construct() {
		$config = Server::get(IConfig::class);
		foreach ($config->getAppKeys('mcp') as $key) {
			$this->settings[$key] = $config->getAppValue('mcp', $key);
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		foreach (['a', 'b', 'service', 'outsider'] as $role) {
			$uid = 'mcp-it-dk-' . $role . '-' . bin2hex(random_bytes(4));
			$password = bin2hex(random_bytes(24));
			Server::get(IUserManager::class)->createUser($uid, $password);
			$this->accounts[$uid] = $password;
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			foreach (['read', 'create', 'edit'] as $operation) {
				$policy->setGrant($uid, 'deck', $operation, true);
			}
		}
	}

	/** @return string UID at a fixed role position: owner, editor, service, outsider. */
	public function uid(int $index): string {
		return array_keys($this->accounts)[$index];
	}

	/**
	 * @param string $owner Board owner.
	 * @param string $editor Native direct editor.
	 * @return array<string,mixed> Native IDs, never guessed.
	 */
	public function seed(string $owner, string $editor): array {
		$f = self::run($owner, 'seed', ['editor' => $editor, 'service' => $this->uid(2)]);
		$this->boards[] = ['uid' => $owner, 'board' => $f['board']];
		return $f;
	}

	/**
	 * @param string $uid Disposable actor.
	 * @param string $action Native fixture action.
	 * @param array<string,mixed> $input Owned resources.
	 * @return array<string,mixed> Fresh native state.
	 */
	public static function run(string $uid, string $action, array $input): array {
		$process = proc_open([PHP_BINARY, __DIR__ . '/functional-actor.php', $uid, $action],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start native Deck actor');
		}
		fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0) {
			throw new RuntimeException('Native Deck actor failed: ' . $error);
		}
		try {
			return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new RuntimeException('Native actor returned invalid JSON: ' . substr($out . $error, 0, 1200), 0, $e);
		}
	}

	/** Restore only this fixture's resources and the exact app settings saved on entry. */
	public function close(): void {
		foreach ($this->boards as $board) {
			self::run($board['uid'], 'cleanup', $board);
		}
		foreach (array_keys($this->accounts) as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		$config = Server::get(IConfig::class);
		foreach ($config->getAppKeys('mcp') as $key) {
			if (!array_key_exists($key, $this->settings)) {
				$config->deleteAppValue('mcp', $key);
			}
		}
		foreach ($this->settings as $key => $value) {
			$config->setAppValue('mcp', $key, $value);
		}
	}
}
