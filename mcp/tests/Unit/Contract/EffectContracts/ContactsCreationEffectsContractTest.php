<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
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
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Contacts\IManager;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/** Freezes the creation fixed in 1.2.4: the shown UID and signed plan are the ones CardDAV writes. */
final class ContactsCreationEffectsContractTest extends TestCase {
	private const BOOK = '/remote.php/dav/addressbooks/users/alice/personal/';
	private MemoryContacts $contacts;
	private EffectsGate $gate;
	private RecordingContactCard $cards;

	/** @return void native CardDAV store and real plan-state service for each request */
	protected function setUp(): void {
		$this->contacts = (new MemoryContacts())->addBook(1, 'personal', 'Contatos', 'principals/users/alice');
		$this->cards = new RecordingContactCard($this);
		$this->gate = new EffectsGate($this);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return ContactsModule the module of one request, every collaborator present as the container autowires it */
	private function module(): ContactsModule {
		$users = FakeUsers::manager($this, ['alice' => 'Alice']);
		$catalog = $this->createMock(IManager::class);
		$catalog->method('getUserAddressBooks')->willReturn([]);
		$location = new BackupLocation(BackupDocumentFixture::fromConfig($this, $this->gate->data()->appConfig($this)));
		return new ContactsModule(new ContactAccess($this->contacts), $this->contacts, $this->contacts, $this->cards,
			new SharedGuard($users),
			new ContactBackup($this->createMock(IRootFolder::class), $this->createMock(ITimeFactory::class), $this->gate->config(),
				new IneffectiveLocks(), $location),
			new SystemContacts($catalog, $this->createMock(IAppConfig::class), $this->createMock(IGroupManager::class), $users), new PlanState($this->gate->config()));
	}

	/** @return void strict confirmation, changed fields, real creation, no grant and Guest refusals */
	public function testCreatingAContact(): void {
		$flow = (new CriticalFlow('create-contact', 'contacts_create_contact', 'contacts', 'create',
			['addressbook' => self::BOOK, 'name' => 'Ana', 'emails' => ['ana@example.invalid']],
			fn (): array => $this->state()))
			->withoutPlanChanged('Creation binds the submitted fields rather than existing rows; changed-field confirmation is frozen by testTheShownUidIsWrittenOnlyWithItsPlanState.')
			->withoutVisibility('CardDAV contacts are not Files nodes; native book ACLs apply, not Files system tags.')
			->normalizing(fn (): array => $this->literals());
		(new FlowRunner($this, $this->gate, 'contacts'))->run($flow);
		$this->assertCount(1, $this->contacts->cards[1]);
	}

	/** @return void no token or fields from another plan write nothing; valid approval persists exactly its UID */
	public function testTheShownUidIsWrittenOnlyWithItsPlanState(): void {
		$args = ['addressbook' => self::BOOK, 'name' => 'Ana'];
		$tool = 'contacts_create_contact';
		$t = new EffectTranscript($this, 'contacts.create-plan-identity');
		$plan = $this->gate->call($tool, $args);
		$t->record('preview', $args, $plan, $this->state());
		$uid = $plan['body']->result->structuredContent->after->uid;
		$token = $plan['body']->result->structuredContent->plan_state;
		$this->assertStringStartsWith($uid . '.', $token);
		foreach (['missing-plan' => $args + ['confirm' => true],
			'other-fields' => ['addressbook' => self::BOOK, 'name' => 'Another', 'confirm' => true, 'plan_state' => $token]] as $step => $request) {
			$out = $this->gate->call($tool, $request);
			$t->record($step, $request, $out, $this->state());
			$this->assertSame([], $this->contacts->cards[1]);
			$this->assertSame([], $this->contacts->log);
			if ($step === 'missing-plan') {
				$this->assertTrue(isset($out['body']->error) || ($out['body']->result->isError ?? false), json_encode($out));
			} else {
				$this->assertTrue($out['body']->result->structuredContent->requiresConfirmation ?? false, json_encode($out));
				$this->assertSame($uid, $out['body']->result->structuredContent->after->uid, 'changed fields preserve the shown UID');
			}
		}
		$request = $args + ['confirm' => true, 'plan_state' => $token];
		$out = $this->gate->call($tool, $request);
		$t->record('confirmed', $request, $out, $this->state());
		$this->assertFalse($out['body']->result->isError ?? false, json_encode($out));
		$written = json_decode($out['body']->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($uid, $written['uid']);
		$this->assertArrayHasKey($uid . '.vcf', $this->contacts->cards[1]);
		$this->assertStringContainsString('UID:' . $uid . "\r\n", $this->contacts->cards[1][$uid . '.vcf']['data']);
		$t->assertGolden($this->literals());
	}

	/** @return array<string, string> only the real generated UIDs and their content-derived native ETags */
	private function literals(): array {
		$map = $this->cards->literals;
		foreach (array_values($this->contacts->cards[1]) as $i => $row) {
			$this->assertSame('"' . md5($row['data']) . '"', $row['etag']);
			$replacement = '<contact-etag#' . ($i + 1) . '>';
			$map[$row['etag']] = $replacement;
			$map[trim($row['etag'], '"')] = $replacement;
		}
		return $map;
	}

	/** @return array<string, mixed> URI as a value so only the copy normalizes it; original CardDAV rows retain real keys */
	private function state(): array {
		return ['cards' => array_values($this->contacts->cards[1]), 'dav' => $this->contacts->log];
	}
}
