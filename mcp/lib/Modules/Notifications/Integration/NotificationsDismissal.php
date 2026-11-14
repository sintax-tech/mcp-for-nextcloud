<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Integration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\INotification;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * One-id dismiss through the native Notifications endpoint path.
 *
 * Preview only calls getById. The confirmed call passes the loaded notification into deleteById
 * (notifier callbacks, then the user-filtered delete) and pushes only when that delete reports success.
 */
final class NotificationsDismissal {
	/** @param ContainerInterface $container lazy native handler and push @param NotificationsSurface $surface dismiss signature guard @param IUserSession $session impersonation and signed-in account @param GrantPolicy $grants dismiss grant, re-read on every plan and effect */
	public function __construct(
		private ContainerInterface $container,
		private NotificationsSurface $surface,
		private IUserSession $session,
		private GrantPolicy $grants,
	) {
	}

	/**
	 * @param string $actor authenticated account, never an argument
	 * @param int $id notification id of 1 or greater
	 * @return array{id:int,found:bool,app:?string,datetime:?string,message:string,warning:string} inert plan
	 * @throws PolicyUnavailable when dismiss is not available on this installation
	 * @throws ToolFailure when impersonation, the signed-in account or the grant refuses the plan
	 */
	public function describe(string $actor, int $id): array {
		$this->admit($actor);
		$row = $this->before($this->handler(), $actor, $id);
		if ($row === null) {
			return $this->plan($id, false, null, null,
				Translator::t('No notification with this id is in your inbox. A missing id and an id owned by somebody else are reported the same way, and nothing is removed.'),
				Translator::t('Nothing is removed.'));
		}
		return $this->plan($id, true, $row['app'], $row['datetime'],
			Translator::t('Dismissing this notification is permanent. It cannot be restored, and devices are told to drop it.'),
			Translator::t('This cannot be undone.'));
	}

	/**
	 * @param string $actor authenticated account, never an argument
	 * @param int $id notification id of 1 or greater
	 * @return array{id:int,outcome:string,still_present:bool|null,push:string,partial:bool,app:?string,datetime:?string,message:string} read-back receipt
	 * @throws PolicyUnavailable when dismiss is not available, or the first read fails before any effect
	 * @throws ToolFailure when impersonation, the signed-in account or the grant refuses the effect
	 */
	public function apply(string $actor, int $id): array {
		$this->admit($actor);
		$handler = $this->handler();
		$row = $this->before($handler, $actor, $id);
		if ($row === null) {
			return $this->receipt($id, 'absent', false, 'not_sent', false, null, null,
				Translator::t('No notification with this id is in your inbox. A missing id and an id owned by somebody else are reported the same way, and nothing is removed.'));
		}
		$deleted = false;
		$uncertain = false;
		try {
			$deleted = $handler->deleteById($id, $actor, $row['notification']);
			if (!is_bool($deleted)) {
				$deleted = false;
				$uncertain = true;
			}
		} catch (Throwable) {
			// A notifier may already have run. Do not retry and do not push.
			$uncertain = true;
		}
		$push = 'not_sent';
		if ($deleted && !$uncertain) {
			try {
				$this->push($actor, $id, $row['app']);
				$push = 'sent';
			} catch (Throwable) {
				$push = 'uncertain';
				$uncertain = true;
			}
		}
		try {
			$again = $this->fetch($handler, $actor, $id);
			$present = $again !== null;
		} catch (Throwable) {
			return $this->receipt($id, 'uncertain', null, $push, true, $row['app'], $row['datetime'],
				Translator::t('The notification may have been dismissed, but the result is uncertain. It was not tried again.'));
		}
		if (!$uncertain && $deleted && $present === false && $push === 'sent') {
			return $this->receipt($id, 'dismissed', false, 'sent', false, $row['app'], $row['datetime'],
				Translator::t('The notification was dismissed. A later read shows it is gone.'));
		}
		return $this->receipt($id, 'uncertain', $present, $push, true, $row['app'], $row['datetime'],
			Translator::t('The notification may have been dismissed, but the result is uncertain. It was not tried again.'));
	}

