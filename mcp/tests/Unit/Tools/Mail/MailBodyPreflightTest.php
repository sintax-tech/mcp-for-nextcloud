<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailBodyPreflight;
use OCA\Mcp\Tools\Mail\MailCacheGeneration;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/Horde/ImapClientStubs.php';

/**
 * The size preflight asks for the envelope and RFC822.SIZE only, takes the whole message size as the budget and
 * refuses a missing or invalid size; the cache generation is read from the folder's three sync tokens and is null
 * whenever it cannot be trusted.
 */
final class MailBodyPreflightTest extends TestCase {
	/** Budget. */
	private const MAX = MailBodyPreflight::MAX_MESSAGE_BYTES;

	/**
	 * A message within the budget passes on a FETCH of metadata items only.
	 *
	 * @return void
	 */
	public function testAMessageWithinTheBudgetPassesOnAMetadataFetch(): void {
		$client = $this->client(self::MAX);
		$this->assertSame(self::MAX, (new MailBodyPreflight())->check($client, 'INBOX', 3, '<m@x>'));
		$this->assertSame(['envelope', 'size'], $client->items);
	}

	/**
	 * One octet over the budget, whatever the parts, is refused.
	 *
	 * @return void
	 */
	public function testTheWholeMessageSizeIsTheBudget(): void {
		$this->assertFailure(MailMessages::bodyTooLarge(self::MAX + 1, self::MAX), $this->client(self::MAX + 1));
	}

	/**
	 * A missing, zero, negative or non-integer size, and a missing message, are refused.
	 *
	 * @return void
	 */
	public function testBadMetadataIsRefused(): void {
		foreach ([null, 0, -5, '100', 1.5] as $size) {
			$this->assertFailure(MailMessages::bodyUnavailable(), $this->client($size), var_export($size, true));
		}
		$this->assertFailure(MailMessages::messageNotFound(), $this->client(100, false));
	}

	/**
	 * A different Message-ID is refused; equal ones are only a complementary check.
	 *
	 * @return void
	 */
	public function testADifferentMessageIdIsRefused(): void {
		$this->assertFailure(MailMessages::messageChanged(), $this->client(100, true, '<other@x>'));
		$this->assertTrue(MailBodyPreflight::sameMessage('<m@x>', ' m@x '));
		$this->assertTrue(MailBodyPreflight::sameMessage(null, ''));
		$this->assertFalse(MailBodyPreflight::sameMessage(null, '<m@x>'));
	}

	/**
	 * The UIDVALIDITY of the server must be a positive number; leading zeros do not matter.
	 *
	 * @return void
	 */
	public function testTheUidValidityMustBeANumber(): void {
		$preflight = new MailBodyPreflight();
		$this->assertSame('42', $preflight->uidValidity($this->statusClient(['uidvalidity' => 42]), 'INBOX'));
		$this->assertSame('42', $preflight->uidValidity($this->statusClient(['uidvalidity' => '0042']), 'INBOX'));
		foreach ([[], ['uidvalidity' => 0], ['uidvalidity' => 'x']] as $status) {
			try {
				$preflight->uidValidity($this->statusClient($status), 'INBOX');
				$this->fail('accepted ' . json_encode($status));
			} catch (ToolFailure $e) {
				$this->assertSame(MailMessages::bodyUnavailable(), $e->getMessage());
			}
		}
	}

	/**
	 * The cache generation is the V of the three Horde sync tokens when they agree, and null otherwise.
	 *
	 * @return void
	 */
	public function testTheCacheGenerationComesFromTheThreeSyncTokens(): void {
		$v7 = FakeMailMessageApp::syncToken('7');
		$this->assertSame('7', MailCacheGeneration::of($this->mailbox($v7, $v7, $v7)));
		$this->assertSame('7', MailCacheGeneration::fromToken(base64_encode('U5,V007,H1')));
		$this->assertNull(MailCacheGeneration::of($this->mailbox($v7, null, $v7)), 'a token missing: never synchronized or reset');
		$this->assertNull(MailCacheGeneration::of($this->mailbox($v7, FakeMailMessageApp::syncToken('8'), $v7)), 'tokens of two generations');
		foreach (['%%%', base64_encode('U5,H1'), base64_encode('U5,V,H1'), base64_encode('V0'), base64_encode('Vx1'), ''] as $bad) {
			$this->assertNull(MailCacheGeneration::fromToken($bad), $bad);
		}
	}

