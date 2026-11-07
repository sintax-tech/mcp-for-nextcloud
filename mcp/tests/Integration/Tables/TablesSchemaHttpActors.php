<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use OCP\IConfig;

/** Owns the schema HTTP actors' access prerequisites and restores their previous policy preferences. */
final class TablesSchemaHttpActors {
	/** @var array<string, array<string, array{raw:?string, allowed:bool}>> Captured cells, including absent overrides. */
	private array $before = [];

	/**
	 * @param GrantPolicy $policy Official writer and resolver for disposable actors.
	 * @param IConfig $config Raw preferences for lossless restoration of absent keys and stored values.
	 */
	public function __construct(private GrantPolicy $policy, private IConfig $config) {
	}

	/** @return void Capture both actors before changing anything, including before the first Bearer issuance. */
	public function enable(): void {
		if ($this->before !== []) {
			return;
		}
		foreach ([WriteCellScene::OWNER => ['read', 'schema', 'share'], WriteCellScene::READER => ['read']] as $uid => $operations) {
			$cells = [
				'eligible' => $this->policy->eligible($uid),
				'connected' => $this->policy->connected($uid),
			];
			foreach ($operations as $operation) {
				$cells['grant_tables_' . $operation] = $this->policy->granted($uid, 'tables', $operation);
			}
			$keys = $this->config->getUserKeys($uid, 'mcp');
			foreach ($cells as $key => $allowed) {
				$raw = in_array($key, $keys, true) ? $this->config->getUserValue($uid, 'mcp', $key) : null;
				$this->before[$uid][$key] = ['raw' => $raw, 'allowed' => $allowed];
			}
		}
		foreach (array_keys($this->before) as $uid) {
			$this->policy->setEligible($uid, true);
			$this->policy->setConnected($uid, true);
			$this->policy->setGrant($uid, 'tables', 'read', true);
		}
	}

	/** @return void Restore resolved access through the writer, then exact raw preferences, including absent keys. */
	public function restore(): void {
		foreach ($this->before as $uid => $cells) {
			foreach ($cells as $key => $before) {
				if ($key === 'eligible') {
					$this->policy->setEligible($uid, $before['allowed']);
				} elseif ($key === 'connected') {
					$this->policy->setConnected($uid, $before['allowed']);
				} else {
					$this->policy->setGrant($uid, 'tables', substr($key, strlen('grant_tables_')), $before['allowed']);
				}
				if ($before['raw'] === null) {
					$this->config->deleteUserValue($uid, 'mcp', $key);
				} else {
					$this->config->setUserValue($uid, 'mcp', $key, $before['raw']);
				}
			}
		}
		$this->before = [];
	}
}
