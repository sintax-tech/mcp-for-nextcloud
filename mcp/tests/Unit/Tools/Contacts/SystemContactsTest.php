<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Tools\Contacts\SystemContacts;
use OCP\Contacts\IManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Verifies the read-only account catalog search: official IManager call, admin enumeration rules, no private fields. */
final class SystemContactsTest extends TestCase {
    /** @var array<string, string> core app config values, overridden per test */
    private array $config = [];
    /** @var list<array{string, list<string>, array<string, mixed>}> */
    private array $calls = [];
    /** @var list<array<string, mixed>> raw rows returned by the manager double */
    private array $rows = [];
    /** @var array<string, list<string>> group ids per user id */
    private array $groups = [];
    /** @var list<string> accounts that exist and are enabled */
    private array $accounts = ['alice', 'pedro', 'maria', 'joao'];
    private bool $systemRegistered = true;
    private SystemContacts $system;

    protected function setUp(): void {
        $this->groups = ['alice' => ['sales'], 'pedro' => ['sales'], 'maria' => ['ops'], 'joao' => []];
        $manager = $this->createMock(IManager::class);
        $manager->method('getUserAddressBooks')->willReturnCallback(function (): array {
            $personal = $this->createMock(IAddressBook::class);
            $personal->method('getKey')->willReturn('1');
            $personal->method('isSystemAddressBook')->willReturn(false);
            $personal->method('getDisplayName')->willReturn('Personal');
            $books = ['1' => $personal];
            if ($this->systemRegistered) {
                $system = $this->createMock(IAddressBook::class);
                $system->method('getKey')->willReturn('9');
                $system->method('isSystemAddressBook')->willReturn(true);
                $system->method('getDisplayName')->willReturn('Contas');
                $books['9'] = $system;
            }
            return $books;
        });
        $manager->method('search')->willReturnCallback(function ($pattern, $properties, $options): array {
            $this->calls[] = [$pattern, $properties, $options];
            return $this->rows;
        });
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $app === 'core' ? ($this->config[$key] ?? $default) : $default
        );
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!in_array($uid, $this->accounts, true)) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            $user->method('getBackendClassName')->willReturn('Database');
            return $user;
        });
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('getUserGroupIds')->willReturnCallback(fn (IUser $user): array => $this->groups[$user->getUID()] ?? []);
        $groups->method('isInGroup')->willReturnCallback(
            fn (string $uid, string $gid): bool => in_array($gid, $this->groups[$uid] ?? [], true)
        );
        $this->system = new SystemContacts($manager, $config, $groups, $users);
        $this->rows = [
            $this->row('pedro', 'Pedro Almeida', ['pedro@example.invalid'], '9'),
            $this->row('maria', 'Pedro Maria', ['maria@example.invalid'], '9'),
            ['addressbook-key' => '1', 'UID' => 'personal-1', 'URI' => 'personal-1.vcf', 'FN' => 'Pedro Pessoal'],
        ];
    }

    /**
     * @param list<string> $emails
     * @return array<string, mixed>
     */
    private function row(string $uid, string $name, array $emails, string $book): array {
        return [
            'id' => 5,
            'addressbook-key' => $book,
            'isLocalSystemBook' => true,
            'UID' => $uid,
            'URI' => 'Database:' . $uid . '.vcf',
            'FN' => $name,
            'EMAIL' => $emails,
            'ORG' => 'Dalcomad;TI',
            'TITLE' => 'Analyst',
            'NICKNAME' => 'ped',
            'TEL' => ['+55 11 5555-0000'],
            'ADR' => ['Rua X'],
            'NOTE' => 'private note',
            'BDAY' => '19900101',
            'PHOTO' => 'binary',
            'CLOUD' => [$uid . '@cloud.invalid'],
        ];
    }

    public function testSearchUsesTheOfficialManagerAndKeepsOnlySystemResults(): void {
        $items = $this->system->search('alice', 'Pedro');
        self::assertCount(1, $this->calls);
        [$pattern, $properties, $options] = $this->calls[0];
        self::assertSame('Pedro', $pattern);
        self::assertSame(['FN', 'EMAIL', 'NICKNAME', 'ORG'], $properties);
        self::assertTrue($options['enumeration']);
        self::assertTrue($options['fullmatch']);
        self::assertFalse($options['strict_search']);
        self::assertSame(SystemContacts::CAP, $options['limit']);
        self::assertSame(['pedro', 'maria'], array_column($items, 'accountId'));
        self::assertSame('Pedro Almeida', $items[0]['name']);
        self::assertSame(['pedro@example.invalid'], $items[0]['emails']);
        self::assertSame('Dalcomad', $items[0]['organization']);
        self::assertSame('Analyst', $items[0]['title']);
        self::assertSame('ped', $items[0]['nickname']);
        self::assertSame('Database:pedro.vcf', $items[0]['uri']);
        self::assertSame('/remote.php/dav/addressbooks/users/alice/z-server-generated--system/', $items[0]['addressbook']);
        self::assertSame(['name' => 'Contas', 'system' => true], $items[0]['addressBook']);
        self::assertTrue($items[0]['readOnly']);
    }

    public function testPrivateFieldsAreNeverExposed(): void {
        $encoded = json_encode($this->system->search('alice', 'Pedro'), JSON_THROW_ON_ERROR);
        foreach (['5555-0000', 'Rua X', 'private note', '19900101', 'binary', 'cloud.invalid'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function testShortOrEmptyQueryNeverCallsTheManager(): void {
        self::assertSame([], $this->system->search('alice', ''));
        self::assertSame([], $this->system->search('alice', ' p '));
        self::assertSame([], $this->calls);
    }

    public function testNoSystemBookMeansNoCatalogAndNoCall(): void {
        $this->systemRegistered = false;
        self::assertNull($this->system->bookName('alice'));
        self::assertSame([], $this->system->search('alice', 'Pedro'));
        self::assertSame([], $this->calls);
    }

    public function testEnumerationOffAllowsOnlyConfiguredFullMatches(): void {
        $this->config = ['shareapi_allow_share_dialog_user_enumeration' => 'no'];
        $this->rows = [$this->row('pedro', 'Pedro Almeida', ['pedro@example.invalid'], '9')];
        $items = $this->system->search('alice', 'Pedro Almeida');
        [, $properties, $options] = $this->calls[0];
        self::assertSame(['FN', 'EMAIL', 'UID'], $properties);
        self::assertFalse($options['enumeration']);
        self::assertTrue($options['strict_search']);
        self::assertSame(['pedro'], array_column($items, 'accountId'));
    }

    public function testFullMatchFlagsNarrowTheSearchedProperties(): void {
        $this->config = [
            'shareapi_allow_share_dialog_user_enumeration' => 'no',
            'shareapi_restrict_user_enumeration_full_match_email' => 'no',
            'shareapi_restrict_user_enumeration_full_match_userid' => 'no',
        ];
        $this->system->search('alice', 'Pedro Almeida');
        self::assertSame(['FN'], $this->calls[0][1]);
    }

    public function testEnumerationAndFullMatchOffHidesTheCatalogCompletely(): void {
        $this->config = [
            'shareapi_allow_share_dialog_user_enumeration' => 'no',
            'shareapi_restrict_user_enumeration_full_match' => 'no',
        ];
        self::assertNull($this->system->bookName('alice'));
        self::assertSame([], $this->system->search('alice', 'Pedro'));
        self::assertSame([], $this->calls);
    }

    public function testGroupRestrictionKeepsOnlyTheCallersGroupMates(): void {
        $this->config = ['shareapi_restrict_user_enumeration_to_group' => 'yes'];
        $items = $this->system->search('alice', 'Pedro');
        self::assertSame(['pedro'], array_column($items, 'accountId'));
    }

    public function testPhoneRestrictionAloneShowsOnlyTheCallerItself(): void {
        $this->config = ['shareapi_restrict_user_enumeration_to_phone' => 'yes'];
        $this->rows = [
            $this->row('alice', 'Alice Self', ['alice@example.invalid'], '9'),
            $this->row('pedro', 'Pedro Almeida', ['pedro@example.invalid'], '9'),
        ];
        self::assertSame(['alice'], array_column($this->system->search('alice', 'al'), 'accountId'));
    }

    public function testGuestRowsDisabledAndUnknownAccountsAreDropped(): void {
        $guest = $this->row('guestx', 'Guest X', ['g@example.invalid'], '9');
        $guest['URI'] = 'Guests:guestx.vcf';
        $this->rows = [$guest, $this->row('ghost', 'Ghost Account', [], '9'), $this->row('pedro', 'Pedro Almeida', [], '9')];
        self::assertSame(['pedro'], array_column($this->system->search('alice', 'Pedro'), 'accountId'));
    }

    public function testDuplicateRowsOfOneAccountAppearOnce(): void {
        $this->rows = [$this->row('pedro', 'Pedro Almeida', [], '9'), $this->row('pedro', 'Pedro Almeida', [], '9')];
        self::assertCount(1, $this->system->search('alice', 'Pedro'));
    }

    public function testFindReturnsTheCardByUriThroughAnExactUidSearch(): void {
        $this->rows = [$this->row('pedro', 'Pedro Almeida', [], '9'), $this->row('pedro2', 'Pedro Dois', [], '9')];
        $this->accounts[] = 'pedro2';
        $item = $this->system->find('alice', 'Database:pedro.vcf');
        self::assertSame('pedro', $item['accountId'] ?? null);
        [$pattern, $properties, $options] = $this->calls[0];
        self::assertSame('pedro', $pattern);
        self::assertSame(['UID'], $properties);
        self::assertTrue($options['strict_search']);
    }

    public function testFindRefusesMalformedOrUnlistedUris(): void {
        self::assertNull($this->system->find('alice', '../etc/passwd'));
        self::assertNull($this->system->find('alice', 'pedro.vcf'));
        $this->rows = [$this->row('maria', 'Pedro Maria', [], '9')];
        self::assertNull($this->system->find('alice', 'Database:pedro.vcf'));
    }

    public function testFindAppliesTheSameEnumerationRules(): void {
        $this->config = ['shareapi_restrict_user_enumeration_to_group' => 'yes'];
        $this->rows = [$this->row('maria', 'Pedro Maria', [], '9')];
        self::assertNull($this->system->find('alice', 'Database:maria.vcf'));
    }

    public function testFindOfOwnCardWorksEvenWhenTheCatalogIsHidden(): void {
        $this->config = [
            'shareapi_allow_share_dialog_user_enumeration' => 'no',
            'shareapi_restrict_user_enumeration_full_match' => 'no',
        ];
        $this->rows = [$this->row('alice', 'Alice Self', [], '9')];
        self::assertSame('alice', $this->system->find('alice', 'Database:alice.vcf')['accountId'] ?? null);
        $this->rows = [$this->row('pedro', 'Pedro Almeida', [], '9')];
        self::assertNull($this->system->find('alice', 'Database:pedro.vcf'));
    }

    public function testFindOfOtherAccountsNeedsTheUserIdFullMatchWhenEnumerationIsOff(): void {
        $this->config = [
            'shareapi_allow_share_dialog_user_enumeration' => 'no',
            'shareapi_restrict_user_enumeration_full_match_userid' => 'no',
        ];
        $this->rows = [$this->row('pedro', 'Pedro Almeida', [], '9')];
        self::assertNull($this->system->find('alice', 'Database:pedro.vcf'));
    }
}