	/**
	 * Asserts that the preflight refuses with this message.
	 *
	 * @param string $message expected failure
	 * @param object $client fake client
	 * @param string $why description
	 * @return void
	 */
	private function assertFailure(string $message, object $client, string $why = ''): void {
		try {
			(new MailBodyPreflight())->check($client, 'INBOX', 3, '<m@x>');
			$this->fail('no refusal ' . $why);
		} catch (ToolFailure $e) {
			$this->assertSame($message, $e->getMessage(), $why);
		}
	}

	/**
	 * A folder with these sync tokens.
	 *
	 * @param string|null $new sync_new_token
	 * @param string|null $changed sync_changed_token
	 * @param string|null $vanished sync_vanished_token
	 * @return object the folder double
	 */
	private function mailbox(?string $new, ?string $changed, ?string $vanished): object {
		return new class ($new, $changed, $vanished) {
			/** @param string|null $new token @param string|null $changed token @param string|null $vanished token @return void */
			public function __construct(private ?string $new, private ?string $changed, private ?string $vanished) {
			}

			/** @return string|null token */
			public function getSyncNewToken(): ?string {
				return $this->new;
			}

			/** @return string|null token */
			public function getSyncChangedToken(): ?string {
				return $this->changed;
			}

			/** @return string|null token */
			public function getSyncVanishedToken(): ?string {
				return $this->vanished;
			}
		};
	}

	/**
	 * A client that answers one FETCH with this metadata.
	 *
	 * @param mixed $size RFC822.SIZE
	 * @param bool $exists whether the UID exists
	 * @param string $messageId Message-ID of the envelope
	 * @return object the client, with the requested items in ->items
	 */
	private function client(mixed $size, bool $exists = true, string $messageId = '<m@x>'): object {
		return new class ($size, $exists, $messageId) {
			/** @var list<string> FETCH items requested */
			public array $items = [];

			/** @param mixed $size size @param bool $exists exists @param string $messageId Message-ID @return void */
			public function __construct(private mixed $size, private bool $exists, private string $messageId) {
			}

			/**
			 * @param string $mailbox folder
			 * @param \Horde_Imap_Client_Fetch_Query $query items
			 * @param array<string, mixed> $options ids
			 * @return object results with first()
			 */
			public function fetch(string $mailbox, \Horde_Imap_Client_Fetch_Query $query, array $options): object {
				$this->items = $query->items;
				$data = !$this->exists ? null : new class ($this->size, $this->messageId) {
					/** @param mixed $size size @param string $id Message-ID @return void */
					public function __construct(private mixed $size, private string $id) {
					}

					/** @return mixed size */
					public function getSize(): mixed {
						return $this->size;
					}

					/** @return object envelope */
					public function getEnvelope(): object {
						return (object)['message_id' => $this->id];
					}
				};
				return new class ($data) {
					/** @param object|null $data fetch data @return void */
					public function __construct(private ?object $data) {
					}

					/** @return object|null first result */
					public function first(): ?object {
						return $this->data;
					}
				};
			}
		};
	}

	/**
	 * A client that answers STATUS.
	 *
	 * @param array<string, mixed> $status answer
	 * @return object the client
	 */
	private function statusClient(array $status): object {
		return new class ($status) {
			/** @param array<string, mixed> $status answer @return void */
			public function __construct(private array $status) {
			}

			/** @param string $mailbox folder @param int $item item @return array<string, mixed> answer */
			public function status(string $mailbox, int $item): array {
				return $this->status;
			}
		};
	}
}
