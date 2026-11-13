<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Integration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Container\ContainerInterface;

/** Native Handler::get only; opaque projection never calls a formatter or origin service. */
final class NotificationsGateway {
	/** @param ContainerInterface $container lazy native handler @param IManager $notifications in-memory filter factory @param NotificationsSurface $surface read signature guard */
	public function __construct(private ContainerInterface $container, private IManager $notifications, private NotificationsSurface $surface) {}
	/**
	 * @param string $actor authenticated owner, never a caller-supplied target
	 * @param int $limit strict 1..200 bound
	 * @return array{items:list<array{id:int,app:string,datetime:string}>,truncated:bool} metadata whitelist
	 * @throws PolicyUnavailable on incompatible native output; never widen to an unfiltered read
	 */
	public function latest(string $actor, int $limit): array {
		if ($actor === '' || $limit < 1 || $limit > 200 || !$this->surface->supported()) throw new PolicyUnavailable('The notification inbox is unavailable.');
		$filter = $this->notifications->createNotification();
		$filter->setUser($actor);
		// The native factory initializes the timestamp to epoch; setDateTime deliberately rejects epoch.
		// Verify the empty filter rather than narrowing the query to the current time.
		if ($filter->getUser() !== $actor || $filter->getDateTime()->getTimestamp() !== 0 || $filter->getApp() !== ''
			|| $filter->getObjectType() !== '' || $filter->getObjectId() !== '' || $filter->getSubject() !== '' || $filter->getMessage() !== '') throw new PolicyUnavailable('The notification inbox is unavailable.');
		$rows = $this->container->get(NotificationsSurface::HANDLER)->get($filter, $limit);
		if (!is_array($rows) || count($rows) > $limit) throw new PolicyUnavailable('The notification inbox is unavailable.');
		$items = []; $previous = PHP_INT_MAX;
		foreach ($rows as $id => $notification) {
			if (!is_int($id) || $id < 1 || $id >= $previous || !$notification instanceof INotification || $notification->getUser() !== $actor) throw new PolicyUnavailable('The notification inbox is unavailable.');
			$previous = $id;
			$items[] = ['id' => $id, 'app' => $notification->getApp(),
				'datetime' => DateTimeImmutable::createFromInterface($notification->getDateTime())->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)];
		}
		return ['items' => $items, 'truncated' => count($rows) === $limit];
	}
}
