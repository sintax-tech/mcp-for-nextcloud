<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\FileAccess\References;

use OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Platform\FileAccess\References\FileReferenceScope;
use OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** The structured-reference output rule: which references stay, which go, and that the source object never changes. */
final class OutputReferenceFilterTest extends TestCase {
    private const HIDDEN_TAG = '7';

    private FakeTree $tree;
    private InMemoryConfig $config;
    /** @var array<string, list<string>> tags by object id */
    private array $tags = [];
    /** @var array<string, IShare> shares by full id */
    private array $shares = [];
    /** @var array<string, IShare> public shares by token */
    private array $tokens = [];
    private VisibilityGuard $guard;
    private ?\OCA\Mcp\Access\Guests\GuestRestrictions $guests = null;
    private int $okId;
    private int $hiddenId;
    private int $inheritedId;
    private int $otherId;

    protected function setUp(): void {
        $this->tree = new FakeTree($this, '/alice/files');
        $this->okId = $this->tree->addFile('/alice/files/Docs/ok.pdf', 'ok');
        $this->hiddenId = $this->tree->addFile('/alice/files/Docs/direct.pdf', 'tagged');
        $secret = $this->tree->addFolder('/alice/files/Secret');
        $this->inheritedId = $this->tree->addFile('/alice/files/Secret/plan.pdf', 'inherited');
        $this->otherId = $this->tree->addFile('/alice/files/Other/out.pdf', 'out of scope');
        $this->tags = [(string)$this->hiddenId => [self::HIDDEN_TAG], (string)$secret => [self::HIDDEN_TAG]];
        $this->config = new InMemoryConfig();
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
            $result = [];
            foreach ($ids as $id) {
                $result[(string)$id] = $this->tags[(string)$id] ?? [];
            }
            return $result;
        });
        $this->guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot());
    }

    private function filter(?FileReferenceScope $scope = null, array $trusted = ['cloud.example.com', '*.cloud.example.net'], string $webroot = ''): OutputReferenceFilter {
        $shares = $this->createMock(IShareManager::class);
        $shares->method('getShareById')->willReturnCallback(fn (string $id) => $this->shares[$id] ?? throw new \OCP\Share\Exceptions\ShareNotFound());
        $shares->method('getShareByToken')->willReturnCallback(fn (string $token) => $this->tokens[$token] ?? throw new \OCP\Share\Exceptions\ShareNotFound());
        return OutputReferenceFilters::over($this, $this->tree->filesystemRoot(), $this->guard, $shares, $scope, $trusted, $webroot, $this->guests);
    }

    private function roomShare(int $id, int $nodeId, string $token = 'room1'): void {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
        $share->method('getSharedWith')->willReturn($token);
        $share->method('getNodeId')->willReturn($nodeId);
        $this->shares['ocRoomShare:' . $id] = $share;
    }

    /** A scope that keeps only /Docs and records the order the gates were asked in. */
    private function docsScope(array &$asked = []): FileReferenceScope {
        return new class ($asked) implements FileReferenceScope {
            public function __construct(private array &$asked) {}
            public function restricts(string $userId): bool {
                return true;
            }
            public function allows(string $userId, Node $node): bool {
                $this->asked[] = $node->getPath();
                return str_starts_with($node->getPath(), '/alice/files/Docs/');
            }
        };
    }

    private function publicLink(string $token, int $nodeId, mixed $type = 'file'): void {
        $share = $this->createMock(IShare::class);
        $share->method('getNodeId')->willReturn($nodeId);
        if ($type instanceof \Throwable) {
            $share->method('getNodeType')->willThrowException($type);
        } else {
            $share->method('getNodeType')->willReturn($type);
        }
        $this->tokens[$token] = $share;
    }

    private function calendar(string ...$attach): VCalendar {
        $lines = array_map(static fn (string $line) => 'ATTACH' . $line, $attach);
        return Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VTODO\r\nUID:t\r\nSUMMARY:S\r\n"
            . implode("\r\n", $lines) . ($lines === [] ? '' : "\r\n")
            . "BEGIN:VALARM\r\nACTION:AUDIO\r\nTRIGGER:-PT5M\r\nATTACH:" . $this->ids('https://cloud.example.com/f/HIDDEN') . "\r\nEND:VALARM\r\n"
            . "END:VTODO\r\nEND:VCALENDAR\r\n");
    }

    /** @return list<string> ATTACH values of the calendar and its nested components */
    private static function attaches(VCalendar $calendar): array {
        $values = [];
        $walk = static function ($component) use (&$walk, &$values): void {
            foreach ($component->select('ATTACH') as $attach) {
                $values[] = (string)$attach->getValue();
            }
            foreach ($component->getComponents() as $child) {
                $walk($child);
            }
        };
        $walk($calendar);
        return $values;
    }

    private function ids(string $url): string {
        return strtr($url, ['OK' => (string)$this->okId, 'HIDDEN' => (string)$this->hiddenId, 'INHERITED' => (string)$this->inheritedId, 'OTHER' => (string)$this->otherId]);
    }

    /** @return array<string, array{string, bool}> URL and whether it stays in the output */
    public static function urls(): array {
        return [
            'short link allowed' => ['https://cloud.example.com/f/OK', true],
            'short link tagged' => ['https://cloud.example.com/f/HIDDEN', false],
            'short link inherited' => ['https://cloud.example.com/index.php/f/INHERITED', false],
            'files app query allowed' => ['https://cloud.example.com/index.php/apps/files/?dir=/Docs&fileid=OK', true],
            'files app query tagged' => ['https://cloud.example.com/apps/files/?dir=/Docs&fileid=HIDDEN', false],
            'files app path route' => ['https://cloud.example.com/apps/files/files/HIDDEN?dir=/Docs&openfile=true', false],
            'dav path allowed' => ['https://cloud.example.com/remote.php/dav/files/alice/Docs/ok.pdf', true],
            'dav path inherited' => ['https://cloud.example.com/remote.php/dav/files/alice/Secret/plan.pdf', false],
            'dav path encoded' => ['https://cloud.example.com/remote.php/dav/files/alice/Secret/plan%2Epdf', false],
            'dav path of another user' => ['https://cloud.example.com/remote.php/dav/files/bruno/Docs/ok.pdf', false],
            'dav traversal' => ['https://cloud.example.com/remote.php/dav/files/alice/Docs/../Secret/plan.pdf', false],
            'legacy webdav' => ['https://cloud.example.com/remote.php/webdav/Secret/plan.pdf', false],
            'relative short link' => ['/f/HIDDEN', false],
            'relative allowed' => ['/index.php/f/OK', true],
            'host-relative' => ['//cloud.example.com/f/HIDDEN', false],
            'wildcard trusted domain' => ['https://node1.cloud.example.net/f/HIDDEN', false],
            'host case and port' => ['https://CLOUD.example.com:443/f/HIDDEN', false],
            'host trailing dot' => ['https://cloud.example.com./f/HIDDEN', false],
            'host escaped letter' => ['https://%63loud.example.com/f/HIDDEN', false],
            'local missing id' => ['https://cloud.example.com/f/987654', false],
            'local unsupported shape' => ['https://cloud.example.com/apps/deck/board/1', false],
            'local non-numeric fileid' => ['https://cloud.example.com/apps/files/?fileid=OK,HIDDEN', false],
            'path and query both named' => ['https://cloud.example.com/apps/files/files/OK?fileid=HIDDEN', false],
            'local webdav scheme' => ['webdav://cloud.example.com/f/OK', false],
            'files app dir hidden' => ['https://cloud.example.com/apps/files/?dir=/Secret', false],
            'files app dir allowed' => ['https://cloud.example.com/apps/files/?dir=/Docs', true],
            'files app dir hidden beside allowed fileid' => ['https://cloud.example.com/apps/files/?dir=/Secret&fileid=OK', false],
            'loopback name' => ['http://localhost/remote.php/dav/files/alice/Secret/plan.pdf', false],
            'loopback ipv4' => ['http://127.0.0.1/f/HIDDEN', false],
            'loopback ipv4 range' => ['http://127.0.1.1:8080/index.php/f/HIDDEN', false],
            'loopback ipv6' => ['http://[::1]/apps/files/?fileid=INHERITED', false],
            'loopback unsupported shape' => ['http://localhost/apps/deck/board/1', false],
            'loopback missing id' => ['http://127.0.0.1/f/987654', false],
            'ip alias tagged' => ['http://192.168.0.10/f/HIDDEN', false],
            'ip alias allowed' => ['http://192.168.0.10/f/OK', true],
            'alias under another web root' => ['https://alias.example.net/nextcloud/index.php/apps/files/?fileid=HIDDEN', false],
            'alias dav under another web root' => ['https://alias.example.net/cloud/remote.php/dav/files/alice/Secret/plan.pdf', false],
            'alias files app dir hidden' => ['https://alias.example.net/apps/files/?dir=/Secret', false],
            'alias allowed' => ['https://alias.example.net/nextcloud/f/OK', true],
            'external same path' => ['https://files.example.org/f/HIDDEN', false],
            'external dav' => ['https://files.example.org/remote.php/dav/files/alice/Secret/plan.pdf', false],
            'external shape of a node the user lacks' => ['https://files.example.org/f/987654', true],
            'external dav of another user' => ['https://files.example.org/remote.php/dav/files/bruno/x.pdf', true],
            'external unknown token' => ['https://files.example.org/s/unknownToken', true],
            'external without shape' => ['https://files.example.org/wiki/Secret/plan.pdf', true],
            'code browser tree' => ['https://github.com/nextcloud/server/tree/master/apps/files', true],
            'code browser blob' => ['https://github.com/nextcloud/server/blob/master/apps/files/lib/App.php', true],
            'external path inside unknown share' => ['https://example.org/s/abc?path=/x', true],
            'external path inside a share of this server' => ['https://example.org/s/okToken?path=/x', false],
            'external non-numeric fileid' => ['https://example.org/apps/files/?fileid=abc', true],
            'external files app root' => ['https://other.example/index.php/apps/files/', true],
            'external dir list' => ['https://other.example/apps/files/?dir[]=x', true],
            'external non-numeric fileid beside hidden dir' => ['https://other.example/apps/files/?dir=/Secret&fileid=abc', false],
            'external missing short link' => ['https://blog.example/f/987654', true],
            'local dir list' => ['https://cloud.example.com/apps/files/?dir[]=x', false],
            'local files app root' => ['https://cloud.example.com/apps/files/', false],
            'mail reference' => ['mailto:someone@example.org', true],
            'content id' => ['cid:part1@example.org', true],
        ];
    }

    #[DataProvider('urls')]
    public function testAttachIsKeptOnlyWhenEveryNamedNodeIsAllowed(string $url, bool $kept): void {
        $this->publicLink('okToken', $this->okId);
        $url = $this->ids($url);
        $original = $this->calendar(':' . $url);
        $before = $original->serialize();

        $output = $this->filter()->outputCalendar('alice', $original);

        self::assertSame($kept ? [$url] : [], self::attaches($output), $url);
        self::assertSame($before, $original->serialize(), 'the object written to DAV never changes');
        self::assertSame([$url, $this->ids('https://cloud.example.com/f/HIDDEN')], self::attaches($original));
    }

    public function testNestedAlarmAttachIsCheckedAndInlineContentStays(): void {
        $original = $this->calendar(';ENCODING=BASE64;VALUE=BINARY;FMTTYPE=text/plain:aGVsbG8=');

        $output = $this->filter()->outputCalendar('alice', $original);

        self::assertSame(['hello'], self::attaches($output), 'inline data is content, the alarm reference to a tagged file goes');
    }

    public function testWebrootPrefixIsStripped(): void {
        $kept = $this->ids('https://cloud.example.com/nextcloud/index.php/f/OK');
        $gone = $this->ids('https://cloud.example.com/nextcloud/f/HIDDEN');

        $output = $this->filter(null, ['cloud.example.com'], '/nextcloud')->outputCalendar('alice', $this->calendar(':' . $kept, ':' . $gone));

        self::assertSame([$kept], self::attaches($output));
    }

    public function testPublicLinkResolvesThroughItsShareWithoutFollowingIt(): void {
        $this->publicLink('okToken', $this->okId);
        $this->publicLink('secretToken', $this->inheritedId);
        $kept = 'https://cloud.example.com/s/okToken';
        $output = $this->filter()->outputCalendar('alice', $this->calendar(
            ':' . $kept,
            ':https://cloud.example.com/index.php/s/secretToken/download',
            ':https://cloud.example.com/s/unknown',
            ':https://cloud.example.com/s/okToken/download?path=/sub&files=x.pdf',
        ));

        self::assertSame([$kept], self::attaches($output));
    }

    public function testPublicFolderLinkIsRemovedWhileAnExclusionIsConfigured(): void {
        $clean = $this->tree->addFolder('/alice/files/Docs/Clean');
        $this->publicLink('folderToken', $clean, 'folder');
        $this->publicLink('unknownType', $this->okId, new \OCP\Files\NotFoundException());
        $this->publicLink('okToken', $this->okId);
        $kept = 'https://cloud.example.com/s/okToken';

        $output = $this->filter()->outputCalendar('alice', $this->calendar(
            ':https://cloud.example.com/s/folderToken',
            ':https://cloud.example.com/s/unknownType',
            ':' . $kept,
            ':https://cloud.example.com/f/' . $clean,
        ));

        // The link is a capability over the subtree; the short link of the same folder is not.
        self::assertSame([$kept, 'https://cloud.example.com/f/' . $clean], self::attaches($output));
    }

    public function testShareWhoseTypeCannotBeReadIsRemoved(): void {
        $this->publicLink('unknownType', $this->okId, new \OCP\Files\NotFoundException());
        $this->publicLink('okToken', $this->okId);
        $kept = 'https://cloud.example.com/s/okToken';

        $output = $this->filter()->outputCalendar('alice', $this->calendar(':https://cloud.example.com/s/unknownType', ':' . $kept));

        self::assertSame([$kept], self::attaches($output), 'a share of an allowed file whose type cannot be read is not shown');
    }

    public function testPublicFolderLinkStaysWithoutAnExclusionTag(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $clean = $this->tree->addFolder('/alice/files/Docs/Clean');
        $this->publicLink('folderToken', $clean, 'folder');
        $url = 'https://cloud.example.com/s/folderToken';

        $output = $this->filter($this->docsScope())->outputCalendar('alice', $this->calendar(':' . $url));

        self::assertSame($url, self::attaches($output)[0], 'only the exclusion makes a folder link a leak');
    }

    public function testCalendarAttachParametersAreCheckedAndAnUncheckableFilenameIsDropped(): void {
        $this->publicLink('okToken', $this->okId);
        $link = 'https://cloud.example.com/s/okToken';
        $calendarApp = ';FMTTYPE=application/pdf;FILENAME=/Docs/ok.pdf;X-NC-FILE-ID=' . $this->okId . ';X-NC-HAS-PREVIEW=false;X-NC-SHARED-TYPES=3:';
        $original = $this->calendar(
            $calendarApp . $link,
            ';X-NC-FILE-ID=' . $this->hiddenId . ';FILENAME=/Docs/direct.pdf:http://localhost/f/' . $this->hiddenId,
            ';X-NC-FILE-ID=' . $this->hiddenId . ':https://files.example.org/doc',
            ';X-NC-FILE-ID=' . $this->inheritedId . ':' . $link,
            ';X-NC-FILE-ID=987654:https://files.example.org/missing',
            ';X-NC-FILE-ID=12a:https://files.example.org/malformed',
            ';FILENAME=/Secret/plan.pdf:https://files.example.org/named',
            ';FILENAME=/Gone/old.pdf;FMTTYPE=application/pdf:https://files.example.org/stale',
            ';X-NC-FILE-ID=' . $this->hiddenId . ';ENCODING=BASE64;VALUE=BINARY:aGVsbG8=',
        );
        $before = $original->serialize();

        $output = $this->filter()->outputCalendar('alice', $original);

        $kept = array_values($output->VTODO->select('ATTACH'));
        self::assertSame([$link, 'https://files.example.org/stale'], array_map(static fn ($a) => (string)$a->getValue(), $kept));
        self::assertSame('/Docs/ok.pdf', (string)$kept[0]['FILENAME'], 'an allowed name stays as the Calendar app wrote it');
        self::assertSame((string)$this->okId, (string)$kept[0]['X-NC-FILE-ID']);
        self::assertFalse(isset($kept[1]['FILENAME']), 'a name the tree does not have cannot be checked');
        self::assertSame('application/pdf', (string)$kept[1]['FMTTYPE']);
        self::assertSame($before, $original->serialize(), 'the object written to DAV never changes');
    }

    public function testEveryAliasOfAnIdMustPassNotJustOne(): void {
        $this->tree->addFile('/alice/files/Docs/shared.pdf', 'visible alias');
        $this->tree->addFile('/alice/files/Secret/shared.pdf', 'alias under an excluded folder');
        $id = $this->tree->nodes['/alice/files/Docs/shared.pdf']['id'];
        $this->tree->nodes['/alice/files/Secret/shared.pdf']['id'] = $id;
        $this->roomShare(30, $id);
        $url = 'https://cloud.example.com/f/' . $id;

        self::assertSame([], self::attaches($this->filter()->outputCalendar('alice', $this->calendar(':' . $url))));
        self::assertNull($this->filter()->attachmentId('alice', 'room1', 30));

        $this->tree->nodes['/alice/files/Secret/shared.pdf']['id'] = $id + 1000;
        self::assertSame([$url], self::attaches($this->filter()->outputCalendar('alice', $this->calendar(':' . $url))), 'the visible alias alone passes');
    }

    public function testUnreadableFileIsRemovedLikeAMissingOne(): void {
        $this->tree->nodes['/alice/files/Docs/ok.pdf']['readable'] = false;
        $url = $this->ids('https://cloud.example.com/f/OK');

        self::assertSame([], self::attaches($this->filter()->outputCalendar('alice', $this->calendar(':' . $url))));
    }

    public function testScopeIsAskedFirstAndAFileOnlyOutsideItIsRemoved(): void {
        $asked = [];
        $ok = $this->ids('https://cloud.example.com/f/OK');
        $out = $this->ids('https://cloud.example.com/f/OTHER');

        $output = $this->filter($this->docsScope($asked))->outputCalendar('alice', $this->calendar(':' . $ok, ':' . $out));

        self::assertSame([$ok], self::attaches($output));
        self::assertContains('/alice/files/Other/out.pdf', $asked, 'the untagged file outside the scope reached the scope step');
    }

    public function testScopeRefusalStopsBeforeTheExclusionCheck(): void {
        $this->tags = [];
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->expects(self::never())->method('getTagIdsForObjects');
        $this->guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot());
        $refuseAll = new class implements FileReferenceScope {
            public function restricts(string $userId): bool {
                return true;
            }
            public function allows(string $userId, Node $node): bool {
                return false;
            }
        };

        $output = $this->filter($refuseAll)->outputCalendar('alice', $this->calendar(':' . $this->ids('https://cloud.example.com/f/OK')));

        self::assertSame([], self::attaches($output));
    }

    public function testWithoutExclusionAndScopeTheObjectIsReturnedAsIsWithoutResolvingAnything(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->expects(self::never())->method('getUserFolder');
        $shares = $this->createMock(IShareManager::class);
        $shares->expects(self::never())->method('getShareById');
        $guard = new VisibilityGuard($this->config->mock($this), $this->createMock(ISystemTagObjectMapper::class));
        $filter = OutputReferenceFilters::over($this, $root, $guard, $shares);
        $calendar = $this->calendar(':https://cloud.example.com/f/1');

        self::assertSame($calendar, $filter->outputCalendar('alice', $calendar));
        self::assertSame(55, $filter->attachmentId('alice', 'room1', 55));
    }

    public function testInvalidExclusionConfigurationRemovesEveryLocalReference(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '{"broken":';
        $external = 'https://files.example.org/f/1';

        $output = $this->filter()->outputCalendar('alice', $this->calendar(':' . $this->ids('https://cloud.example.com/f/OK'), ':' . $external));

        self::assertSame([$external], self::attaches($output));
    }

    public function testGuestsHideExternalReferencesWithoutTagsOrScopeAndPreserveSourceCalendar(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $this->tree->nodes['/alice/files/Docs/ok.pdf']['scope'] = 'external';
        $this->tree->nodes['/alice/files/Docs/direct.pdf']['scope'] = 'external';
        $this->guests = $this->getMockBuilder(\OCA\Mcp\Access\Guests\GuestRestrictions::class)
            ->disableOriginalConstructor()->onlyMethods(['hidesExternalStorage'])->getMock();
        $this->guests->method('hidesExternalStorage')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');
        $this->roomShare(11, $this->okId);
        $url = $this->ids('https://cloud.example.com/f/OK');
        $calendar = $this->calendar(':' . $url);
        $before = $calendar->serialize();
        $filter = $this->filter();
        self::assertTrue($filter->active('alice'));
        self::assertFalse($filter->active('bob'));
        self::assertNull($filter->attachmentId('alice', 'room1', 11));
        self::assertSame([], self::attaches($filter->outputCalendar('alice', $calendar)));
        self::assertSame($before, $calendar->serialize());
        $this->tree->nodes['/alice/files/Docs/ok.pdf']['scope'] = 'personal';
        self::assertSame(11, $filter->attachmentId('alice', 'room1', 11));
        self::assertSame([$url], self::attaches($filter->outputCalendar('alice', $calendar)));
    }

    public function testNothingIsEverFetched(): void {
        $parameters = (new \ReflectionClass(OutputReferenceFilter::class))->getConstructor()->getParameters();
        $types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), $parameters);

        self::assertNotContains(\OCP\Http\Client\IClientService::class, $types);
        foreach ($types as $type) {
            self::assertStringNotContainsStringIgnoringCase('http', $type, 'the filter holds no HTTP client');
        }
    }

    public function testAttachmentIdPassesOnlyForAnAllowedFileOfTheSameConversation(): void {
        $this->roomShare(11, $this->okId);
        $this->roomShare(12, $this->inheritedId);
        $this->roomShare(13, $this->hiddenId);
        $this->roomShare(14, $this->okId, 'other-room');
        $this->roomShare(15, 987654);
        $filter = $this->filter();

        self::assertSame(11, $filter->attachmentId('alice', 'room1', 11));
        foreach ([12, 13, 14, 15, 16] as $id) {
            self::assertNull($filter->attachmentId('alice', 'room1', $id), "share $id");
        }
        self::assertNull($filter->attachmentId('alice', 'room1', null));
    }

    public function testAttachmentIdOfAFileOnlyOutsideTheScopeIsNull(): void {
        $this->tags = [];
        $this->roomShare(11, $this->okId);
        $this->roomShare(17, $this->otherId);
        $filter = $this->filter($this->docsScope());

        self::assertSame(11, $filter->attachmentId('alice', 'room1', 11));
        self::assertNull($filter->attachmentId('alice', 'room1', 17));
    }

    /** The write-side checks of quote and attach run through the same gates, the Files scope step included. */
    public function testWriteChecksFollowTheScopeAndTheExclusionInTheCallersTree(): void {
        $this->roomShare(11, $this->okId);
        $this->roomShare(13, $this->hiddenId);
        $filter = $this->filter();
        self::assertTrue($filter->allowsRoomShare('alice', $this->shares['ocRoomShare:11']));
        self::assertFalse($filter->allowsRoomShare('alice', $this->shares['ocRoomShare:13']));
        self::assertTrue($filter->allowsUserNode('alice', $this->tree->node('/alice/files/Docs/ok.pdf')));
        self::assertFalse($filter->allowsUserNode('alice', $this->tree->node('/alice/files/Secret/plan.pdf')));

        $this->tags = [];
        $this->roomShare(17, $this->otherId);
        $scoped = $this->filter($this->docsScope());
        self::assertFalse($scoped->allowsRoomShare('alice', $this->shares['ocRoomShare:17']));
        self::assertFalse($scoped->allowsUserNode('alice', $this->tree->node('/alice/files/Other/out.pdf')));
        self::assertTrue($scoped->allowsUserNode('alice', $this->tree->node('/alice/files/Docs/ok.pdf')));
    }

    public function testAttachmentOfANonRoomShareIsNull(): void {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn(IShare::TYPE_LINK);
        $share->method('getSharedWith')->willReturn('room1');
        $share->method('getNodeId')->willReturn($this->okId);
        $this->shares['ocRoomShare:20'] = $share;

        self::assertNull($this->filter()->attachmentId('alice', 'room1', 20));
    }

    /**
     * C1 of the second B5 review: the scope is read whatever exclusion is configured, so an unreadable scope is
     * unavailability (503) at every entry of the filter, never masked by a configured tag.
     */
    public function testAnUnreadableScopeIsUnavailableEvenWithATagConfigured(): void {
        $unreadable = new class implements FileReferenceScope {
            public function restricts(string $userId): bool {
                throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('Resource scope unavailable');
            }
            public function allows(string $userId, Node $node): bool {
                throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('Resource scope unavailable');
            }
        };
        $filter = $this->filter($unreadable);
        $this->assertNotSame([], $this->guard->getHiddenTagIds(), 'a tag is configured');
        foreach (['active' => fn () => $filter->active('alice'), 'assertAvailable' => fn () => $filter->assertAvailable('alice'),
            'node' => fn () => $filter->allowsUserNode('alice', $this->tree->node('/alice/files/Docs/ok.pdf'))] as $label => $run) {
            try {
                $run();
                $this->fail("$label answered");
            } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
