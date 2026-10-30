<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Test-only shapes of the three Horde IMAP classes the Mail message tools build or read by name. In a
 * Nextcloud server they come from the vendor tree of the Mail app; here they only record which FETCH items were asked
 * for, so a test can tell a metadata FETCH from a content FETCH. They are loaded explicitly by the tests that need
 * them, never by the autoloader.
 */
class Horde_Imap_Client {
	/** STATUS item of the UIDVALIDITY, as Horde_Imap_Client 2.x numbers it. */
	public const STATUS_UIDVALIDITY = 16;

	/** Mode of a read-write selection (SELECT), as Horde_Imap_Client 2.x numbers it. */
	public const OPEN_READWRITE = 2;
}

/** The FETCH items of one query, recorded by name. */
class Horde_Imap_Client_Fetch_Query {
	/** @var list<string> items asked for, in order */
	public array $items = [];

	/**
	 * Records any FETCH item, such as envelope, structure, size, fullText or bodyPart.
	 *
	 * @param string $name item method called
	 * @param list<mixed> $arguments its arguments
	 * @return void
	 */
	public function __call(string $name, array $arguments): void {
		$this->items[] = $name;
	}
}

/** A UID set. */
class Horde_Imap_Client_Ids {
	/**
	 * Holds the UIDs.
	 *
	 * @param list<int> $ids UIDs
	 * @return void
	 */
	public function __construct(public array $ids) {
	}
}
