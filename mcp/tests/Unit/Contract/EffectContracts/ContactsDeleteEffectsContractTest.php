<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use DateTimeImmutable;
use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Tasks\IneffectiveLocks;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Tools\Contacts\ContactAccess;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\Contacts\ContactCard;
use OCA\Mcp\Tools\Contacts\ContactsModule;
use OCA\Mcp\Tools\Contacts\SystemContacts;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Contacts\IManager;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the permanent deletion of a contact (RE-01 round 2), frozen through the endpoint: the in-memory
 * address books and the CardDAV requests they received, plus the mandatory vCard backup the module saves in the Files
 * of alice before deleting (a fake file tree, with a frozen clock). The random suffix the backup draws for its file
 * name is replaced in the transcript only, after its shape was asserted on the name the tree really holds.
 *
 * The creation of a contact stays out (RE-01 decision Q1-a, finding 2); see {@see ContactsEffectsContractTest}.
 */
final class ContactsDeleteEffectsContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';
	/** Path of the address book of alice the flow deletes from. */
	private const BOOK = '/remote.php/dav/addressbooks/users/alice/personal/';
	/** The moment of every request, in UTC. */
	private const NOW = '2026-03-10T12:00:00Z';
	/** Shape of a backup file name: contact, local stamp of {@see self::NOW} in São Paulo, 64 random bits. */
	private const BACKUP_NAME = '/^(Maria Souza|João Lima)\.20260310-090000-([0-9a-f]{16})\.vcf$/';

	private MemoryContacts $contacts;
	private FakeTree $files;
	private EffectsGate $gate;

	/** @return void builds the address book with two contacts, the Files of alice and the endpoint */
	protected function setUp(): void {
		$this->contacts = (new MemoryContacts())->addBook(1, 'personal', 'Contatos', self::ALICE);
		$this->contacts->plant(1, 'c1.vcf', self::card('c1', 'Maria Souza', 'Souza;Maria;;;'));
		$this->contacts->plant(1, 'c2.vcf', self::card('c2', 'João Lima', 'Lima;João;;;'));
		$this->files = new FakeTree($this);
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return ContactsModule the module of one request, every collaborator present as the container autowires it */
	private function module(): ContactsModule {
		$users = FakeUsers::manager($this, ['alice' => 'Alice']);
		$catalog = $this->createMock(IManager::class);
		$catalog->method('getUserAddressBooks')->willReturn([]);
		$roots = $this->createMock(IRootFolder::class);
		$roots->method('getUserFolder')->willReturnCallback(fn () => $this->files->rootFolder());
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturnCallback(static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW));
		$location = new BackupLocation(BackupDocumentFixture::fromConfig($this, $this->gate->data()->appConfig($this)));
		return new ContactsModule(new ContactAccess($this->contacts), $this->contacts, $this->contacts, new ContactCard(),
			new SharedGuard($users),
			new ContactBackup($roots, $time, $this->gate->config(), new IneffectiveLocks(), $location),
			new SystemContacts($catalog, $this->createMock(IAppConfig::class), $this->createMock(IGroupManager::class), $users));
	}

	/**
	 * contacts_delete_contact confirmed with the ETag of its plan: plan, strict confirm, the verified backup followed by
	 * the conditional CardDAV delete, and every refusal. After the confirmed deletion each refusal targets the other
	 * contact, so it is asked with a confirmation that would really delete.
	 *
	 * @return void
	 */
	public function testPermanentDeletionOfAContactAfterItsBackup(): void {
		$flow = (new CriticalFlow('delete-contact', 'contacts_delete_contact', 'contacts', 'delete',
			fn (): array => ['addressbook' => self::BOOK, 'uri' => isset($this->contacts->cards[1]['c1.vcf']) ? 'c1.vcf' : 'c2.vcf'],
			fn (): array => $this->state()))
			->confirmingWith(fn (mixed $plan, array $arguments): array => $arguments
				+ ['confirm' => true, 'etag' => $plan->result->structuredContent->etag ?? null])
			->withoutPlanChanged('The Contacts module keeps no plan fingerprint (no PlanState/PlanChanged). The plan '
				. 'returns the ETag of the contact and the confirmed call carries it: when the card changed in between, '
				. 'the ETag no longer matches and the call is refused as a conflict before any backup or delete '
				. '(asserted outside the golden); the CardDAV delete itself carries it as If-Match.')
			->withoutVisibility('Contacts are CardDAV objects, not Files nodes: no system tag or excluded folder can hide '
				. 'an address book or a card from MCP (VisibilityGuard and FilesAccessGuard do not apply). The accounts '
				. 'catalog is read-only by design and foreign books are not found: permission refusals, not visibility.')
			->normalizing(fn (): array => $this->backupSuffixes());
		(new FlowRunner($this, $this->gate, 'contacts'))->run($flow);

		self::assertSame(['c2.vcf'], array_keys($this->contacts->cards[1]), 'only the confirmed contact is deleted');
		self::assertSame(['delete personal/c1.vcf'], $this->contacts->log, 'one CardDAV delete, of the confirmed contact');
		$backups = $this->backups();
		self::assertCount(1, $backups, 'exactly one backup, of the deleted contact');
		self::assertSame(self::card('c1', 'Maria Souza', 'Souza;Maria;;;'), $backups[0]['vcard'], 'the backup is the whole card');
		self::assertStringStartsWith('/alice/files/MCP backups/Contacts/Contatos/', $backups[0]['path']);
		foreach ($backups as $backup) {
			self::assertMatchesRegularExpression(self::BACKUP_NAME, basename($backup['path']));
		}
	}

	/** @return void a deletion confirmed with the ETag of an older card is refused before any backup or delete */
	public function testDeletionWithAStaleEtagIsRefused(): void {
		$arguments = ['addressbook' => self::BOOK, 'uri' => 'c1.vcf'];
		$plan = $this->gate->call('contacts_delete_contact', $arguments)['body'];
		$stale = $arguments + ['confirm' => true, 'etag' => $plan->result->structuredContent->etag];
		// Another client changes the card after the plan.
		$this->contacts->plant(1, 'c1.vcf', str_replace('END:VCARD', "NOTE:outro cliente\r\nEND:VCARD",
			$this->contacts->cards[1]['c1.vcf']['data']));
		$before = $this->state();
		$body = $this->gate->call('contacts_delete_contact', $stale)['body'];
		self::assertTrue($body->result->isError ?? false, 'a stale ETag must be refused: ' . json_encode($body));
		self::assertEquals($before, $this->state(), 'a stale ETag must back up and delete nothing');
	}

	/**
	 * @return array{cards: array<int, array<string, string>>, dav: list<string>, backups: list<array{path:string, vcard:string}>, files: list<string>}
	 *     the address books, the CardDAV requests, the backup copies in Files and the Files write log
	 */
	private function state(): array {
		return $this->contacts->state() + ['backups' => $this->backups(), 'files' => $this->files->ops];
	}

	/** @return list<array{path:string, vcard:string}> every vCard file in the Files of alice, in creation order */
	private function backups(): array {
		$out = [];
		foreach ($this->files->nodes as $path => $node) {
			if ($node['type'] === 'file' && str_ends_with($path, '.vcf')) {
				$out[] = ['path' => $path, 'vcard' => $node['content']];
			}
		}
		return $out;
	}

	/** @return array<string, string> random suffix of each backup file name => placeholder, for the transcript */
	private function backupSuffixes(): array {
		$map = [];
		foreach ($this->backups() as $backup) {
			if (preg_match(self::BACKUP_NAME, basename($backup['path']), $match) === 1) {
				$map[$match[2]] ??= '<random-backup-suffix#' . (count($map) + 1) . '>';
			}
		}
		return $map;
	}

	/**
	 * @param string $uid contact UID
	 * @param string $name formatted name
	 * @param string $structured N property
	 * @return string the vCard text as CardDAV stores it
	 */
	private static function card(string $uid, string $name, string $structured): string {
		return str_replace("\n", "\r\n", "BEGIN:VCARD\nVERSION:3.0\nUID:$uid\nFN:$name\nN:$structured\n"
			. "EMAIL;TYPE=WORK:$uid@example.invalid\nX-CUSTOM;X-PARAM=keep:intocado\nEND:VCARD\n");
	}
}
