<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\UserRevocationListener;
use OCA\Mcp\Access\Policy\CauseProcessor;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IDBConnection;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

final class UserRevocationListenerTest extends TestCase {
	public function testDisableCutsTheUserButOtherChangesAndDeletionDoNot(): void {
		$data = new InMemoryConfig();
		$store = new InMemoryOAuthStore();
		$causes = $this->createMock(CauseProcessor::class);
		$causes->expects($this->once())->method('flush');
		$listener = new UserRevocationListener(InMemoryConfig::writer($data->mock($this), $store), $this->createMock(IDBConnection::class), new NullLogger(), $causes);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$store->insertToken(['user_id' => 'alice', 'auth_gen' => '1'], 0);
		$store->insertCode(['user_id' => 'alice', 'auth_gen' => '1'], 0);
		$listener->handle(new UserChangedEvent($user, 'displayName', 'Alice'));
		$listener->handle(new UserChangedEvent($user, 'enabled', true, false));
		// The deletion is revoked by its account cause (UserCauseListener), never by an unconditional cut here.
		$listener->handle(new UserDeletedEvent($user));
		$this->assertCount(1, $store->tokens);
		$this->assertArrayNotHasKey('alice', $data->generations);
		$listener->handle(new UserChangedEvent($user, 'enabled', false, true));
		// A cut at the last issued generation; the tombstone stays for a recreated account.
		$this->assertSame('1', $data->generations['alice']['revoked_below']);
		$this->assertSame([], $store->tokens);
		$this->assertSame([], $store->codes);
	}

	public function testACutNeverOutlivesTheGenerationsIssuedAfterIt(): void {
		$data = new InMemoryConfig();
		$data->generations['alice'] = ['issue_seq' => '3', 'revoked_below' => '0'];
		$store = new InMemoryOAuthStore();
		$listener = new UserRevocationListener(InMemoryConfig::writer($data->mock($this), $store), $this->createMock(IDBConnection::class), new NullLogger(),
			$this->createMock(CauseProcessor::class));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$store->insertToken(['user_id' => 'alice', 'auth_gen' => '3'], 0);

		$listener->handle(new UserChangedEvent($user, 'enabled', false, true));
		$this->assertSame('3', $data->generations['alice']['revoked_below']);
		// A consent after the cut gets generation 4; a repeated cleanup of the old cut leaves it alone.
		$store->insertToken(['user_id' => 'alice', 'auth_gen' => '4'], 0);
		$store->deleteUpToGeneration('alice', '3');
		$this->assertSame(['4'], array_values(array_column($store->tokens, 'auth_gen')));
	}

	/** Never SQL inside the caller's transaction, never an exception for the core: the next use still refuses. */
	public function testInsideACallersTransactionOrOnAFailureNothingIsThrown(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$writer = $this->createMock(PolicyWriter::class);
		$logger = $this->createMock(LoggerInterface::class);
		$inTransaction = $this->createMock(IDBConnection::class);
		$inTransaction->method('inTransaction')->willReturn(true);
		$writer->expects($this->once())->method('revokeCredentials')->willThrowException(new \RuntimeException('storage down'));
		$logger->expects($this->once())->method('info');
		$logger->expects($this->once())->method('warning');

		$causes = $this->createMock(CauseProcessor::class);
		$disabled = new UserChangedEvent($user, 'enabled', false, true);
		(new UserRevocationListener($writer, $inTransaction, $logger, $causes))->handle($disabled);
		(new UserRevocationListener($writer, $this->createMock(IDBConnection::class), $logger, $causes))->handle($disabled);
	}
}
