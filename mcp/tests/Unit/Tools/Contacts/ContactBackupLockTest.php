<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Modules\Contacts\Backup\ContactBackupLock;
use PHPUnit\Framework\TestCase;

/** The contact-backup mutex has to fit in `file_locks.key` or release cannot find the row. */
final class ContactBackupLockTest extends TestCase {
	/**
	 * 83 characters used to be stored as 64 and left `lock = -1` after a successful backup.
	 *
	 * @return void
	 */
	public function testKeyFitsTheFileLocksColumn(): void {
		$key = ContactBackupLock::key('alice', '/alice/files/MCP backups/Contacts/Team');
		self::assertSame(ContactBackupLock::MAX_LENGTH, strlen($key));
		self::assertMatchesRegularExpression('/^mcp-contact-backup\/[a-f0-9]{45}$/', $key);
	}

	/**
	 * One folder shares one mutex. Another user or another folder does not.
	 *
	 * @return void
	 */
	public function testKeyDependsOnUserAndFolder(): void {
		$one = ContactBackupLock::key('alice', '/alice/files/MCP backups/Contacts/Team');
		self::assertSame($one, ContactBackupLock::key('alice', '/alice/files/MCP backups/Contacts/Team'));
		self::assertNotSame($one, ContactBackupLock::key('bob', '/alice/files/MCP backups/Contacts/Team'));
		self::assertNotSame($one, ContactBackupLock::key('alice', '/alice/files/MCP backups/Contacts/Other'));
	}
}
