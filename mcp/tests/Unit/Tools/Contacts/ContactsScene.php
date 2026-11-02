<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Contacts\Backup\ContactBackup;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\ContactsModule;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Tests\Unit\Contract\EffectContracts\MemoryContacts;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Contacts\IManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Address books, accounts catalog and backup of the Contacts characterization (RE-15 b0), every double recording what
 * the module asked of it.
 *
 * alice owns "Contatos" (id 1). bob shares "Equipe" (id 2, writable), a book whose name is not valid UTF-8 (id 3) and
 * "Somente leitura" (id 4, read-only); a system principal owns id 5, which the module never lists. The catalog rows
 * cover a visible account, one whose e-mail a personal contact already has, guests by URI and by backend, a disabled
 * and a deleted account, a row of another address book and the user itself.
 */
final class ContactsScene {
	/** Path of the address book alice owns. */
	public const PERSONAL = '/remote.php/dav/addressbooks/users/alice/personal/';
	/** Path of the writable book bob shares with alice. */
	public const TEAM = '/remote.php/dav/addressbooks/users/alice/team/';
	/** Path of the shared book whose name is not valid UTF-8. */
	public const BROKEN = '/remote.php/dav/addressbooks/users/alice/broken/';
	/** Path of the read-only shared book. */
	public const READ_ONLY = '/remote.php/dav/addressbooks/users/alice/ro/';
	/** Rows the catalog double holds; the system address book has the key "sys". */
	private const ROWS = [
		['addressbook-key' => 'sys', 'UID' => 'bob', 'URI' => 'Database:bob.vcf', 'FN' => 'Bob Silva', 'EMAIL' => ['bob@example.invalid'],
			'ORG' => 'Equipe;TI', 'TITLE' => 'Dev', 'NICKNAME' => ['bobby'], 'TEL' => ['+55 11 90000-0000'], 'PHOTO' => 'secret'],
		['addressbook-key' => 'sys', 'UID' => 'carla', 'URI' => 'Database:carla.vcf', 'FN' => 'Carla Souza',
			'EMAIL' => [['value' => 'Carla@example.invalid', 'type' => 'work']], 'ORG' => ['Ops']],
		['addressbook-key' => 'sys', 'UID' => 'ana', 'URI' => 'Database:ana.vcf', 'FN' => 'ana souza', 'EMAIL' => 'ana@example.invalid'],
		['addressbook-key' => 'sys', 'UID' => 'gina', 'URI' => 'Database:gina.vcf', 'FN' => 'Gina Souza'],
		['addressbook-key' => 'sys', 'UID' => 'gus', 'URI' => 'Guests:gus.vcf', 'FN' => 'Gus Souza'],
		['addressbook-key' => 'sys', 'UID' => 'dave', 'URI' => 'Database:dave.vcf', 'FN' => 'Dave Souza'],
		['addressbook-key' => 'sys', 'UID' => 'zed', 'URI' => 'Database:zed.vcf', 'FN' => 'Zed Souza'],
		['addressbook-key' => 'sys', 'URI' => 'Database:nouid.vcf', 'FN' => 'Sem Souza'],
		['addressbook-key' => 'pers', 'UID' => 'eve', 'URI' => 'Database:eve.vcf', 'FN' => 'Eve Souza'],
		['addressbook-key' => 'sys', 'UID' => 'alice', 'URI' => 'Database:alice.vcf', 'FN' => 'Alice Admin', 'EMAIL' => ['alice@example.invalid']],
	];
	/** Accounts: display name, backend and whether enabled. zed no longer exists. */
	private const USERS = ['alice' => ['Alice', 'Database', true], 'bob' => ['Bob Silva', 'Database', true],
		'carla' => ['Carla Souza', 'Database', true], 'ana' => ['ana souza', 'LDAP', true], 'gina' => ['Gina Souza', 'Guests', true],
		'gus' => ['Gus Souza', 'Database', true], 'dave' => ['Dave Souza', 'Database', false]];

	public MemoryContacts $contacts;
	/** @var array<string, string> core settings the catalog reads; absent keys answer their default */
	public array $flags = [];
	/** @var list<array{pattern:string, properties:array, options:array}> every search the catalog was asked */
	public array $searches = [];
	/** @var list<array{user:string, book:string, name:string, vcard:string}> every backup the module asked for */
	public array $saves = [];
	/** Whether the next backups fail. */
	public bool $backupFails = false;
	/** @var (\Closure(): void)|null what happens to the books while a backup is being written */
	public ?\Closure $duringBackup = null;

