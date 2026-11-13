<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\UserStatus\Db;

if (!class_exists(UserStatus::class, false)) {
	/** Empty stand-in so the message test can satisfy the native return type without loading the app. */
	class UserStatus {
	}
}

namespace OCA\UserStatus\Exception;

if (!class_exists(InvalidClearAtException::class, false)) {
	/** Stand-in for the exception StatusService throws before it writes. */
	class InvalidClearAtException extends \Exception {
	}
}
if (!class_exists(InvalidMessageIdException::class, false)) {
	/** Stand-in for the exception StatusService throws before it writes. */
	class InvalidMessageIdException extends \Exception {
	}
}
if (!class_exists(InvalidStatusIconException::class, false)) {
	/** Stand-in for the exception StatusService throws before it writes. */
	class InvalidStatusIconException extends \Exception {
	}
}
if (!class_exists(StatusMessageTooLongException::class, false)) {
	/** Stand-in for the exception StatusService throws before it writes. */
	class StatusMessageTooLongException extends \Exception {
	}
}

namespace OCA\Mcp\Tests\Unit\Modules\Status;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Status\Editing\SetOwnStatusMessageHandler;
use OCA\Mcp\Modules\Status\Editing\SetOwnStatusMessagePreview;
use OCA\Mcp\Modules\Status\Integration\StatusEditor;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCA\Mcp\Modules\Status\Integration\StatusSurface;
use OCA\Mcp\Modules\Status\Integration\StatusWriteSurface;
use OCA\Mcp\Modules\Status\Presentation\StatusMessageRequest;
use OCA\Mcp\Modules\Status\Presentation\StatusMessageSchema;
use OCA\Mcp\Modules\Status\Reading\StatusSnapshot;
use OCA\Mcp\Modules\Status\Tools\SetOwnStatusMessage;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** Preview writes nothing. Confirm follows the status menu and reads the row back. */
final class StatusSetMessageTest extends TestCase {
	private const NOW = 1_700_000_100;

	/** @return void the published tool edits only the actor's own message */
	public function testDefinitionIsAnOwnMessageEdit(): void {
		$definition = SetOwnStatusMessage::definition();
		self::assertSame('status_set_message', $definition->name);
		self::assertSame('status', $definition->module);
		self::assertSame('edit', $definition->operation);
		self::assertSame('user_status', $definition->app);
		self::assertSame(['status.native'], $definition->requires);
		self::assertFalse($definition->destructiveHint);
		self::assertSame(StatusMessageSchema::NOTE, $definition->description);
		self::assertFalse($definition->inputSchema['additionalProperties']);
		self::assertArrayNotHasKey('required', $definition->inputSchema);
		self::assertSame(StatusMessageRequest::IDS, $definition->inputSchema['properties']['message_id']['enum']);
		foreach (['user_id', 'icon', 'custom_icon', 'status_icon', 'emoji'] as $forbidden) {
			self::assertArrayNotHasKey($forbidden, $definition->inputSchema['properties']);
		}
	}

