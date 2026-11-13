<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notifications;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationEffect;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationInput;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationPreview;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsDismissal;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsSurface;
use OCA\Mcp\Modules\Notifications\NotificationsModule;
use OCA\Mcp\Modules\Notifications\Tools\DismissNotification;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\RichObjectStrings\IRichTextFormatter;
use OCP\RichObjectStrings\IValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use stdClass;

/** Real Handler::deleteById for both pins: preview counts, identical absence, callbacks, push and read-back. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationsDismissalTest extends TestCase {
	/** @return iterable<string,array{string}> supported distribution pins */
	public static function pins(): iterable {
		yield 'NC34' => ['34'];
		yield 'NC35' => ['35'];
	}

	/** @param string $major source pin @return void the list shape stays supported when push is absent */
	#[DataProvider('pins')]
	public function testReadShapeDoesNotRequireTheDismissPath(string $major): void {
		$this->sources($major);
		$surface = new NotificationsSurface();
		self::assertTrue($surface->supported());
		self::assertFalse($surface->dismissSupported());
	}

	/** @param string $major source pin @return void preview reads once and never dismisses, pushes or deletes */
	#[DataProvider('pins')]
	public function testPreviewDoesNotDismiss(string $major): void {
		$world = $this->open($major, $this->row(8));
		$plan = $world->preview->describe('alice', ['id' => 8]);
		self::assertTrue($plan['found']);
		self::assertSame('files_sharing', $plan['app']);
		self::assertSame('2027-01-15T08:00:00+00:00', $plan['datetime']);
		self::assertStringNotContainsString('secret', json_encode($plan, JSON_THROW_ON_ERROR));
		self::assertSame([NotificationsSurface::HANDLER], $world->gets);
		self::assertSame(0, $world->script->deletes);
		self::assertSame(0, $world->script->dismisses);
		self::assertSame(0, $world->script->statements);
		self::assertSame([], $world->push->calls);
		self::assertContains('user=alice', $world->script->predicates);
	}

	/** @param string $major source pin @return void another user's id and a missing id are the same receipt and the same plan */
	#[DataProvider('pins')]
	public function testForeignAndMissingIdsAreIndistinguishable(string $major): void {
		$foreign = $this->open($major, $this->row(9, 'bob'));
		$missing = $this->open($major, null);
		self::assertSame($missing->preview->describe('alice', ['id' => 9]), $foreign->preview->describe('alice', ['id' => 9]));
		self::assertSame($missing->effect->apply('alice', ['id' => 9]), $foreign->effect->apply('alice', ['id' => 9]));
		$receipt = json_decode($foreign->effect->apply('alice', ['id' => 9])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('absent', $receipt['outcome']);
		self::assertFalse($receipt['still_present']);
		self::assertSame('not_sent', $receipt['push']);
		self::assertFalse($receipt['partial']);
		self::assertNull($receipt['app']);
		self::assertSame(0, $foreign->script->deletes + $missing->script->deletes);
		self::assertSame(0, $foreign->script->dismisses + $missing->script->dismisses);
		self::assertSame([], $foreign->push->calls);
		self::assertNotContains('user=bob', $foreign->script->predicates);
		self::assertContains('user=alice', $foreign->script->predicates);
	}

	/** @param string $major source pin @return void impersonation, a different session and a missing grant do no native I/O */
	#[DataProvider('pins')]
	public function testImpersonationActorAndGrantAreRefusedBeforeLookup(string $major): void {
		$impersonated = $this->open($major, $this->row(8), 'root');
		$mismatch = $this->open($major, $this->row(8), null, 'bob');
		$denied = $this->open($major, $this->row(8), null, 'alice', false);
		$unknown = $this->open($major, $this->row(8), null, 'alice', false, new PolicyUnavailable('policy'));
		foreach ([$impersonated, $mismatch, $denied] as $world) {
			foreach (['preview', 'effect'] as $side) {
				try {
					$side === 'preview' ? $world->preview->describe('alice', ['id' => 8]) : $world->effect->apply('alice', ['id' => 8]);
					self::fail('refusal still dismissed');
				} catch (ToolFailure) {
					self::assertSame([], $world->gets);
				}
			}
			self::assertSame(0, $world->script->deletes);
		}
		$this->expectException(PolicyUnavailable::class);
		$unknown->effect->apply('alice', ['id' => 8]);
	}

	/** @param string $major source pin @return void a bad id never reaches the native handler */
	#[DataProvider('pins')]
	public function testInvalidIdDoesNotTouchNativeCode(string $major): void {
		$world = $this->open($major, $this->row(8));
		foreach ([['id' => 0], ['id' => '8'], ['id' => 8, 'user' => 'bob']] as $arguments) {
			self::assertTrue($world->preview->describe('alice', $arguments)['isError']);
			self::assertTrue($world->effect->apply('alice', $arguments)['isError']);
		}
		self::assertSame([], $world->gets);
		self::assertSame(0, $world->script->selects);
	}

	/** @param string $major source pin @return void confirm follows deleteById, then push, then a read-back that sees the row gone */
	#[DataProvider('pins')]
	public function testConfirmedDismissUsesTheNativeEndpointPath(string $major): void {
		$world = $this->open($major, $this->row(8));
		$receipt = json_decode($world->effect->apply('alice', ['id' => 8, 'confirm' => true])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('dismissed', $receipt['outcome']);
		self::assertFalse($receipt['still_present']);
		self::assertSame('sent', $receipt['push']);
		self::assertFalse($receipt['partial']);
		self::assertSame('files_sharing', $receipt['app']);
		self::assertSame('2027-01-15T08:00:00+00:00', $receipt['datetime']);
		self::assertStringNotContainsString('secret', json_encode($receipt, JSON_THROW_ON_ERROR));
		self::assertSame(1, $world->script->deletes);
		self::assertSame(1, $world->script->dismisses);
		self::assertSame(1, $world->script->statements);
		self::assertSame([['alice', [8], 'files_sharing']], $world->push->calls);
		self::assertSame([NotificationsSurface::HANDLER, NotificationsSurface::PUSH], $world->gets);
	}

	/** @param string $major source pin @return void a notifier failure leaves the row, skips push and is not retried */
	#[DataProvider('pins')]
	public function testNotifierFailureIsPartialAndUncertain(string $major): void {
		$world = $this->open($major, $this->row(8));
		$world->script->dismissError = new \RuntimeException('notifier');
		$receipt = json_decode($world->effect->apply('alice', ['id' => 8])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('uncertain', $receipt['outcome']);
		self::assertTrue($receipt['still_present']);
		self::assertSame('not_sent', $receipt['push']);
		self::assertTrue($receipt['partial']);
		self::assertSame(1, $world->script->dismisses);
		self::assertSame(0, $world->script->deletes);
		self::assertSame(0, $world->script->statements);
		self::assertSame([], $world->push->calls);
	}

	/** @param string $major source pin @return void a delete that reports nothing removed does not push */
	#[DataProvider('pins')]
	public function testDeleteReportingZeroIsUncertainAndDoesNotPush(string $major): void {
		$world = $this->open($major, $this->row(8));
		$world->script->deletedRows = 0;
		$receipt = json_decode($world->effect->apply('alice', ['id' => 8])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('uncertain', $receipt['outcome']);
		self::assertTrue($receipt['still_present']);
		self::assertSame('not_sent', $receipt['push']);
		self::assertTrue($receipt['partial']);
		self::assertSame(1, $world->script->statements);
		self::assertSame([], $world->push->calls);
	}

	/** @param string $major source pin @return void push failure after a real delete is uncertain and is not repeated */
	#[DataProvider('pins')]
	public function testPushFailureAfterDeleteIsUncertain(string $major): void {
		$world = $this->open($major, $this->row(8));
		$world->push->error = new \RuntimeException('push');
		$receipt = json_decode($world->effect->apply('alice', ['id' => 8])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('uncertain', $receipt['outcome']);
		self::assertFalse($receipt['still_present']);
		self::assertSame('uncertain', $receipt['push']);
		self::assertTrue($receipt['partial']);
		self::assertSame(1, $world->script->statements);
		self::assertCount(1, $world->push->calls);
	}

	/** @param string $major source pin @return void a failed read-back does not claim the row is gone or still there */
	#[DataProvider('pins')]
	public function testReadbackFailureStaysUncertain(string $major): void {
		$world = $this->open($major, $this->row(8));
		$world->script->readbackThrows = true;
		$receipt = json_decode($world->effect->apply('alice', ['id' => 8])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('uncertain', $receipt['outcome']);
		self::assertNull($receipt['still_present']);
		self::assertSame('sent', $receipt['push']);
		self::assertTrue($receipt['partial']);
		self::assertSame(1, $world->script->statements);
	}

	/** @param string $major source pin @return void the registry plans without confirm and only then runs the native delete */
	#[DataProvider('pins')]
	public function testRegistryRequiresConfirmBeforeTheNativeDelete(string $major): void {
		$world = $this->open($major, $this->row(8));
		$tool = new DismissNotification($world->effect, $world->preview);
		$modules = $this->createMock(ContainerInterface::class);
		$modules->method('get')->willReturn($tool);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$registry = new ToolRegistry(
			[new NotificationsModule($modules)],
			$world->policy,
			$apps,
			$users,
			new NullLogger(),
			null,
			new CapabilityRegistry([new NotificationsDiscoveryProbe(true)], new NullLogger()),
		);
		$plan = $registry->call('notifications_dismiss', ['id' => 8], 'alice');
		self::assertTrue($plan['structuredContent']['requiresConfirmation']);
		self::assertNull(json_decode($plan['content'][0]['text'], true));
		self::assertSame(0, $world->script->deletes);
		self::assertSame(0, $world->script->dismisses);
		self::assertSame([], $world->push->calls);
		$again = $registry->call('notifications_dismiss', ['id' => 8, 'confirm' => false], 'alice');
		self::assertTrue($again['structuredContent']['requiresConfirmation']);
		self::assertSame(0, $world->script->deletes);
		$done = $registry->call('notifications_dismiss', ['id' => 8, 'confirm' => true], 'alice');
		$receipt = json_decode($done['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('dismissed', $receipt['outcome']);
		self::assertSame(1, $world->script->deletes);
		self::assertSame(1, $world->script->dismisses);
		self::assertSame(1, $world->script->statements);
		self::assertCount(1, $world->push->calls);
	}

	/** @param string $major source pin @return void load the verbatim handler only */
	private function sources(string $major): void {
		if (class_exists(\OC\Notification\Notification::class, false)) {
			return;
		}
		foreach (['Notification', 'Action', 'Handler'] as $class) {
			require __DIR__ . '/../../../fixtures/notifications-native/' . $major . '/' . $class . '.php';
		}
	}

	/** @param string $major source pin @return void declare the exception and the push prefix the product reflects */
	private function declareDismissTypes(): void {
		if (!class_exists(\OCA\Notifications\Exceptions\NotificationNotFoundException::class, false)) {
			eval('namespace OCA\\Notifications\\Exceptions { class NotificationNotFoundException extends \\Exception {} }');
		}
		if (!class_exists(\OCA\Notifications\Push::class, false)) {
			eval('namespace OCA\\Notifications { class Push { public array $calls = []; public ?\\Throwable $error = null; public function pushDeleteToDevice(string $userId, ?array $notificationIds, string $app = \'\'): void { $this->calls[] = [$userId, $notificationIds, $app]; if ($this->error !== null) { throw $this->error; } } } }');
		}
	}

	/**
	 * @param string $major source pin
	 * @param array<string,mixed>|null $row native row, or null when the id is absent
	 * @return stdClass script, preview, effect, push, policy and recorded container ids
	 */
	private function open(string $major, ?array $row, ?string $impersonator = null, string $sessionUid = 'alice', bool $grant = true, ?\Throwable $grantError = null): stdClass {
		$this->sources($major);
		$this->declareDismissTypes();
		self::assertTrue((new NotificationsSurface())->dismissSupported());
		$script = new stdClass();
		$script->selects = 0;
		$script->deletes = 0;
		$script->dismisses = 0;
		$script->statements = 0;
		$script->predicates = [];
		$script->row = $row;
		$script->deletedRows = 1;
		$script->dismissError = null;
		$script->readbackThrows = false;
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): \OC\Notification\Notification => $this->notification());
		$manager->method('dismissNotification')->willReturnCallback(function () use ($script): void {
			$script->dismisses++;
			if ($script->dismissError !== null) {
				throw $script->dismissError;
			}
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder($script));
		$handler = new \OCA\Notifications\Handler($db, $manager);
		$push = new \OCA\Notifications\Push();
		$gets = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$gets, $handler, $push): object {
			$gets[] = $id;
			if ($id === NotificationsSurface::HANDLER) {
				return $handler;
			}
			if ($id === NotificationsSurface::PUSH) {
				return $push;
			}
			throw new \RuntimeException($id);
		});
		$session = $this->createMock(IUserSession::class);
		$session->method('getImpersonatingUserID')->willReturn($impersonator);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($sessionUid);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(function () use ($grant, $grantError): bool {
			if ($grantError !== null) {
				throw $grantError;
			}
			return $grant;
		});
		$native = new NotificationsDismissal($container, new NotificationsSurface(), $session, $policy);
		$input = new DismissNotificationInput();
		$world = new stdClass();
		$world->script = $script;
		$world->push = $push;
		$world->policy = $policy;
		$world->gets = &$gets;
		$world->preview = new DismissNotificationPreview($input, $native);
		$world->effect = new DismissNotificationEffect($input, $native);
		return $world;
	}

	/** @param stdClass $script shared native counters @return IQueryBuilder one builder that records the native calls */
	private function builder(stdClass $script): IQueryBuilder {
		$query = $this->createMock(IQueryBuilder::class);
		$params = [];
		$query->method('select')->willReturnCallback(function () use ($query, $script): IQueryBuilder {
			$script->selects++;
			return $query;
		});
		$query->method('delete')->willReturnCallback(function () use ($query, $script): IQueryBuilder {
			$script->deletes++;
			return $query;
		});
		foreach (['from', 'where'] as $method) {
			$query->method($method)->willReturnSelf();
		}
		$query->method('andWhere')->willReturnCallback(function (string $clause) use ($query, $script): IQueryBuilder {
			$script->predicates[] = $clause;
			return $query;
		});
		$query->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$params): string {
			$params[] = $value;
			return (string)$value;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $value): string => $column . '=' . $value);
		$query->method('expr')->willReturn($expr);
		$query->method('executeStatement')->willReturnCallback(function () use ($script): int {
			$script->statements++;
			if ($script->deletedRows > 0) {
				$script->row = null;
			}
			return $script->deletedRows;
		});
		$query->method('executeQuery')->willReturnCallback(function () use ($script, &$params): IResult {
			if ($script->readbackThrows && $script->statements > 0) {
				throw new \RuntimeException('readback');
			}
			$user = null;
			foreach ($params as $value) {
				if (is_string($value)) {
					$user = $value;
				}
			}
			$row = $script->row;
			if (is_array($row) && $user !== null && ($row['user'] ?? null) !== $user) {
				$row = null;
			}
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn($row === null ? false : $row);
			$result->method('closeCursor');
			return $result;
		});
		return $query;
	}

	/** @return \OC\Notification\Notification real in-memory value; no rich rendering is permitted */
	private function notification(): \OC\Notification\Notification {
		$validator = $this->createMock(IValidator::class);
		$formatter = $this->createMock(IRichTextFormatter::class);
		foreach ([$validator, $formatter] as $service) {
			foreach ((new ReflectionClass($service))->getInterfaceNames() as $interface) {
				if (!str_starts_with($interface, 'OCP\\')) {
					continue;
				}
				foreach ((new ReflectionClass($interface))->getMethods() as $method) {
					$service->expects(self::never())->method($method->getName());
				}
			}
		}
		return new \OC\Notification\Notification($validator, $formatter);
	}

	/** @param int $id synthetic id @param string $user row owner @return array<string,mixed> native row with fields the receipt must not echo */
	private function row(int $id, string $user = 'alice'): array {
		return ['notification_id' => $id, 'user' => $user, 'app' => 'files_sharing', 'timestamp' => 1800000000,
			'object_type' => 'remote_share', 'object_id' => 'private-object', 'subject' => 'secret-token', 'subject_parameters' => '{"token":"secret-token"}',
			'message' => 'secret-message', 'message_parameters' => '["secret-path"]', 'link' => 'https://secret.invalid/token', 'icon' => 'https://secret.invalid/icon',
			'actions' => '[{"label":"secret-action","link":"https://secret.invalid/action-token","type":"DELETE","primary":true}]'];
	}
}