	/**
	 * @param string $actor authenticated account
	 * @throws PolicyUnavailable when the native dismiss shape is missing
	 * @throws ToolFailure when the session is impersonating, is a different account, or lacks the grant
	 */
	private function admit(string $actor): void {
		if ($actor === '' || !$this->surface->dismissSupported()) {
			throw new PolicyUnavailable(Translator::t('Dismissing notifications is not available.'));
		}
		if ($this->session->getImpersonatingUserID() !== null) {
			throw new ToolFailure(Translator::t('Dismissing a notification while impersonating another account is not allowed.'));
		}
		$user = $this->session->getUser();
		if (!$user instanceof IUser || $user->getUID() !== $actor) {
			throw new ToolFailure(Translator::t('The signed-in account does not match the account that would dismiss this notification.'));
		}
		if (!$this->grants->grantedAny($actor, 'notifications', ['dismiss'])) {
			throw new ToolFailure(Translator::t('You cannot dismiss notifications.'));
		}
	}

	/** @return object native handler @throws PolicyUnavailable when the container cannot provide it */
	private function handler(): object {
		$handler = $this->container->get(NotificationsSurface::HANDLER);
		if (!is_object($handler)) {
			throw new PolicyUnavailable(Translator::t('Dismissing notifications is not available.'));
		}
		return $handler;
	}

	/**
	 * @param object $handler native handler
	 * @param string $actor authenticated account
	 * @param int $id notification id
	 * @return array{app:string,datetime:string,notification:INotification}|null owned row, or null when it is not in this inbox
	 * @throws PolicyUnavailable when the first read fails before an effect
	 */
	private function before(object $handler, string $actor, int $id): ?array {
		try {
			return $this->fetch($handler, $actor, $id);
		} catch (PolicyUnavailable $error) {
			throw $error;
		} catch (Throwable) {
			throw new PolicyUnavailable(Translator::t('Dismissing notifications is not available.'));
		}
	}

	/**
	 * @param object $handler native handler
	 * @param string $actor authenticated account
	 * @param int $id notification id
	 * @return array{app:string,datetime:string,notification:INotification}|null owned row
	 * @throws PolicyUnavailable when the row is not the actor's
	 * @throws Throwable when the read fails for a reason other than a missing row
	 */
	private function fetch(object $handler, string $actor, int $id): ?array {
		try {
			$notification = $handler->getById($id, $actor);
		} catch (Throwable $error) {
			if (is_a($error, NotificationsSurface::NOT_FOUND)) {
				return null;
			}
			throw $error;
		}
		if (!$notification instanceof INotification || $notification->getUser() !== $actor) {
			throw new PolicyUnavailable(Translator::t('Dismissing notifications is not available.'));
		}
		return [
			'app' => $notification->getApp(),
			'datetime' => DateTimeImmutable::createFromInterface($notification->getDateTime())->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
			'notification' => $notification,
		];
	}

	/**
	 * @param string $actor authenticated account
	 * @param int $id notification id
	 * @param string $app native app of the loaded notification
	 * @throws Throwable when the native push fails; the caller records uncertainty and does not retry
	 */
	private function push(string $actor, int $id, string $app): void {
		$push = $this->container->get(NotificationsSurface::PUSH);
		if (!is_object($push)) {
			throw new \RuntimeException('push');
		}
		$push->pushDeleteToDevice($actor, [$id], $app);
	}

	/**
	 * @return array{id:int,found:bool,app:?string,datetime:?string,message:string,warning:string}
	 */
	private function plan(int $id, bool $found, ?string $app, ?string $datetime, string $message, string $warning): array {
		return ['id' => $id, 'found' => $found, 'app' => $app, 'datetime' => $datetime, 'message' => $message, 'warning' => $warning];
	}

	/**
	 * @param bool|null $stillPresent null when the read-back itself failed
	 * @return array{id:int,outcome:string,still_present:bool|null,push:string,partial:bool,app:?string,datetime:?string,message:string}
	 */
	private function receipt(int $id, string $outcome, ?bool $stillPresent, string $push, bool $partial, ?string $app, ?string $datetime, string $message): array {
		return [
			'id' => $id, 'outcome' => $outcome, 'still_present' => $stillPresent, 'push' => $push,
			'partial' => $partial, 'app' => $app, 'datetime' => $datetime, 'message' => $message,
		];
	}
}
