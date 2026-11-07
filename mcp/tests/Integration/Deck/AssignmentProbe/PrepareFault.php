<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\McpDeckProbe;

use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use OCP\Server;

/**
 * A notifier that fails while Deck notifications are prepared, standing in for any notifier, key or encryption step
 * the Notifications app runs only for a recipient with a push device (issue #26). Off unless the test switches it on,
 * for every recipient (`1`) or for one (`user:<uid>`).
 */
final class PrepareFault implements INotifier {
	/** @return string Notifier identifier. */
	public function getID(): string {
		return 'mcp_deck_probe';
	}

	/** @return string Human readable name. */
	public function getName(): string {
		return 'Disposable Deck probe';
	}

	/**
	 * @param INotification $notification Notification being prepared by the real manager.
	 * @param string $languageCode Language of the recipient.
	 * @return INotification Never returns for a Deck notification while the switch is on.
	 * @throws \RuntimeException While the switch is on, for Deck notifications.
	 * @throws UnknownNotificationException For everything else.
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		$fault = Server::get(\OCP\IConfig::class)->getAppValue('mcp_deck_probe', 'prepare_fault', '');
		if ($notification->getApp() === 'deck' && ($fault === '1' || $fault === 'user:' . $notification->getUser())) {
			throw new \RuntimeException('Synthetic notifier failure');
		}
		throw new UnknownNotificationException();
	}
}
