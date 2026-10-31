<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Tasks\IneffectiveLocks;
use OCA\Mcp\Tools\Calendar\SharedGuard;
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
 * Effect contract of the Contacts module (RE-01): the edit of a contact, frozen through the endpoint with in-memory
 * address books as the observable effect and the CardDAV requests they received.
 *
 * Creation is frozen separately by ContactsCreationEffectsContractTest after the 1.2.4 UID/plan-state correction.
 */
final class ContactsEffectsContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';
	/** Path of the address book of alice the flow writes to. */
	private const BOOK = '/remote.php/dav/addressbooks/users/alice/personal/';
	/** URI of the contact the flow edits. */
	private const CARD = 'c1.vcf';

	private MemoryContacts $contacts;
	private EffectsGate $gate;

	/** @return void builds the address book, its contact and the endpoint */
	protected function setUp(): void {
		$this->contacts = (new MemoryContacts())->addBook(1, 'personal', 'Contatos', self::ALICE);
		$this->contacts->plant(1, self::CARD, str_replace("\n", "\r\n", "BEGIN:VCARD\nVERSION:3.0\nUID:c1\nFN:Maria Souza\n"
			. "N:Souza;Maria;;;\nEMAIL;TYPE=WORK:maria@example.invalid\nX-CUSTOM;X-PARAM=keep:intocado\nEND:VCARD\n"));
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return ContactsModule the module of one request, every collaborator present as the container autowires it */
	private function module(): ContactsModule {
		$users = FakeUsers::manager($this, ['alice' => 'Alice']);
		$catalog = $this->createMock(IManager::class);
		$catalog->method('getUserAddressBooks')->willReturn([]);
		$location = new BackupLocation(BackupDocumentFixture::fromConfig($this, $this->gate->data()->appConfig($this)));
		return new ContactsModule(new ContactAccess($this->contacts), $this->contacts, $this->contacts, new ContactCard(),
			new SharedGuard($users),
			new ContactBackup($this->createMock(IRootFolder::class), $this->createMock(ITimeFactory::class), $this->gate->config(),
				new IneffectiveLocks(), $location),
			new SystemContacts($catalog, $this->createMock(IAppConfig::class), $this->createMock(IGroupManager::class), $users));
	}

	/**
	 * contacts_edit_contact of a contact of alice, confirmed with the ETag of its plan: plan, strict confirm, effect and
	 * every refusal; a stale ETag is refused with no write, which is what the module has instead of PlanChanged.
	 *
	 * @return void
	 */
	public function testEditOfAContact(): void {
		$flow = (new CriticalFlow('edit-contact', 'contacts_edit_contact', 'contacts', 'edit',
			['addressbook' => self::BOOK, 'uri' => self::CARD, 'name' => 'Maria Souza Lima', 'organization' => 'Dalcomad'],
			fn (): array => $this->contacts->state()))
			->confirmingWith(fn (mixed $plan, array $arguments): array => $arguments
				+ ['confirm' => true, 'etag' => $plan->result->structuredContent->etag])
			->withoutPlanChanged('The Contacts module keeps no plan fingerprint (no PlanState/PlanChanged). The plan '
				. 'returns the ETag of the contact and the confirmed call carries it: when the card changed in between, '
				. 'the ETag no longer matches and the call is refused as a conflict, without a new plan.')
			->withoutVisibility('Contacts are CardDAV objects, not Files nodes: no system tag or excluded folder can hide '
				. 'an address book or a card from MCP (VisibilityGuard and FilesAccessGuard do not apply). The accounts '
				. 'catalog is read-only by design and foreign books are not found: permission refusals, not visibility.');
		(new FlowRunner($this, $this->gate, 'contacts'))->run($flow);
		$stale = $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']);
		// Another client changes the card after the plan.
		$this->contacts->plant(1, self::CARD, str_replace('END:VCARD', "NOTE:outro cliente\r\nEND:VCARD",
			$this->contacts->cards[1][self::CARD]['data']));
		$before = $this->contacts->state();
		$body = $this->gate->call($flow->tool, $stale)['body'];
		self::assertTrue($body->result->isError ?? false, 'a stale ETag must be refused: ' . json_encode($body));
		self::assertEquals($before, $this->contacts->state(), 'a stale ETag must write nothing');
	}
}
