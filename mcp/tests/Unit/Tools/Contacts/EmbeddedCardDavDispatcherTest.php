<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
    /** How many times a native DAV server was built: a refusal that costs none happened before any DAV call. */
    private int $serversBuilt = 0;
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

    /**
     * g6-1: a ToolFailure is a RuntimeException too, so expecting the superclass would also pass when the
     * session check is gone and the 404 of the server stands in for it. This asserts the very refusal, and that
     * nothing was built or written.
     */
    public function testSessionMismatchFailsBeforeServerCreation(): void {
        try {
            $this->dav->put('bob', 'personal', 'c1.vcf', self::CARD);
            self::fail('the write ran as another principal than the session');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(ToolFailure::class, $e, 'the server answered, so the session was not checked first');
            self::assertSame('CardDAV session mismatch', $e->getMessage());
        }
        self::assertSame(0, $this->serversBuilt);
        self::assertSame([], $this->backend->cards);
    }

    public function testEverySessionMismatchOfEveryVerbFailsBeforeServerCreation(): void {
        foreach ([
            fn () => $this->dav->put('bob', 'personal', 'c1.vcf', self::CARD),
            fn () => $this->dav->update('bob', 'personal', 'c1.vcf', '"e"', self::CARD),
            fn () => $this->dav->delete('bob', 'personal', 'c1.vcf', '"e"'),
        ] as $call) {
            try {
                $call();
                self::fail('a verb ran as another principal');
            } catch (RuntimeException $e) {
                self::assertSame('CardDAV session mismatch', $e->getMessage());
            }
        }
        self::assertSame(0, $this->serversBuilt);
    }

    public function testEncodedSegmentsCannotEscapeBook(): void {
        $this->expectException(ToolFailure::class);
        $this->dav->put('alice', 'personal', '../outside', self::CARD);
    }

    /** @return array<string, array{string, string, string}> user, book, object */
    public static function unsafeSegmentsProvider(): array {
        return [
            'object is ..' => ['alice', 'personal', '..'], 'object is .' => ['alice', 'personal', '.'], 'object empty' => ['alice', 'personal', ''],
            'object with NUL' => ['alice', 'personal', "a\0b"], 'object with newline' => ['alice', 'personal', "x\n.vcf"],
            'object with slash' => ['alice', 'personal', 'a/b.vcf'], 'object with backslash' => ['alice', 'personal', 'a\\b.vcf'],
            'book is ..' => ['alice', '..', 'c1.vcf'], 'book is .' => ['alice', '.', 'c1.vcf'], 'book empty' => ['alice', '', 'c1.vcf'],
            'book with slash' => ['alice', 'a/b', 'c1.vcf'],
        ];
    }

    /** The segments are refused by the rule itself, before a server exists: a stale ETag would also stop most of them, later and for another reason. */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeSegmentsProvider')]
    public function testAnUnsafeSegmentIsRefusedBeforeAnyServerIsBuilt(string $user, string $book, string $object): void {
        foreach ([
            fn () => $this->dav->put($user, $book, $object, self::CARD),
            fn () => $this->dav->update($user, $book, $object, '"e"', self::CARD),
            fn () => $this->dav->delete($user, $book, $object, '"e"'),
        ] as $call) {
            try {
                $call();
                self::fail('an unsafe segment reached the server');
            } catch (ToolFailure $e) {
                self::assertSame(\OCA\Mcp\Tools\Common\CommonMessages::notFound(), $e->getMessage());
            }
        }
        self::assertSame(0, $this->serversBuilt);
    }

    /** g6-2: DELETE carries If-Match, so a card edited since the plan is not removed by a delete that was approved for the old one. */
    public function testDeleteWithAStaleEtagIsRefusedAndTheCardSurvives(): void {
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);

        try {
            $this->dav->delete('alice', 'personal', 'c1.vcf', '"old"');
            self::fail('the card was deleted over a stale ETag');
        } catch (ToolFailure $e) {
            self::assertSame(412, $e->getCode());
        }
        self::assertSame(self::CARD, $this->backend->cards['c1.vcf']['carddata']);
    }

    public function testDeleteWithTheCurrentEtagRemovesTheCard(): void {
        $created = $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);

        self::assertSame(204, $this->dav->delete('alice', 'personal', 'c1.vcf', $created->etag)->status);
        self::assertSame([], $this->backend->cards);
    }

    public function testRefusedWritesLeaveTheBookExactlyAsItWas(): void {
        $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
        $before = $this->backend->cards;
        $this->dav = $this->dispatcher('alice', 10);
        try {
            $this->dav->put('alice', 'personal', 'c2.vcf', self::CARD);
        } catch (ToolFailure) {
        }
        $this->dav = $this->dispatcher('alice');
        try {
            $this->dav->put('alice', 'personal', 'broken.vcf', 'not a card');
        } catch (ToolFailure) {
        }
        $this->backend->owner = 'principals/users/bob';
        try {
            $this->dav->put('alice', 'personal', 'c3.vcf', self::CARD);
        } catch (ToolFailure) {
        }

        self::assertSame($before, $this->backend->cards);
    }

    public function testSizeLimitBeforeAnyDavCallAndNoOutput(): void {
        $this->dav = $this->dispatcher('alice', 10);
        $built = $this->serversBuilt;
        try {
            $this->dav->put('alice', 'personal', 'c1.vcf', self::CARD);
            self::fail('an oversized card was sent');
        } catch (ToolFailure) {
            self::assertSame($built, $this->serversBuilt, 'refused before any DAV call');
            self::assertSame([], $this->backend->cards);
        }
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
        return new EmbeddedCardDavDispatcher(function (): Server {
            $this->serversBuilt++;
            return $this->server();
        }, new Session($session), $config, new NullLogger());
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
