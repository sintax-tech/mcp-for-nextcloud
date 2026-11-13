<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\UserStatus\Db;

if (!class_exists(UserStatus::class, false)) {
	/** Empty stand-in so the set-type test can satisfy the native return type without loading the app. */
	class UserStatus {
	}
}

namespace OCA\UserStatus\Exception;

if (!class_exists(InvalidStatusTypeException::class, false)) {
	/** Stand-in for the exception StatusService throws before it writes. */
	class InvalidStatusTypeException extends \Exception {
	}
}

namespace OCA\Mcp\Tests\Unit\Modules\Status;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Status\Editing\SetOwnStatusTypeHandler;
use OCA\Mcp\Modules\Status\Integration\StatusEditor;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Integration\StatusSurface;
use OCA\Mcp\Modules\Status\Integration\StatusWriteSurface;
use OCA\Mcp\Modules\Status\Presentation\StatusTypeSchema;
use OCA\Mcp\Modules\Status\Presentation\StatusTypes;
use OCA\Mcp\Modules\Status\Reading\StatusSnapshot;
use OCA\Mcp\Modules\Status\Tools\SetOwnStatusType;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\UserStatus\IUserStatus;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** Preview writes nothing. Confirm follows the status menu and reads the row back. */
final class StatusSetTypeTest extends TestCase {
	/** @return void the published tool edits only the actor's own type */
	public function testDefinitionIsAnOwnTypeEdit(): void {
		$definition = SetOwnStatusType::definition();
		self::assertSame('status_set_type', $definition->name);
		self::assertSame('status', $definition->module);
		self::assertSame('edit', $definition->operation);
		self::assertSame('user_status', $definition->app);
		self::assertSame(['status.native'], $definition->requires);
		self::assertFalse($definition->destructiveHint);
		self::assertSame(StatusTypeSchema::NOTE, $definition->description);
		self::assertFalse($definition->inputSchema['additionalProperties']);
		self::assertSame(['status'], $definition->inputSchema['required']);
		self::assertArrayNotHasKey('user_id', $definition->inputSchema['properties']);
		self::assertSame([
			IUserStatus::ONLINE,
			IUserStatus::AWAY,
			IUserStatus::DND,
			IUserStatus::BUSY,
			IUserStatus::INVISIBLE,
			IUserStatus::OFFLINE,
		], StatusTypes::VALUES);
	}

	/** @return void the pinned menu signature is the one the editor accepts */
	public function testWriteSurfaceMatchesThePinnedMenuSignature(): void {
		foreach (['34', '35'] as $major) {
			$body = (string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/user-status-native/' . $major . '/StatusService.php');
			self::assertMatchesRegularExpression(
				'/function setStatus\(string \$userId,\s*string \$status,\s*\?int \$statusTimestamp,\s*bool \$isUserDefined\): UserStatus/',
				$body,
				$major,
			);
			self::assertMatchesRegularExpression('/function removeBackupUserStatus\(string \$userId\): bool/', $body, $major);
		}
		self::assertTrue((new StatusWriteSurface(MenuShapedStatusService::class))->supported());
		self::assertFalse((new StatusWriteSurface(OptionalTimestampStatusService::class))->supported());
	}

	/** @return void a plan reads the actor and does not call the service */
	public function testPreviewWritesNothingAndIgnoresAnotherUser(): void {
		$scene = new SetTypeScene(status: 'online', message: 'At the desk');
		$tool = $this->tool($scene);
		$plan = $tool->preview(['status' => 'dnd', 'user_id' => 'bob'], new ToolContext('alice'));
		self::assertTrue($plan['requiresConfirmation']);
		self::assertSame('online', $plan['before']);
		self::assertSame('dnd', $plan['after']);
		self::assertTrue($plan['message_kept']);
		self::assertTrue($plan['removes_backup']);
		self::assertSame([], $scene->serviceCalls);
		self::assertSame([['alice', false]], $scene->reads);
	}

	/** @return void confirm calls the menu pair and reads the actor's row back */
	public function testConfirmSetsTheTypeRemovesTheBackupAndReadsBack(): void {
		$scene = new SetTypeScene(status: 'online', message: 'At the desk', backup: true);
		$tool = $this->tool($scene);
		$result = $tool->execute(['status' => 'dnd', 'user_id' => 'bob'], new ToolContext('alice'));
		$payload = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($payload);
		self::assertSame([
			['setStatus', 'alice', 'dnd', null, true],
			['removeBackup', 'alice'],
		], $scene->serviceCalls);
		self::assertSame([['alice', false]], $scene->reads);
		self::assertSame('dnd', $payload['status']);
		self::assertSame('At the desk', $payload['message']);
		self::assertTrue($payload['removed_backup']);
		self::assertFalse($payload['live_presence']);
		self::assertSame('configured', $payload['snapshot']);
		GoldenFile::assertMatches($this, dirname(__DIR__, 2) . '/Contract/effects/status.set-type.json', [
			'tool' => 'status_set_type',
			'grant' => 'status.edit',
			'set_status' => ['alice', 'dnd', null, true],
			'remove_backup' => 'alice',
			'readback' => ['alice', false],
			'removed_backup' => true,
			'message' => 'At the desk',
		]);
	}

	/** @return void an unknown type never reaches the service */
	public function testAnUnknownTypeWritesNothing(): void {
		$scene = new SetTypeScene(status: 'online', message: null);
		$tool = $this->tool($scene);
		try {
			$tool->execute(['status' => 'vacation'], new ToolContext('alice'));
			self::fail('an unknown type was accepted');
		} catch (\InvalidArgumentException $exception) {
			self::assertSame('The status type is not supported.', $exception->getMessage());
		}
		self::assertSame([], $scene->serviceCalls);
		self::assertSame([], $scene->reads);
	}

	/** @return void a service rejection does not remove the backup */
	public function testARejectedTypeDoesNotRemoveTheBackup(): void {
		$scene = new SetTypeScene(status: 'online', message: null, reject: true);
		$editor = new StatusEditor(new SetTypeContainer($scene), new StatusWriteSurface(SetTypeService::class));
		try {
			$editor->setOwnType('alice', 'dnd');
			self::fail('a rejected type was stored');
		} catch (\InvalidArgumentException $exception) {
			self::assertSame('The status type is not supported.', $exception->getMessage());
		}
		self::assertSame([['setStatus', 'alice', 'dnd', null, true]], $scene->serviceCalls);
	}

	/** @return void no actor and no menu signature both refuse before any call */
	public function testAMissingActorOrSurfaceWritesNothing(): void {
		$scene = new SetTypeScene(status: 'online', message: null);
		$editor = new StatusEditor(new SetTypeContainer($scene), new StatusWriteSurface(SetTypeService::class));
		try {
			$editor->setOwnType('', 'online');
			self::fail('an empty actor was accepted');
		} catch (PolicyUnavailable) {
		}
		$closed = new StatusEditor(new SetTypeContainer($scene), new StatusWriteSurface('Missing\\StatusService'));
		try {
			$closed->setOwnType('alice', 'online');
			self::fail('a missing service was accepted');
		} catch (PolicyUnavailable) {
		}
		self::assertSame([], $scene->serviceCalls);
	}

	/** @param SetTypeScene $scene shared doubles */
	private function tool(SetTypeScene $scene): SetOwnStatusType {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_100);
		$container = new SetTypeContainer($scene);
		return new SetOwnStatusType(new SetOwnStatusTypeHandler(
			new StatusGateway($container, new StatusSurface(SetTypeMapper::class)),
			$time,
			new StatusSnapshot(),
			new StatusEditor($container, new StatusWriteSurface(SetTypeService::class)),
		));
	}
}