	/** @param TestCase $test test that builds the mocks */
	public function __construct(private TestCase $test) {
		$this->contacts = (new MemoryContacts())->addBook(1, 'personal', 'Contatos', 'principals/users/alice')
			->addBook(2, 'team', 'Equipe', 'principals/users/bob')
			->addBook(3, 'broken', "Caderno \xC3\x28", 'principals/users/bob')
			->addBook(4, 'ro', 'Somente leitura', 'principals/users/bob')
			->addBook(5, 'system', 'Sistema', 'principals/system/system');
		$this->contacts->books[3]['readOnly'] = true;
		$this->contacts->plant(1, 'c1.vcf', self::card('c1', 'Maria Souza', "EMAIL;TYPE=WORK:maria@example.invalid\nX-CUSTOM;X-PARAM=keep:intocado"));
		$this->contacts->plant(1, 'c2.vcf', self::card('c2', 'Carla S.', 'EMAIL:carla@example.invalid'));
		$this->contacts->plant(1, 'bad.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nEND:VCARD\r\n");
		$this->contacts->plant(2, 't1.vcf', self::card('t1', 'Tiago Souza', 'TEL:+55 11 91111-1111'));
		$this->contacts->plant(3, 'b1.vcf', self::card('b1', 'Bruno', 'NOTE:caderno quebrado'));
		$this->contacts->plant(4, 'r1.vcf', self::card('r1', 'Rita', 'ORG:Leitura'));
	}

	/** @return ContactsModule the module of one request, every collaborator as the container builds it */
	public function module(): ContactsModule {
		$users = $this->users();
		$secret = new InMemoryConfig();
		$secret->system['secret'] = 'characterization';
		return new ContactsModule(new ContactAccess($this->contacts), $this->contacts, $this->contacts, new ContactCard(),
			new SharedGuard($users), $this->backup(),
			ContactsTestModule::system($this->catalog(), $this->appConfig(), $this->groups(), $users), new PlanState($secret->mock($this->test)));
	}

	/**
	 * @param string $uid contact UID
	 * @param string $name formatted name
	 * @param string $more further vCard lines
	 * @return string the vCard text as CardDAV stores it
	 */
	public static function card(string $uid, string $name, string $more): string {
		return str_replace("\n", "\r\n", "BEGIN:VCARD\nVERSION:3.0\nUID:$uid\nFN:$name\n$more\nEND:VCARD\n");
	}

	/** @return ContactBackup a backup that records the copy, can fail, and lets the books change while it writes */
	private function backup(): ContactBackup {
		return new class($this) extends ContactBackup {
			/** @param ContactsScene $scene where the copies are recorded */
			public function __construct(private ContactsScene $scene) {}

			/** {@inheritDoc} */
			public function directory(string $bookName): string {
				return 'MCP backups/Contacts/' . $bookName;
			}

			/** {@inheritDoc} */
			public function save(string $userId, string $bookName, string $name, string $data): string {
				$this->scene->saves[] = ['user' => $userId, 'book' => $bookName, 'name' => $name, 'vcard' => $data];
				if ($this->scene->backupFails) {
					throw new \RuntimeException('The backup could not be verified.');
				}
				if ($this->scene->duringBackup !== null) {
					($this->scene->duringBackup)();
				}
				return '/alice/files/MCP backups/Contacts/' . $bookName . '/' . $name . '.vcf';
			}
		};
	}

	/** @return IManager the contacts manager: a system address book "Contas" and a personal one, searched in memory */
	private function catalog(): IManager {
		$books = [];
		foreach (['sys' => [true, 'Contas'], 'pers' => [false, 'Contatos']] as $key => [$system, $name]) {
			$book = $this->mock(IAddressBook::class);
			$book->method('isSystemAddressBook')->willReturn($system);
			$book->method('getKey')->willReturn($key);
			$book->method('getDisplayName')->willReturn($name);
			$books[] = $book;
		}
		$catalog = $this->mock(IManager::class);
		$catalog->method('getUserAddressBooks')->willReturn($books);
		$catalog->method('search')->willReturnCallback(function ($pattern, $properties = [], $options = []): array {
			$this->searches[] = ['pattern' => $pattern, 'properties' => $properties, 'options' => $options];
			return array_values(array_filter(self::ROWS, static function (array $row) use ($pattern, $properties, $options): bool {
				foreach ($properties as $property) {
					foreach ((array)($row[$property] ?? []) as $value) {
						$value = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
						if (($options['strict_search'] ?? false) ? strcasecmp($value, $pattern) === 0 : stripos($value, $pattern) !== false) {
							return true;
						}
					}
				}
				return false;
			}));
		});
		return $catalog;
	}

	/** @return IAppConfig the core settings of {@see self::$flags} */
	private function appConfig(): IAppConfig {
		$config = $this->mock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $app === 'core' ? ($this->flags[$key] ?? $default) : $default);
		return $config;
	}

	/** @return IGroupManager alice, bob and carla share the group "eng" */
	private function groups(): IGroupManager {
		$groups = $this->mock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => $user->getUID() === 'alice' ? ['eng'] : []);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $gid === 'eng' && in_array($uid, ['alice', 'bob', 'carla'], true));
		return $groups;
	}

	/** @return IUserManager the accounts of {@see self::USERS} */
	private function users(): IUserManager {
		$users = [];
		foreach (self::USERS as $uid => [$name, $backend, $enabled]) {
			$user = $this->mock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($name);
			$user->method('getBackendClassName')->willReturn($backend);
			$user->method('isEnabled')->willReturn($enabled);
			$users[$uid] = $user;
		}
		$manager = $this->mock(IUserManager::class);
		$manager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
		return $manager;
	}

	/**
	 * @param class-string $class interface to double
	 * @return \PHPUnit\Framework\MockObject\MockObject a stub built by the test
	 */
	private function mock(string $class): object {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
