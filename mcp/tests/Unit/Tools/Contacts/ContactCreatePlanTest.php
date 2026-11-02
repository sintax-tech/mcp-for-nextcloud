<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Platform\Dav\DavResult;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Tools\Contacts\ContactAccess;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\Contacts\ContactCard;
use OCA\Mcp\Tools\Contacts\ContactDav;
use OCA\Mcp\Tools\Contacts\ContactStore;
use OCA\Mcp\Tools\Contacts\ContactsModule;
use OCA\Mcp\Tools\Contacts\SystemContacts;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * The UID shown in a contact creation plan is the UID stored when that plan is confirmed.
 */
final class ContactCreatePlanTest extends TestCase {
	private const BOOK = '/remote.php/dav/addressbooks/users/alice/personal/';

	private ContactDav $dav;
	private ContactsModule $module;
	/** @var array{uri:string, etag:string, data:string} */
	private array $stored = ['uri' => 'none.vcf', 'etag' => '"v0"', 'data' => ''];
	private int $puts = 0;

	/**
	 * Address book whose read-back returns the vCard stored by the last put.
	 */
	protected function setUp(): void {
		$store = $this->createMock(ContactStore::class);
		$store->method('books')->willReturn([
			[
				'id' => 1,
				'uri' => 'personal',
				'displayName' => 'Personal',
				'ownerPrincipal' => 'principals/users/alice',
				'readOnly' => false,
			],
		]);
		$store->method('card')->willReturnCallback(fn (): array => $this->stored);
		$this->dav = $this->createMock(ContactDav::class);
		$this->dav->method('put')->willReturnCallback(function (string $userId, string $bookUri, string $uri, string $vcard): DavResult {
			$this->puts++;
			$this->stored = ['uri' => $uri, 'etag' => '"v1"', 'data' => $vcard];

			return new DavResult(201, '"v1"');
		});
		$config = new InMemoryConfig();
		$config->system['secret'] = 'contact-plan-secret';
		$this->module = new ContactsModule(
			new ContactAccess($store),
			$store,
			$this->dav,
			new ContactCard(),
			new SharedGuard($this->createMock(IUserManager::class)),
			$this->createMock(ContactBackup::class),
			$this->createMock(SystemContacts::class),
			new PlanState($config->mock($this)),
		);
	}

	/**
	 * Preview and the confirmed write share one UID, the one plan_state carries.
	 */
	public function testTheConfirmedContactKeepsTheUidThePlanShowed(): void {
		$plan = $this->module->preview('contacts_create_contact', ['addressbook' => self::BOOK, 'name' => 'Ana'], 'alice');
		$uid = $plan['after']['uid'];

		self::assertSame($uid . '.', substr($plan['plan_state'], 0, strlen($uid) + 1));
		$result = $this->json($this->module->call('contacts_create_contact', [
			'addressbook' => self::BOOK,
			'name' => 'Ana',
			'confirm' => true,
			'plan_state' => $plan['plan_state'],
		], 'alice'));

		self::assertSame(1, $this->puts);
		self::assertSame($uid, $result['uid']);
		self::assertSame($uid . '.vcf', $result['uri']);
		self::assertSame($uid, (string)Reader::read($this->stored['data'])->UID);
		self::assertSame(1, substr_count($this->stored['data'], 'UID:'));
	}

	/**
	 * A changed field answers with a new plan that keeps the UID and writes nothing.
	 */
	public function testAChangedPlanKeepsTheUidAndWritesNothing(): void {
		$plan = $this->module->preview('contacts_create_contact', [
			'addressbook' => self::BOOK,
			'name' => 'Ana',
			'emails' => ['ana@example.invalid'],
		], 'alice');
		$uid = $plan['after']['uid'];
		$changed = null;

		try {
			$this->module->call('contacts_create_contact', [
				'addressbook' => self::BOOK,
				'name' => 'Beatriz',
				'emails' => ['ana@example.invalid'],
				'confirm' => true,
				'plan_state' => $plan['plan_state'],
			], 'alice');
			self::fail('A changed plan was written.');
		} catch (PlanChanged $caught) {
			$changed = $caught;
			self::assertSame(0, $this->puts);
			self::assertSame($uid, $changed->plan['after']['uid']);
			self::assertSame('Beatriz', $changed->plan['after']['name']);
			self::assertNotSame($plan['plan_state'], $changed->plan['plan_state']);
			self::assertSame($uid . '.', substr($changed->plan['plan_state'], 0, strlen($uid) + 1));
			self::assertSame(
				'The creation plan changed. Review the new plan before confirming.',
				$changed->plan['warnings'][0]['message'],
			);
		}
		self::assertInstanceOf(PlanChanged::class, $changed);

		$result = $this->json($this->module->call('contacts_create_contact', [
			'addressbook' => self::BOOK,
			'name' => 'Beatriz',
			'emails' => ['ana@example.invalid'],
			'confirm' => true,
			'plan_state' => $changed->plan['plan_state'],
		], 'alice'));

		self::assertSame(1, $this->puts);
		self::assertSame($uid, $result['uid']);
		self::assertSame($uid, (string)Reader::read($this->stored['data'])->UID);
	}

	/**
	 * A confirmation that does not carry the plan UID does not invent another contact.
	 */
	public function testAConfirmationWithoutThePlanUidWritesNothing(): void {
		$this->module->preview('contacts_create_contact', ['addressbook' => self::BOOK, 'name' => 'Ana'], 'alice');

		try {
			$this->module->call('contacts_create_contact', [
				'addressbook' => self::BOOK,
				'name' => 'Ana',
				'confirm' => true,
			], 'alice');
			self::fail('A confirmation without plan_state was written.');
		} catch (ArgumentValidationException) {
			self::assertSame(0, $this->puts);
		}
	}

	/** @param array{content:list<array{type:string, text:string}>} $result @return array<string, mixed> */
	private function json(array $result): array {
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
