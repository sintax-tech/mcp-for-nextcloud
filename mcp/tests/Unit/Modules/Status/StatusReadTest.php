<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Status;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Status\Integration\StatusCapabilities;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Integration\StatusSurface;
use OCA\Mcp\Modules\Status\Presentation\StatusSchema;
use OCA\Mcp\Modules\Status\Reading\ReadOwnStatusHandler;
use OCA\Mcp\Modules\Status\Reading\StatusSnapshot;
use OCA\Mcp\Modules\Status\Tools\ReadOwnStatus;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The real handler and gateway select one row and call nothing that writes. */
final class StatusReadTest extends TestCase {
	/** @return void the published tool is the actor's own opt-in read */
	public function testDefinitionIsAnOptInReadOfTheActor(): void {
		$definition = ReadOwnStatus::definition();
		self::assertSame('status_read_own', $definition->name);
		self::assertSame('status', $definition->module);
		self::assertSame('read', $definition->operation);
		self::assertSame('user_status', $definition->app);
		self::assertSame(['status.native'], $definition->requires);
		self::assertFalse($definition->destructiveHint);
		self::assertNull($definition->grantAnyOf);
		self::assertSame(StatusSchema::NOTE, $definition->description);
		self::assertFalse($definition->inputSchema['additionalProperties']);
	}

	/** @return void a missing row is reported and the mapper is not asked to create one */
	public function testMissingRowDoesNotCreate(): void {
		$mapper = new CountingStatusMapper(missing: true);
		$payload = $this->read($mapper, 1_700_000_100);
		self::assertSame([
			'configured' => false,
			'snapshot' => 'configured',
			'live_presence' => false,
			'message' => 'No status is configured.',
		], $payload);
		self::assertSame([['alice', false]], $mapper->reads);
		self::assertSame(0, $mapper->writes);
	}

	/** @return void an expired clear_at is projected and the stored fields stay untouched */
	public function testExpirationIsProjectedAndStoredFieldsStay(): void {
		$row = new StoredStatusRow([
			'userId' => 'alice',
			'status' => 'online',
			'statusTimestamp' => 1_600_000_000,
			'userDefined' => false,
			'messageId' => 'meeting',
			'customMessage' => null,
			'clearAt' => 1_600_000_100,
			'statusMessageTimestamp' => 1_600_000_050,
			'customIcon' => 'SECRET-ICON',
		]);
		$mapper = new CountingStatusMapper(row: $row);
		$payload = $this->read($mapper, 1_700_000_100, ['user_id' => 'bob']);
		self::assertTrue($payload['expired']);
		self::assertSame('online', $payload['status']);
		self::assertSame('In a meeting', $payload['message']);
		self::assertSame(1_600_000_100, $payload['clear_at']);
		self::assertFalse($payload['live_presence']);
		self::assertSame('configured', $payload['snapshot']);
		self::assertArrayNotHasKey('custom_icon', $payload);
		self::assertArrayNotHasKey('icon', $payload);
		self::assertStringNotContainsString('SECRET-ICON', json_encode($payload));
		self::assertSame(1_600_000_100, $row->fields['clearAt']);
		self::assertSame('online', $row->fields['status']);
		self::assertSame([['alice', false]], $mapper->reads);
		self::assertSame(0, $mapper->writes);
	}

	/** @return void clear_at equal to now is not expired, matching the native strict comparison */
	public function testClearAtEqualToNowIsNotExpired(): void {
		$payload = $this->read(new CountingStatusMapper(row: new StoredStatusRow([
			'userId' => 'alice', 'status' => 'dnd', 'statusTimestamp' => 10, 'userDefined' => true,
			'messageId' => null, 'customMessage' => 'At the desk', 'clearAt' => 50, 'statusMessageTimestamp' => 10,
		])), 50);
		self::assertFalse($payload['expired']);
		self::assertSame('At the desk', $payload['message']);
		self::assertNull($payload['message_id']);
	}

	/** @return void an unknown message id is returned without an invented label or icon */
	public function testUnknownMessageIdHasNoInventedText(): void {
		$payload = $this->read(new CountingStatusMapper(row: new StoredStatusRow([
			'userId' => 'alice', 'status' => 'busy', 'statusTimestamp' => 10, 'userDefined' => true,
			'messageId' => 'calendar-busy', 'customMessage' => '   ', 'clearAt' => null, 'statusMessageTimestamp' => 0,
		])), 20);
		self::assertSame('calendar-busy', $payload['message_id']);
		self::assertNull($payload['message']);
		self::assertFalse($payload['expired']);
	}

	/** @return void more than one row is a refusal and still writes nothing */
	public function testMultipleRowsAreRefusedWithoutWriting(): void {
		$mapper = new CountingStatusMapper(many: true);
		$this->expectException(PolicyUnavailable::class);
		try {
			$this->read($mapper, 10);
		} finally {
			self::assertSame([['alice', false]], $mapper->reads);
			self::assertSame(0, $mapper->writes);
		}
	}

