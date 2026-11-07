<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Contacts;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * ContactBackup stays exempt while a file-name allowlist is active, including when that policy cannot be read.
 * The contacts app has to be installed in the image; a missing app fails this test instead of skipping it.
 * The harness of this base does not install or pin Contacts. The integrated gate provisions that app before
 * the proof runs. This slice does not change harness.sh.
 * These tests are written for the combined T15 run. This slice does not execute them.
 */
#[Group('contacts')]
final class ContactBackupPolicyTest extends ExclusionTestCase {
	private bool $enabledContacts = false;

	/** Enables contacts when it is installed but off, and grants alice the delete this proof needs. */
	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppManager::class);
		if (!$apps->isInstalled('contacts')) {
			self::fail('The contacts app is not installed in this image; ContactBackup cannot be proved here.');
		}
		if (!$apps->isEnabledForUser('contacts')) {
			$apps->enableApp('contacts');
			$this->enabledContacts = true;
		}
		self::assertTrue($apps->isEnabledForUser('contacts'));
		Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'contacts', 'delete', true);
	}

	/** Restores the absent policy key, the delete grant and an app this test itself enabled. */
	protected function tearDown(): void {
		$this->clearPolicy();
		try {
			Server::get(GrantPolicy::class)->setGrant(self::ALICE, 'contacts', 'delete', false);
		} catch (Throwable) {
			// The next test sets its own grants.
		}
		if ($this->enabledContacts) {
			try {
				Server::get(IAppManager::class)->disableApp('contacts');
			} catch (Throwable) {
				// Best effort so a failed disable does not hide the assertion failure.
			}
		}
		parent::tearDown();
	}

	/** Removes the policy key when this test created one. */
	private function clearPolicy(): void {
		try {
			$config = Server::get(IAppConfig::class);
			$config->clearCache(false);
			if ($config->hasKey(FileWritePolicy::APP, FileWritePolicy::KEY, null)) {
				$config->deleteKey(FileWritePolicy::APP, FileWritePolicy::KEY);
			}
			$config->clearCache(false);
		} catch (Throwable) {
			// The next test asserts the key's absence on its own setUp.
		}
	}

	/**
	 * @return array{path:string, uri:string, etag:string, data:string} Address book path, card uri, etag and bytes.
	 */
	private function plant(): array {
		$backend = Server::get(CardDavBackend::class);
		$bookUri = 't157-' . $this->run;
		$data = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:" . $bookUri . "\r\nFN:Ada Lovelace\r\nN:Lovelace;Ada;;;\r\nEND:VCARD\r\n";
		$bookId = (int)$backend->createAddressBook('principals/users/' . self::ALICE, $bookUri, [
			'{DAV:}displayname' => 'T157',
		]);
		$this->later(static function () use ($backend, $bookId): void {
			$backend->deleteAddressBook($bookId);
		});
		$cardUri = 'ada-' . $this->run . '.vcf';
		$backend->createCard($bookId, $cardUri, $data);
		$card = $backend->getCard($bookId, $cardUri);
		$this->assertIsArray($card);
		return [
			'path' => '/remote.php/dav/addressbooks/users/' . rawurlencode(self::ALICE) . '/' . rawurlencode($bookUri) . '/',
			'uri' => $cardUri,
			'etag' => (string)$card['etag'],
			'data' => $data,
		];
	}

	/** @param array{path:string, uri:string, etag:string, data:string} $contact Planted card. */
	private function deleteContact(array $contact): ToolReply {
		return $this->mcp()->write('contacts_delete_contact', [
			'addressbook' => $contact['path'],
			'uri' => $contact['uri'],
			'etag' => $contact['etag'],
		]);
	}

	/**
	 * @param ToolReply $reply Confirmed delete.
	 * @param string $data Original vCard bytes.
	 * @return void
	 */
	private function assertBackup(ToolReply $reply, string $data): void {
		$this->assertTrue($reply->ok(), $reply->describe());
		$json = $reply->json();
		$this->assertIsArray($json);
		$this->assertTrue($json['deleted'] ?? false);
		$backup = (string)($json['backupPath'] ?? '');
		$this->assertStringStartsWith('/MCP backups/Contacts/', $backup);
		$this->assertStringEndsWith('.vcf', $backup);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, ltrim($backup, '/')));
		$copy = $this->node(self::ALICE, ltrim($backup, '/'));
		$this->assertInstanceOf(File::class, $copy);
		$this->assertSame($data, $copy->getContent());
	}

	/** An allowlist that does not include vcf still stores a verified vCard before the contact is deleted. */
	public function testDeleteBacksUpVcfWhenTheAllowlistExcludesVcf(): void {
		Server::get(FileWritePolicy::class)->save('allowlist', ['txt'], false);
		$contact = $this->plant();
		$this->assertBackup($this->deleteContact($contact), $contact['data']);
	}

	/** An unreadable policy does not block the mandatory backup of an authorized delete. */
	public function testDeleteBacksUpVcfWhenThePolicyIsUnreadable(): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueString(FileWritePolicy::APP, FileWritePolicy::KEY, 'not-json', false);
		$config->clearCache(false);
		$contact = $this->plant();
		$this->assertBackup($this->deleteContact($contact), $contact['data']);
	}
}