	/** @return void the pinned menu signatures are the ones the editor accepts */
	public function testWriteSurfaceMatchesThePinnedMessageSignatures(): void {
		foreach (['34', '35'] as $major) {
			$body = (string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/user-status-native/' . $major . '/StatusService.php');
			self::assertMatchesRegularExpression(
				'/function setPredefinedMessage\(string \$userId,\s*string \$messageId,\s*\?int \$clearAt\): UserStatus/',
				$body,
				$major,
			);
			self::assertMatchesRegularExpression(
				'/function setCustomMessage\(string \$userId,\s*\?string \$statusIcon,\s*\?string \$message,\s*\?int \$clearAt\): UserStatus/',
				$body,
				$major,
			);
			self::assertMatchesRegularExpression('/function clearMessage\(string \$userId\): bool/', $body, $major);
			self::assertSame(80, $this->constant($body, 'MAXIMUM_MESSAGE_LENGTH'), $major);
		}
		self::assertTrue((new StatusWriteSurface(MessageShapedStatusService::class))->messageSupported());
		self::assertFalse((new StatusWriteSurface(OptionalClearAtStatusService::class))->messageSupported());
	}

	/** @return void a plan reads the actor and does not call the service */
	public function testPreviewWritesNothingAndIgnoresAnotherUser(): void {
		$scene = new MessageScene(message: 'At the desk');
		$plan = $this->tool($scene)->preview([
			'message' => 'In a meeting',
			'clear_at' => self::NOW + 100,
			'user_id' => 'bob',
		], new ToolContext('alice'));
		self::assertTrue($plan['requiresConfirmation']);
		self::assertSame('custom', $plan['mode']);
		self::assertSame('At the desk', $plan['before']);
		self::assertSame('In a meeting', $plan['after']);
		self::assertSame(self::NOW + 100, $plan['clear_at']);
		self::assertTrue($plan['removes_backup']);
		self::assertStringContainsString('backup', $plan['message']);
		self::assertSame([], $scene->serviceCalls);
		self::assertSame([['alice', false]], $scene->reads);
	}

	/** @return void clearing warns that the backup stays and writes nothing */
	public function testClearPreviewDoesNotRemoveTheBackup(): void {
		$scene = new MessageScene(message: 'At the desk');
		$plan = $this->tool($scene)->preview(['clear' => true], new ToolContext('alice'));
		self::assertSame('clear', $plan['mode']);
		self::assertNull($plan['after']);
		self::assertFalse($plan['removes_backup']);
		self::assertStringContainsString('stays', $plan['message']);
		self::assertSame([], $scene->serviceCalls);
	}

	/** @return void confirm stores the text with a null icon, removes the backup, and reads the actor back */
	public function testConfirmSetsTheCustomMessageRemovesTheBackupAndReadsBack(): void {
		$scene = new MessageScene(message: 'Old', backup: true);
		$result = $this->tool($scene)->execute([
			'message' => 'At the desk',
			'clear_at' => self::NOW + 100,
			'user_id' => 'bob',
		], new ToolContext('alice'));
		$payload = json_decode($result['content'][0]['text'], true);
		self::assertSame([
			['setCustomMessage', 'alice', null, 'At the desk', self::NOW + 100],
			['removeBackup', 'alice'],
		], $scene->serviceCalls);
		self::assertSame([['alice', false]], $scene->reads);
		self::assertSame('At the desk', $payload['message']);
		self::assertNull($payload['message_id']);
		self::assertTrue($payload['removed_backup']);
		self::assertFalse($payload['live_presence']);
		GoldenFile::assertMatches($this, dirname(__DIR__, 2) . '/Contract/effects/status.set-message.json', [
			'tool' => 'status_set_message',
			'grant' => 'status.edit',
			'set_custom_message' => ['alice', null, 'At the desk', self::NOW + 100],
			'remove_backup' => 'alice',
			'readback' => ['alice', false],
			'removed_backup' => true,
			'message' => 'At the desk',
			'message_id' => null,
		]);
	}

	/** @return void a predefined id uses the menu path and still removes the backup */
	public function testConfirmSetsAPredefinedMessage(): void {
		$scene = new MessageScene(message: 'Old', backup: true);
		$result = $this->tool($scene)->execute(['message_id' => 'meeting'], new ToolContext('alice'));
		$payload = json_decode($result['content'][0]['text'], true);
		self::assertSame([
			['setPredefinedMessage', 'alice', 'meeting', null],
			['removeBackup', 'alice'],
		], $scene->serviceCalls);
		self::assertSame('In a meeting', $payload['message']);
		self::assertSame('meeting', $payload['message_id']);
		self::assertTrue($payload['removed_backup']);
	}

	/** @return void an explicit clear uses clearMessage and leaves the backup */
	public function testConfirmClearsTheMessageWithoutRemovingTheBackup(): void {
		$scene = new MessageScene(message: 'At the desk', messageId: null, backup: true);
		$result = $this->tool($scene)->execute(['clear' => true], new ToolContext('alice'));
		$payload = json_decode($result['content'][0]['text'], true);
		self::assertSame([['clearMessage', 'alice']], $scene->serviceCalls);
		self::assertNull($payload['message']);
		self::assertFalse($payload['removed_backup']);
	}

	/** @return void an empty message, two modes, a past time, or clear plus expiration never reach the service */
	public function testRefusalsWriteNothing(): void {
		$cases = [
			[['message' => ''], 'The status message is empty. Removing it needs clear set to true.'],
			[['message' => 'At the desk', 'message_id' => 'meeting'], 'Pass one status message change: a message, a predefined message id, or clear.'],
			[[], 'Pass one status message change: a message, a predefined message id, or clear.'],
			[['clear' => false], 'Pass one status message change: a message, a predefined message id, or clear.'],
			[['message_id' => 'vacation'], 'The status message is not supported.'],
			[['message' => str_repeat('a', 81)], 'The status message can have at most 80 characters.'],
			[['message' => 'At the desk', 'clear_at' => self::NOW - 1], 'The status message expiration must be a unix time in the future.'],
			[['message' => 'At the desk', 'clear_at' => '1700000200'], 'The status message expiration must be a unix time in the future.'],
			[['clear' => true, 'clear_at' => self::NOW + 100], 'A removed status message has no expiration.'],
		];
		foreach ($cases as [$arguments, $message]) {
			$scene = new MessageScene(message: 'At the desk');
			try {
				$this->tool($scene)->execute($arguments, new ToolContext('alice'));
				self::fail('accepted ' . json_encode($arguments));
			} catch (\InvalidArgumentException $exception) {
				self::assertSame($message, $exception->getMessage());
			}
			self::assertSame([], $scene->serviceCalls, json_encode($arguments));
		}
	}

	/** @return void a service rejection does not remove the backup */
	public function testARejectedMessageDoesNotRemoveTheBackup(): void {
		$scene = new MessageScene(message: 'At the desk', reject: 'id');
		$editor = new StatusEditor(new MessageContainer($scene), new StatusWriteSurface(MessageService::class));
		try {
			$editor->setOwnMessage('alice', StatusMessageRequest::parse(['message_id' => 'meeting'], self::NOW));
			self::fail('a rejected id was stored');
		} catch (\InvalidArgumentException $exception) {
			self::assertSame('The status message is not supported.', $exception->getMessage());
		}
		self::assertSame([['setPredefinedMessage', 'alice', 'meeting', null]], $scene->serviceCalls);
	}

	/** @return void no actor and no menu signature both refuse before any call */
	public function testAMissingActorOrSurfaceWritesNothing(): void {
		$scene = new MessageScene(message: 'At the desk');
		$request = StatusMessageRequest::parse(['message' => 'At the desk'], self::NOW);
		$editor = new StatusEditor(new MessageContainer($scene), new StatusWriteSurface(MessageService::class));
		try {
			$editor->setOwnMessage('', $request);
			self::fail('an empty actor was accepted');
		} catch (PolicyUnavailable) {
		}
		$closed = new StatusEditor(new MessageContainer($scene), new StatusWriteSurface('Missing\\StatusService'));
		try {
			$closed->setOwnMessage('alice', $request);
			self::fail('a missing service was accepted');
		} catch (PolicyUnavailable) {
		}
		self::assertSame([], $scene->serviceCalls);
	}

	/** @param string $body pinned service source @param string $name constant @return int its value */
	private function constant(string $body, string $name): int {
		self::assertSame(1, preg_match('/const ' . $name . ' = (\d+);/', $body, $match));
		return (int)$match[1];
	}

	/** @param MessageScene $scene shared doubles */
	private function tool(MessageScene $scene): SetOwnStatusMessage {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$container = new MessageContainer($scene);
		$gateway = new StatusGateway($container, new StatusSurface(MessageMapper::class));
		return new SetOwnStatusMessage(
			new SetOwnStatusMessageHandler($gateway, $time, new StatusSnapshot(), new StatusEditor($container, new StatusWriteSurface(MessageService::class))),
			new SetOwnStatusMessagePreview($gateway, $time),
		);
	}
}

/** One row and the calls the message menu makes. */
final class MessageScene {
	/** @var list<array{0: string, 1: bool}> selects of the actor's own row */
	public array $reads = [];
	/** @var list<array<int, mixed>> native message calls */
	public array $serviceCalls = [];