/** One row and the calls the menu path makes. */
final class SetTypeScene {
	/** @var list<array{0: string, 1: bool}> selects of the actor's own row */
	public array $reads = [];
	/** @var list<array<int, mixed>> setStatus and removeBackup calls */
	public array $serviceCalls = [];

	public function __construct(
		public string $status,
		public ?string $message,
		public bool $backup = false,
		public bool $reject = false,
	) {
	}
}

/** Select the gateway already uses. */
final class SetTypeMapper {
	public function __construct(private SetTypeScene $scene) {
	}

	public function findByUserId(string $userId, bool $isBackup = false): \OCA\UserStatus\Db\UserStatus {
		$this->scene->reads[] = [$userId, $isBackup];
		return new SetTypeRow($this->scene, $userId);
	}
}

/** Menu service the editor calls after the surface check. */
final class SetTypeService {
	public function __construct(private SetTypeScene $scene) {
	}

	public function setStatus(string $userId, string $status, ?int $statusTimestamp, bool $isUserDefined): \OCA\UserStatus\Db\UserStatus {
		$this->scene->serviceCalls[] = ['setStatus', $userId, $status, $statusTimestamp, $isUserDefined];
		if ($this->scene->reject) {
			throw new \OCA\UserStatus\Exception\InvalidStatusTypeException('rejected');
		}
		$this->scene->status = $status;
		return new \OCA\UserStatus\Db\UserStatus();
	}

	public function removeBackupUserStatus(string $userId): bool {
		$this->scene->serviceCalls[] = ['removeBackup', $userId];
		return $this->scene->backup;
	}
}

/** Returns the mapper or the service for the two native names. */
final class SetTypeContainer implements ContainerInterface {
	public function __construct(private SetTypeScene $scene) {
	}

	public function get(string $id): mixed {
		return match ($id) {
			StatusSurface::MAPPER => new SetTypeMapper($this->scene),
			StatusWriteSurface::SERVICE => new SetTypeService($this->scene),
			default => throw new \RuntimeException($id),
		};
	}

	public function has(string $id): bool {
		return true;
	}
}

/** Stored fields the snapshot reads back. */
final class SetTypeRow extends \OCA\UserStatus\Db\UserStatus {
	public function __construct(private SetTypeScene $scene, private string $userId) {
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function getStatus(): string {
		return $this->scene->status;
	}

	public function getStatusTimestamp(): int {
		return 1_600_000_000;
	}

	public function getIsUserDefined(): bool {
		return true;
	}

	public function getIsBackup(): bool {
		return false;
	}

	public function getMessageId(): ?string {
		return null;
	}

	public function getCustomMessage(): ?string {
		return $this->scene->message;
	}

	public function getClearAt(): ?int {
		return null;
	}

	public function getStatusMessageTimestamp(): int {
		return 1_600_000_050;
	}
}

/** The controller's setStatus, used only to prove the surface accepts it. */
final class MenuShapedStatusService {
	public function setStatus(string $userId, string $status, ?int $statusTimestamp, bool $isUserDefined): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}

	public function removeBackupUserStatus(string $userId): bool {
		throw new \LogicException('not called');
	}
}

/** A timestamp with a default is not the menu signature. */
final class OptionalTimestampStatusService {
	public function setStatus(string $userId, string $status, ?int $statusTimestamp = null, bool $isUserDefined = true): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}

	public function removeBackupUserStatus(string $userId): bool {
		throw new \LogicException('not called');
	}
}
