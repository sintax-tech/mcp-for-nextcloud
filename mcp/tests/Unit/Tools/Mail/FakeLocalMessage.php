<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

/**
 * Test-only stand-in for an OCA\Mail\Db\LocalMessage of the Outbox: the fields the send tool sets, and the id, status
 * and built message the Mail app's Outbox and send chain give it.
 */
final class FakeLocalMessage {
	/** Outbox id, once saved. */
	public ?int $id = null;

	/** Status the send chain recorded. */
	public ?int $status = null;

	/** The message as the Mail app built it, once sent. */
	public ?string $raw = null;

	/**
	 * Keeps the fields of an outgoing message.
	 *
	 * @param int $type LocalMessage type
	 * @param int $accountId account it is sent from
	 * @param string $subject subject
	 * @param string $body plain text
	 * @param int|null $sendAt send time, null for none
	 * @return void
	 */
	public function __construct(
		public int $type,
		public int $accountId,
		public string $subject,
		public string $body,
		public ?int $sendAt,
	) {
	}

	/**
	 * The Outbox id.
	 *
	 * @return int|null the id
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * The status the send chain recorded.
	 *
	 * @return int|null the status
	 */
	public function getStatus(): ?int {
		return $this->status;
	}

	/**
	 * The message as the Mail app built it.
	 *
	 * @return string|null the raw message
	 */
	public function getRaw(): ?string {
		return $this->raw;
	}
}