	public function __construct(
		public ?string $message,
		public ?string $messageId = null,
		public ?int $clearAt = null,
		public bool $backup = false,
		public ?string $reject = null,
	) {
	}
}

/** Select the gateway already uses. */
final class MessageMapper {
	public function __construct(private MessageScene $scene) {
	}

	public function findByUserId(string $userId, bool $isBackup = false): \OCA\UserStatus\Db\UserStatus {
		$this->scene->reads[] = [$userId, $isBackup];
		return new MessageRow($this->scene, $userId);
	}
}

/** Menu service the editor calls after the surface check. */
final class MessageService {
	public function __construct(private MessageScene $scene) {
	}

	public function setCustomMessage(string $userId, ?string $statusIcon, ?string $message, ?int $clearAt): \OCA\UserStatus\Db\UserStatus {
		$this->scene->serviceCalls[] = ['setCustomMessage', $userId, $statusIcon, $message, $clearAt];
		$this->reject();
		$this->scene->message = $message;
		$this->scene->messageId = null;
		$this->scene->clearAt = $clearAt;
		return new \OCA\UserStatus\Db\UserStatus();
	}

	public function setPredefinedMessage(string $userId, string $messageId, ?int $clearAt): \OCA\UserStatus\Db\UserStatus {
		$this->scene->serviceCalls[] = ['setPredefinedMessage', $userId, $messageId, $clearAt];
		$this->reject();
		$this->scene->message = null;
		$this->scene->messageId = $messageId;
		$this->scene->clearAt = $clearAt;
		return new \OCA\UserStatus\Db\UserStatus();
	}

