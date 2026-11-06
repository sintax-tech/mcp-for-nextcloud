<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\McpDeckProbe;

use OCP\Notification\INotification;
use OCP\Server;

/** A failing native delivery app after the manager's deferral boundary, not a fake IManager. */
final class Delivery implements \OCP\Notification\IDeferrableApp {
	private bool $deferred = false;
	private bool $pending = false;

	/** @return bool Whether this isolated synthetic request asked for the delivery control. */
	private function active(): bool {
		$nonce = Server::get(\OCP\IConfig::class)->getAppValue('mcp_deck_probe', 'nonce', '');
		return $nonce !== '' && hash_equals($nonce, $_SERVER['HTTP_X_MCP_DECK_PROBE'] ?? '');
	}

	/** @param string $phase Only metadata, never notification contents or request credentials. */
	private function record(string $phase): void {
		if (!$this->active()) { return; }
		$nonce = Server::get(\OCP\IConfig::class)->getAppValue('mcp_deck_probe', 'nonce');
		file_put_contents('/tmp/mcp-deck-probe-' . $nonce . '.jsonl', json_encode([
			'phase' => $phase, 'deferred' => $this->deferred,
			'route' => str_contains($_SERVER['REQUEST_URI'] ?? '', '/apps/mcp') ? 'mcp' : 'deck',
		], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
	}

	/** @param INotification $notification Real notification passed by the native manager. */
	public function notify(INotification $notification): void {
		if (!$this->active() || $notification->getApp() !== 'deck') { return; }
		$this->record('notify');
		if (!$this->deferred) { throw new \RuntimeException('Synthetic delivery failure'); }
		$this->pending = true;
	}

	/** @param INotification $notification Real processed notification. */
	public function markProcessed(INotification $notification): void {
		$this->record('processed');
	}

	/** @param INotification $notification Count request. @return int No stored notifications owned by this probe. */
	public function getCount(INotification $notification): int { return 0; }
	/** Observe only actual deferral called by the real manager. */
	public function defer(): void { $this->deferred = true; $this->record('defer'); }
	/** Fail only during actual flush if this request queued delivery. */
	public function flush(): void {
		$this->record('flush');
		if ($this->active() && $this->pending) { throw new \RuntimeException('Synthetic deferred delivery failure'); }
		$this->deferred = false;
	}
}
