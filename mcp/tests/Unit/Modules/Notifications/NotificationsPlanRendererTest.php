<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notifications;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationEffect;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationInput;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationPreview;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsDismissal;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsSurface;
use OCA\Mcp\Modules\Notifications\NotificationsModule;
use OCA\Mcp\Modules\Notifications\Presentation\NotificationsSchema;
use OCA\Mcp\Modules\Notifications\Tools\DismissNotification;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The readability contract of the one-id dismiss plan. */
final class NotificationsPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** @return void the dismiss plan is text, and text from the notification stays inert */
	public function testEveryWriteHasAReadablePlan(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getUser')->willReturn('alice');
		$notification->method('getApp')->willReturn('files_sharing');
		$notification->method('getDateTime')->willReturn(new \DateTime('2027-01-15T08:00:00+00:00'));
		$handler = new class($notification) {
			public int $reads = 0;
			public int $deletes = 0;
			public function __construct(private INotification $notification) {
			}
			public function getById(int $id, string $user): INotification {
				$this->reads++;
				return $this->notification;
			}
			public function deleteById(int $id, string $user, ?INotification $notification = null): bool {
				$this->deletes++;
				return false;
			}
		};
		$handlerClass = $handler::class;
		$root = __DIR__ . '/../../../fixtures/notifications-native/35/';
		if (!class_exists(\OCA\Notifications\Exceptions\NotificationNotFoundException::class, false)) {
			require $root . 'Exceptions/NotificationNotFoundException.php';
		}
		if (!class_exists(\OCA\Notifications\Push::class, false)) {
			require $root . 'Push.php';
		}
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($handler): object {
			if ($id === NotificationsSurface::PUSH) {
				throw new \RuntimeException('preview pushed');
			}
			return $handler;
		});
		$session = $this->createMock(IUserSession::class);
		$session->method('getImpersonatingUserID')->willReturn(null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$grants = $this->createMock(GrantPolicy::class);
		$grants->method('grantedAny')->willReturn(true);
		$native = new NotificationsDismissal($container, new NotificationsSurface($handlerClass), $session, $grants);
		$input = new DismissNotificationInput();
		$tool = new DismissNotification(new DismissNotificationEffect($input, $native), new DismissNotificationPreview($input, $native));
		$modules = $this->createMock(ContainerInterface::class);
		$modules->method('get')->willReturn($tool);
		$module = new NotificationsModule($modules);
		$plan = $module->preview('notifications_dismiss', ['id' => 4], 'alice');
		$this->assertEveryWriteToolHasAReadablePlan($module, ['notifications_dismiss' => $plan]);
		self::assertSame(0, $handler->deletes);
		self::assertSame(1, $handler->reads);
		self::assertSame('dismiss', DismissNotification::definition()->operation);
		self::assertTrue(DismissNotification::definition()->destructiveHint);
		self::assertSame([NotificationsSchema::NOTE, NotificationsSchema::DISMISS], $module->guideNotes());
	}
}