	public function clearMessage(string $userId): bool {
		$this->scene->serviceCalls[] = ['clearMessage', $userId];
		$this->scene->message = null;
		$this->scene->messageId = null;
		$this->scene->clearAt = null;
		return true;
	}

	public function removeBackupUserStatus(string $userId): bool {
		$this->scene->serviceCalls[] = ['removeBackup', $userId];
		return $this->scene->backup;
	}

	private function reject(): void {
		if ($this->scene->reject === 'id') {
			throw new \OCA\UserStatus\Exception\InvalidMessageIdException('rejected');
		}
	}
}

/** Returns the mapper or the service for the two native names. */
final class MessageContainer implements ContainerInterface {
	public function __construct(private MessageScene $scene) {
	}

	public function get(string $id): mixed {
		return match ($id) {
			StatusSurface::MAPPER => new MessageMapper($this->scene),
			StatusWriteSurface::SERVICE => new MessageService($this->scene),
			default => throw new \RuntimeException($id),
		};
	}

	public function has(string $id): bool {
		return true;
	}
}

/** Stored fields the snapshot reads back. */
final class MessageRow extends \OCA\UserStatus\Db\UserStatus {
	public function __construct(private MessageScene $scene, private string $userId) {
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function getStatus(): string {
		return 'dnd';
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
		return $this->scene->messageId;
	}

	public function getCustomMessage(): ?string {
		return $this->scene->message;
	}

	public function getClearAt(): ?int {
		return $this->scene->clearAt;
	}

	public function getStatusMessageTimestamp(): int {
		return 1_600_000_050;
	}
}

/** The controller's message methods, used only to prove the surface accepts them. */
final class MessageShapedStatusService {
	public function setPredefinedMessage(string $userId, string $messageId, ?int $clearAt): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}

	public function setCustomMessage(string $userId, ?string $statusIcon, ?string $message, ?int $clearAt): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}

	public function clearMessage(string $userId): bool {
		throw new \LogicException('not called');
	}

	public function removeBackupUserStatus(string $userId): bool {
		throw new \LogicException('not called');
	}
}

/** A clearAt with a default is not the menu signature. */
final class OptionalClearAtStatusService {
	public function setPredefinedMessage(string $userId, string $messageId, ?int $clearAt = null): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}

	public function setCustomMessage(string $userId, ?string $statusIcon, ?string $message, ?int $clearAt): \OCA\UserStatus\Db\UserStatus {
		throw new \LogicException('not called');
	}

	public function clearMessage(string $userId): bool {
		throw new \LogicException('not called');
	}

	public function removeBackupUserStatus(string $userId): bool {
		throw new \LogicException('not called');
	}
}