	/** @return void the select signature and the enabled app are both required */
	public function testCapabilityIsShapeAndEnablementOnly(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$probe = new StatusCapabilities($apps, new StatusSurface(SelectOnlyMapper::class));
		self::assertTrue($probe->supports('status.native'));
		self::assertFalse($probe->supports('other'));
		$off = $this->createMock(IAppManager::class);
		$off->method('isEnabledForAnyone')->willReturn(false);
		self::assertFalse((new StatusCapabilities($off, new StatusSurface(SelectOnlyMapper::class)))->supports('status.native'));
		self::assertFalse((new StatusCapabilities($apps, new StatusSurface('Missing\\StatusMapper')))->supports('status.native'));
		self::assertFalse((new StatusSurface(WritingMapper::class))->supported());
	}

	/**
	 * @param CountingStatusMapper $mapper stand-in reached by the real gateway
	 * @param int $now clock used only for projection
	 * @param array<string, mixed> $arguments ignored caller arguments
	 * @return array<string, mixed> decoded snapshot
	 */
	private function read(CountingStatusMapper $mapper, int $now, array $arguments = []): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')->with(StatusSurface::MAPPER)->willReturn($mapper);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$tool = new ReadOwnStatus(new ReadOwnStatusHandler(
			new StatusGateway($container, new StatusSurface(CountingStatusMapper::class)),
			$time,
			new StatusSnapshot(),
		));
		$result = $tool->execute($arguments, new ToolContext('alice'));
		self::assertArrayNotHasKey('isError', $result);
		$payload = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($payload);
		return $payload;
	}
}

/** Counts every call. Only findByUserId is a read; every other method is a write. */
final class CountingStatusMapper {
	/** @var list<array{0:string,1:bool}> select calls */
	public array $reads = [];
	/** Write calls, including any maintenance method. */
	public int $writes = 0;

	/** @param StoredStatusRow|null $row row returned by the select */
	public function __construct(private ?StoredStatusRow $row = null, private bool $missing = false, private bool $many = false) {
	}

	/**
	 * @param string $userId requested user
	 * @param bool $isBackup whether the backup row was requested
	 * @return StoredStatusRow stored row
	 */
	public function findByUserId(string $userId, bool $isBackup = false): \OCA\UserStatus\Db\UserStatus {
		$this->reads[] = [$userId, $isBackup];
		if ($this->missing) {
			throw new DoesNotExistException('missing');
		}
		if ($this->many) {
			throw new MultipleObjectsReturnedException('many');
		}
		return $this->row ?? throw new \LogicException('no row');
	}

	/**
	 * @param string $name unexpected method
	 * @param list<mixed> $arguments its arguments
	 * @return never
	 */
	public function __call(string $name, array $arguments): never {
		$this->writes++;
		throw new \LogicException($name);
	}
}

/** Stored fields. Icon and any unlisted getter fail the read. */
final class StoredStatusRow extends \OCA\UserStatus\Db\UserStatus {
	/** @param array<string, mixed> $fields copied values */
	public function __construct(public array $fields) {
	}

	/** @return string owner */
	public function getUserId(): string {
		return $this->fields['userId'];
	}

	/** @return string stored type */
	public function getStatus(): string {
		return $this->fields['status'];
	}

	/** @return int stored timestamp */
	public function getStatusTimestamp(): int {
		return $this->fields['statusTimestamp'];
	}

	/** @return bool stored user-defined flag */
	public function getIsUserDefined(): bool {
		return $this->fields['userDefined'];
	}

	/** @return string|null stored message id */
	public function getMessageId(): ?string {
		return $this->fields['messageId'];
	}

	/** @return string|null stored custom text */
	public function getCustomMessage(): ?string {
		return $this->fields['customMessage'];
	}

	/** @return int|null stored clear-at */
	public function getClearAt(): ?int {
		return $this->fields['clearAt'];
	}

	/** @return int stored message timestamp */
	public function getStatusMessageTimestamp(): int {
		return $this->fields['statusMessageTimestamp'];
	}

	/**
	 * @param string $name unexpected getter
	 * @param list<mixed> $arguments its arguments
	 * @return never
	 */
	public function __call(string $name, array $arguments): never {
		throw new \LogicException($name);
	}
}

/** Select signature the surface accepts. */
final class SelectOnlyMapper {
	/** @return \OCA\UserStatus\Db\UserStatus unused; the signature is inspected only */
	public function findByUserId(string $userId, bool $isBackup = false): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}
}

namespace OCA\UserStatus\Db;

/** Empty stand-in so the read test can satisfy the native return type without loading the app. */
class UserStatus {
}

namespace OCA\Mcp\Tests\Unit\Modules\Status;

/** A write-shaped mapper the surface must reject. */
final class WritingMapper {
	/** @return \OCA\UserStatus\Db\UserStatus unused */
	public function findByUserId(string $userId): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}
}
