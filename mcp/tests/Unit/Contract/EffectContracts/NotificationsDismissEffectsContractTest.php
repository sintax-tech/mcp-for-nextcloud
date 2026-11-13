<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationEffect;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationInput;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationPreview;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsDismissal;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsSurface;
use OCA\Mcp\Modules\Notifications\NotificationsModule;
use OCA\Mcp\Modules\Notifications\Tools\DismissNotification;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Notifications\NotificationsDiscoveryProbe;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Effect contract of notifications_dismiss: the preview leaves the inbox row in place, and confirm: true
 * deletes that row and records the device push.
 */
final class NotificationsDismissEffectsContractTest extends TestCase {
	private const ID = 8;
	private EffectsGate $gate;
	private IUserSession $session;
	/** @var object{present:bool, deletes:int, pushes:list<array{0:string,1:?array,2:string}>, notification:INotification} */
	private object $inbox;

	/** @return void one owned row behind the real dismiss admission */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->loadTypes();
		$inbox = new \stdClass();
		$inbox->present = true;
		$inbox->deletes = 0;
		$inbox->pushes = [];
		$inbox->notification = $this->notification();
		$this->inbox = $inbox;
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn(EffectsGate::USER);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($alice);
		$this->session->method('getImpersonatingUserID')->willReturn(null);
		$this->gate->serve(fn (): array => [$this->module()],
			fn (): CapabilityRegistry => new CapabilityRegistry([new NotificationsDiscoveryProbe()], new NullLogger()));
	}

	/** @return void dismiss one own notification: inert plan, confirmed delete and push, no stored plan token */
	public function testDismissingOneOwnNotification(): void {
		$flow = (new CriticalFlow('dismiss', 'notifications_dismiss', 'notifications', 'dismiss',
			['id' => self::ID], fn (): array => $this->state()))
			->withoutPlanChanged('notifications_dismiss carries no plan_state: the confirmed call re-reads the row, and a missing id is answered the same way as an id owned by somebody else.')
			->withoutVisibility('A notification is an inbox row, not a file: no system tag or excluded folder can hide it from MCP.');
		(new FlowRunner($this, $this->gate, 'notifications'))->run($flow);
		self::assertFalse($this->inbox->present);
		self::assertSame(1, $this->inbox->deletes);
		self::assertSame([[EffectsGate::USER, [self::ID], 'files_sharing']], $this->inbox->pushes);
	}

	/** @return array{present:bool, deletes:int, pushes:list<array{0:string,1:?array,2:string}>} the row and the native writes */
	private function state(): array {
		return ['present' => $this->inbox->present, 'deletes' => $this->inbox->deletes, 'pushes' => $this->inbox->pushes];
	}

	/** @return NotificationsModule the catalog wired to the shared inbox */
	private function module(): NotificationsModule {
		$handler = new class($this->inbox, self::ID) {
			/** @param object{present:bool, deletes:int, pushes:list<array{0:string,1:?array,2:string}>, notification:INotification} $inbox @param int $notificationId the one id this flow dismisses */
			public function __construct(private object $inbox, private int $notificationId) {
			}

			/** @return INotification the owned row @throws \OCA\Notifications\Exceptions\NotificationNotFoundException when it is gone */
			public function getById(int $id, string $user): INotification {
				if (!$this->inbox->present || $id !== $this->notificationId || $user !== EffectsGate::USER) {
					throw new \OCA\Notifications\Exceptions\NotificationNotFoundException();
				}
				return $this->inbox->notification;
			}

			/** @return bool whether this user's row was removed */
			public function deleteById(int $id, string $user, INotification $notification): bool {
				if ($id !== $this->notificationId || $user !== EffectsGate::USER || $notification->getUser() !== $user) {
					return false;
				}
				$this->inbox->deletes++;
				$this->inbox->present = false;
				return true;
			}
		};
		$push = new class($this->inbox) {
			/** @param object{pushes:list<array{0:string,1:?array,2:string}>} $inbox */
			public function __construct(private object $inbox) {
			}

			/** @param ?array $notificationIds dismissed ids @return void records the device push */
			public function pushDeleteToDevice(string $userId, ?array $notificationIds, string $app = ''): void {
				$this->inbox->pushes[] = [$userId, $notificationIds, $app];
			}
		};
		$tool = null;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$tool, $handler, $push): object {
			if ($id === DismissNotification::class) {
				return $tool ?? throw new \LogicException('dismiss tool');
			}
			if ($id === NotificationsSurface::HANDLER) {
				return $handler;
			}
			if ($id === NotificationsSurface::PUSH) {
				return $push;
			}
			throw new \LogicException($id);
		});
		$native = new NotificationsDismissal($container, new NotificationsSurface(), $this->session, $this->gate->policy());
		$input = new DismissNotificationInput();
		$tool = new DismissNotification(new DismissNotificationEffect($input, $native), new DismissNotificationPreview($input, $native));
		return new NotificationsModule($container);
	}

	/** @return void load the verbatim NC35 types dismissSupported reflects */
	private function loadTypes(): void {
		$root = __DIR__ . '/../../../fixtures/notifications-native/35/';
		if (!class_exists(\OC\Notification\Notification::class, false)) {
			require $root . 'Notification.php';
			require $root . 'Action.php';
			require $root . 'Handler.php';
		}
		if (!class_exists(\OCA\Notifications\Exceptions\NotificationNotFoundException::class, false)) {
			require $root . 'Exceptions/NotificationNotFoundException.php';
		}
		if (!class_exists(\OCA\Notifications\Push::class, false)) {
			require $root . 'Push.php';
		}
	}

	/** @return \OC\Notification\Notification owned row whose subject stays out of the plan */
	private function notification(): \OC\Notification\Notification {
		$notification = new \OC\Notification\Notification(
			$this->createMock(\OCP\RichObjectStrings\IValidator::class),
			$this->createMock(\OCP\RichObjectStrings\IRichTextFormatter::class),
		);
		$notification->setApp('files_sharing');
		$notification->setUser(EffectsGate::USER);
		$notification->setDateTime(new \DateTime('2027-01-15 08:00:00', new \DateTimeZone('UTC')));
		$notification->setObject('remote_share', 'secret-object');
		return $notification;
	}
}
