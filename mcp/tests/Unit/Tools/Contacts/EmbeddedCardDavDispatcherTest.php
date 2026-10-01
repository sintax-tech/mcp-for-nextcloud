<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Tests\Unit\Tools\Calendar\MemoryPrincipalBackend;
use OCA\Mcp\Tools\Calendar\Session;
use OCA\Mcp\Tools\Contacts\EmbeddedCardDavDispatcher;
use OCA\Mcp\Tools\ToolFailure;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Sabre\CardDAV\AddressBookHome;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Plugin;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalCollection;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

/** Real Sabre CardDAV validates data and enforces HTTP preconditions and ACL, with no Nextcloud server. */
final class EmbeddedCardDavDispatcherTest extends TestCase {
    private MemoryCardBackend $backend;
    private EmbeddedCardDavDispatcher $dav;
    private Server $server;
    private const CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:Alice Contact\r\nN:Contact;Alice;;;\r\nEND:VCARD\r\n";

    protected function setUp(): void {
        $this->backend = new MemoryCardBackend();
        $this->dav = $this->dispatcher('alice');
    }

    public function testCreateEditDeleteUseNativePipelineAndEtag(): void {
        $created = $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
        self::assertSame(201, $created->status);
        self::assertSame(
            'principals/users/alice',
            $this->server->getPlugin('auth')->getCurrentPrincipal()
        );
        $updated = $this->dav->update(
            'alice',
            'personal',
            'c1.vcf',
            $created->etag,
            str_replace('Alice Contact', 'New Contact', self::CARD)
        );
        self::assertSame(204, $updated->status);
        self::assertStringContainsString('New Contact', $this->backend->cards['c1.vcf']['carddata']);
        self::assertSame(
            204,
            $this->dav->delete('alice', 'personal', 'c1.vcf', $updated->etag)->status
        );
        self::assertSame([], $this->backend->cards);
    }

    public function testCreateNeverOverwrites(): void {
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
        $this->expectException(ToolFailure::class);
        $this->dav->put('alice', 'personal', 'c1.vcf', str_replace('Alice', 'Other', self::CARD));
    }

    public function testStaleEtagDoesNotUpdate(): void {
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
        try {
            $this->dav->update(
                'alice',
                'personal',
                'c1.vcf',
                '"old"',
                str_replace('Alice', 'Other', self::CARD)
            );
            self::fail();
        } catch (ToolFailure $e) {
            self::assertSame(412, $e->getCode());
        }
        self::assertSame(self::CARD, $this->backend->cards['c1.vcf']['carddata']);
    }

    public function testAclRefusalNeverCreatesCard(): void {
        $this->backend->owner = 'principals/users/bob';
        $this->expectException(ToolFailure::class);
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
    }

    public function testInvalidVcardIsRefusedBySabre(): void {
        $this->expectException(ToolFailure::class);
        $this->dav->put('alice', 'personal', 'broken.vcf', 'not a card');
    }

    public function testSessionMismatchAndPathEscapeFailBeforeServerCreation(): void {
        $this->expectException(RuntimeException::class);
        $this->dav->put('bob', 'personal', 'c1.vcf', self::CARD);
    }

    public function testEncodedSegmentsCannotEscapeBook(): void {
        $this->expectException(ToolFailure::class);
        $this->dav->put('alice', 'personal', '../outside', self::CARD);
    }

    public function testSizeLimitBeforeAnyDavCallAndNoOutput(): void {
        $this->dav = $this->dispatcher('alice', 10);
        $this->expectException(ToolFailure::class);
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
    }

    public function testDispatchCapturesOutputAndUsesFreshServerEachTime(): void {
        ob_start();
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
        $first = $this->server;
        $this->dav->put('alice', 'personal', 'c2.vcf', str_replace('UID:c1', 'UID:c2', self::CARD));
        self::assertSame('', ob_get_clean());
        self::assertNotSame($first, $this->server);
    }

    private function dispatcher(string $uid, int $limit = 5242880): EmbeddedCardDavDispatcher {
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willReturn($limit);
        return new EmbeddedCardDavDispatcher(fn () => $this->server(), new Session($session), $config, new NullLogger());
    }

    private function server(): Server {
        $principals = new MemoryPrincipalBackend();
        $principals->addUser('alice', 'alice@example.invalid');
        $principals->addUser('bob', 'bob@example.invalid');
        $home = new AddressBookHome($this->backend, 'principals/users/alice');
        $server = new Server(
            new SimpleCollection(
                'root',
                [
                    new SimpleCollection('addressbooks', [new SimpleCollection('users', [$home])]),
                    new SimpleCollection('principals', [new PrincipalCollection($principals, 'principals/users')]),
                ]
            )
        );
        $server->setBaseUri('/remote.php/dav/');
        $server->addPlugin(new PinnedCardPrincipal());
        $server->addPlugin(new AclPlugin());
        $server->addPlugin(new Plugin());
        return $this->server = $server;
    }
}
final class PinnedCardPrincipal extends AuthPlugin {
    public function setCurrentPrincipal(?string $principal): void {
        $this->currentPrincipal = $principal;
    }
}
final class MemoryCardBackend extends AbstractBackend {
    public array $cards = [];
    public string $owner = 'principals/users/alice';

    public function getAddressBooksForUser($principalUri) {
        return [
            [
                'id' => 1,
                'uri' => 'personal',
                'principaluri' => $this->owner,
                '{DAV:}displayname' => 'Personal',
            ],
        ];
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch) {}

    public function createAddressBook($principalUri, $url, array $properties) {
        return 1;
    }

    public function deleteAddressBook($addressBookId) {}

    public function getCards($addressbookId) {
        return array_values($this->cards);
    }

    public function getCard($addressBookId, $cardUri) {
        return $this->cards[$cardUri] ?? false;
    }

    public function createCard($addressBookId, $cardUri, $cardData) {
        return $this->updateCard($addressBookId, $cardUri, $cardData);
    }

    public function updateCard($addressBookId, $cardUri, $cardData) {
        $etag = '"' . md5($cardData) . '"';
        $this->cards[$cardUri] = [
            'uri' => $cardUri,
            'carddata' => $cardData,
            'etag' => $etag,
            'size' => strlen($cardData),
            'lastmodified' => time(),
        ];
        return $etag;
    }

    public function deleteCard($addressBookId, $cardUri) {
        unset($this->cards[$cardUri]);
        return true;
    }
}
