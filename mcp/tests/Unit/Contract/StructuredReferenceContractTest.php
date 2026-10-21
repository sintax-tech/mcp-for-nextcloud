<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use DateTimeImmutable;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;
use OCA\Mcp\Platform\FileAccess\References\FileReferenceScope;
use OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Tasks\Reading\TaskQuery as TaskStore;
use OCA\Mcp\Modules\Tasks\TasksModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use OCA\Mcp\Access\ResourceScope\FilesReferenceScope;
use OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * V9 and V10: structured references to Files nodes in Talk messages and task calendars, for a file excluded by tag
 * and for a file only outside the user's Files scope, the real one of B5.
 * The source DAV object is never what is sanitized.
 */
final class StructuredReferenceContractTest extends TestCase {
    private const HIDDEN_TAG = '999';
    private const PATH = '/remote.php/dav/calendars/alice/tasks/';
    private const HOST = 'https://cloud.example.com';
    private const EXTERNAL = 'https://files.example.org/f/1';

    private FakeTree $tree;
    private InMemoryConfig $config;
    /** @var array<string, list<string>> */
    private array $tags = [];
    /** @var array<string, IShare> */
    private array $shares = [];
    /** @var array<string, IShare> public shares by token */
    private array $tokens = [];
    private VisibilityGuard $guard;
    private ?FileReferenceScope $scope = null;
    private int $okId;
    private int $taggedId;
    private int $inheritedId;
    private int $outsideId;

    /** Raw DAV object of the task, as the server stores it. */
    private string $data = '';
    private CalendarDav $dav;

    protected function setUp(): void {
        $this->tree = new FakeTree($this, '/alice/files');
        $this->okId = $this->tree->addFile('/alice/files/Docs/ok.pdf', 'ok');
        $this->taggedId = $this->tree->addFile('/alice/files/Docs/tagged.pdf', 'tagged');
        $secret = $this->tree->addFolder('/alice/files/Secret');
        $this->inheritedId = $this->tree->addFile('/alice/files/Secret/plan.pdf', 'inherited');
        $this->outsideId = $this->tree->addFile('/alice/files/Other/out.pdf', 'outside scope, untagged');
        $this->tags = [(string)$this->taggedId => [self::HIDDEN_TAG], (string)$secret => [self::HIDDEN_TAG]];
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
        $this->data = $this->taskObject();
    }

    private function filter(): OutputReferenceFilter {
        $shares = $this->createMock(IShareManager::class);
        $shares->method('getShareById')->willReturnCallback(fn (string $id) => $this->shares[$id] ?? throw new \OCP\Share\Exceptions\ShareNotFound());
        $shares->method('getShareByToken')->willReturnCallback(fn (string $token) => $this->tokens[$token] ?? throw new \OCP\Share\Exceptions\ShareNotFound());
        return OutputReferenceFilters::over($this, $this->tree->filesystemRoot(), $this->guard, $shares, $this->scope);
    }

    /** The real Files scope of alice: /Docs and /Secret are selected, /Other is not. */
    private function useScope(): void {
        $selected = [(string)$this->tree->nodes['/alice/files/Docs']['id'], (string)$this->tree->nodes['/alice/files/Secret']['id']];
        $this->scope = new FilesReferenceScope(FilesScopes::over($this, $this->guard, $this->tree->filesystemRoot(), static fn (): array => $selected));
    }

    // V9 — Talk

    private function roomShare(int $id, int $nodeId): void {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
        $share->method('getSharedWith')->willReturn('room1');
        $share->method('getNodeId')->willReturn($nodeId);
        $this->shares['ocRoomShare:' . $id] = $share;
    }

    private function comment(string $id, string $verb, string $message): IComment {
        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn($id);
        $comment->method('getVerb')->willReturn($verb);
        $comment->method('getMessage')->willReturn($message);
        $comment->method('getActorType')->willReturn('users');
        $comment->method('getActorId')->willReturn('bruno');
        $comment->method('getParentId')->willReturn('0');
        $comment->method('getCreationDateTime')->willReturn(new DateTimeImmutable('@1700000000'));
        return $comment;
    }

    private static function envelope(int $share): string {
        return (string)json_encode(['message' => 'file_shared', 'parameters' => ['share' => (string)$share, 'metaData' => ['caption' => 'see plan.pdf']]]);
    }

    /** @param list<IComment> $history */
    private function read(array $history): array {
        $room = new StructuredReferenceRoomStub('room1');
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForReading')->willReturn(new Conversation($room, new \stdClass()));
        $chat = $this->createMock(StructuredReferenceChat::class);
        $chat->method('getHistory')->willReturn($history);
        $users = $this->createMock(IUserManager::class);
        return (new ConversationReader(static fn () => throw new \LogicException('Unused manager'), static fn () => throw new \LogicException('Unused participants'), static fn () => $chat, $resolver, new ActorNames($users), $this->filter()))->readMessages('alice', 'room1', 50);
    }

    public function testV9ReadMessagesWithholdsTheIdOfAnExcludedFileAndKeepsTheText(): void {
        $this->roomShare(11, $this->okId);
        $this->roomShare(12, $this->taggedId);
        $this->roomShare(13, $this->inheritedId);
        $text = 'plan.pdf is in Secret, attachment 12';

        $messages = $this->read([
            $this->comment('1', 'comment', $text),
            $this->comment('2', 'object_shared', self::envelope(11)),
            $this->comment('3', 'object_shared', self::envelope(12)),
            $this->comment('4', 'object_shared', self::envelope(13)),
            $this->comment('5', 'object_shared', self::envelope(404)),
        ]);

        self::assertSame([null, 11, null, null, null], array_column($messages, 'attachmentId'));
        self::assertSame($text, $messages[0]['text'], 'free text is never redacted');
        self::assertSame(['comment', 'file_shared', 'file_shared', 'file_shared', 'file_shared'], array_column($messages, 'type'));
        // Excluded and missing are the same message, apart from the comment id.
        unset($messages[2]['id'], $messages[4]['id']);
        self::assertSame($messages[4], $messages[2]);
        $json = (string)json_encode($messages);
        self::assertStringNotContainsString('tagged.pdf', $json);
        self::assertStringNotContainsString((string)$this->taggedId, $json);
    }

    public function testV9ReadMessagesWithholdsTheIdOfAFileOnlyOutsideTheScope(): void {
        $this->tags = [];
        $this->useScope();
        $this->roomShare(11, $this->okId);
        $this->roomShare(17, $this->outsideId);

        $messages = $this->read([
            $this->comment('2', 'object_shared', self::envelope(11)),
            $this->comment('3', 'object_shared', self::envelope(17)),
        ]);

        self::assertSame([11, null], array_column($messages, 'attachmentId'));
    }

    public function testV9WithoutExclusionOrScopeTheIdIsReportedAsBefore(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';

        $messages = $this->read([$this->comment('3', 'object_shared', self::envelope(404))]);

        self::assertSame(404, $messages[0]['attachmentId']);
    }

    // V10 — Tasks

    /** @return list<string> the ATTACH lines of the stored task: allowed, tagged, inherited, outside scope, external, unresolvable */
    private function attachValues(): array {
        return [
            'ok' => self::HOST . '/f/' . $this->okId,
            'tagged' => self::HOST . '/index.php/apps/files/?dir=/Docs&fileid=' . $this->taggedId,
            'inherited' => self::HOST . '/remote.php/dav/files/alice/Secret/plan.pdf',
            'outside' => self::HOST . '/f/' . $this->outsideId,
            'external' => self::EXTERNAL,
            'unresolvable' => self::HOST . '/apps/whatever/42',
        ];
    }

    private function taskObject(): string {
        $lines = '';
        foreach ($this->attachValues() as $value) {
            $lines .= 'ATTACH;FMTTYPE=application/pdf:' . $value . "\r\n";
        }
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20261001T100000Z\r\n"
            . "SUMMARY:Work\r\nSTATUS:NEEDS-ACTION\r\n" . $lines . "END:VTODO\r\nEND:VCALENDAR\r\n";
    }

    private function module(): TasksModule {
        $store = $this->createMockForIntersectionOfInterfaces([CalendarStore::class, \OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class]);
        $store->method('calendarsForPrincipal')->willReturn([[
            'id' => 1, 'uri' => 'tasks', 'displayName' => 'Tasks', 'ownerPrincipal' => 'principals/users/alice',
            'readOnly' => false, 'components' => ['VTODO'], 'deleted' => false,
        ]]);
        $row = fn () => ['id' => 1, 'uri' => 't1.ics', 'etag' => '"' . md5($this->data) . '"', 'data' => $this->data, 'deleted' => false];
        $store->method('objectByUid')->willReturnCallback($row);
        $store->method('object')->willReturnCallback($row);
        $store->method('objects')->willReturnCallback(fn () => [$row()]);
        $tasks = $this->createMock(TaskStore::class);
        $tasks->method('uris')->willReturn(['t1.ics']);
        $this->dav = $this->createMock(CalendarDav::class);
        $this->dav->method('update')->willReturnCallback(function ($user, $calendar, $uri, $etag, $data) {
            $this->data = $data;
            return new DavResult(204);
        });
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        return \OCA\Mcp\Tests\Support\Tasks\TasksModuleFactory::create(
            ScopeStub::access($store),
            $store,
            $tasks,
            $this->dav,
            new SharedGuard($this->createMock(IUserManager::class)),
            new TrashPolicy($config),
            $time,
            $this->filter(),
        );
    }

    /** @return list<string> ATTACH values of a serialized calendar */
    private static function attaches(string $icalendar): array {
        return array_map(static fn ($attach) => (string)$attach->getValue(), Reader::read($icalendar)->VTODO->select('ATTACH'));
    }

    private static function json(array $result): array {
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> what the output may show for the current gates */
    private function shown(string ...$keys): array {
        return array_values(array_intersect_key($this->attachValues(), array_flip($keys)));
    }

    /** @return array<string, array{bool}> */
    public static function gates(): array {
        return ['excluded by tag' => [false], 'only outside the scope' => [true]];
    }

    private function applyGate(bool $scopeOnly): void {
        if ($scopeOnly) {
            $this->tags = [];
            $this->useScope();
        }
    }

    /** @return list<string> the ATTACH the gate keeps: the allowed file, the external URL and, for the scope gate, the untagged files inside it */
    private function expected(bool $scopeOnly): array {
        return $scopeOnly ? $this->shown('ok', 'tagged', 'inherited', 'external') : $this->shown('ok', 'outside', 'external');
    }

    #[DataProvider('gates')]
    public function testV10ReadTaskShowsOnlyAllowedAndExternalAttach(bool $scopeOnly): void {
        $this->applyGate($scopeOnly);

        $task = self::json($this->module()->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice'));

        self::assertSame($this->expected($scopeOnly), self::attaches($task['icalendar']));
    }

    public function testV10ListTasksCarriesNoRawCalendarAndNoAttach(): void {
        $list = $this->module()->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice');

        $json = $list['content'][0]['text'];
        self::assertArrayNotHasKey('icalendar', self::json($list)['tasks'][0]);
        self::assertStringNotContainsString('ATTACH', $json);
        self::assertStringNotContainsString('cloud.example.com', $json);
    }

    /** @return array<string, array{string, array<string, mixed>, bool}> */
    public static function writes(): array {
        $cases = [];
        foreach (self::gates() as $gate => [$scopeOnly]) {
            $cases["edit, $gate"] = ['tasks_edit_task', ['summary' => 'Renamed'], $scopeOnly];
            $cases["complete, $gate"] = ['tasks_complete_task', [], $scopeOnly];
            $cases["delete, $gate"] = ['tasks_delete_task', [], $scopeOnly];
        }
        return $cases;
    }

    #[DataProvider('writes')]
    public function testV10PlanWithoutConfirmShowsTheSameSanitizedAttachOnBothSides(string $tool, array $fields, bool $scopeOnly): void {
        $this->applyGate($scopeOnly);
        $module = $this->module();
        $this->dav->expects(self::never())->method('update');
        $this->dav->expects(self::never())->method('delete');

        $plan = self::json($module->call($tool, ['calendar' => self::PATH, 'uid' => 't1'] + $fields, 'alice'));

        self::assertTrue($plan['requiresConfirmation']);
        self::assertSame($this->expected($scopeOnly), self::attaches($plan['before']['icalendar']));
        if ($tool !== 'tasks_delete_task') {
            // The diff never shows the removal as a change: both sides lose the same ATTACH.
            self::assertSame(self::attaches($plan['before']['icalendar']), self::attaches($plan['after']['icalendar']));
        }
        self::assertSame($this->expected($scopeOnly), self::attaches($module->preview($tool, ['calendar' => self::PATH, 'uid' => 't1'] + $fields, 'alice')['before']['icalendar']));
    }

    #[DataProvider('gates')]
    public function testV10EditingAnotherFieldPreservesEveryAttachInTheDavObject(bool $scopeOnly): void {
        $this->applyGate($scopeOnly);
        $stored = self::attaches($this->data);

        $result = self::json($this->module()->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Renamed', 'confirm' => true], 'alice'));

        // Read the DAV object directly after the write: nothing the output hid was dropped.
        self::assertSame($stored, self::attaches($this->data));
        self::assertSame('Renamed', (string)Reader::read($this->data)->VTODO->SUMMARY);
        self::assertSame($this->expected($scopeOnly), self::attaches($result['icalendar']), 'the confirmed result is sanitized too');
    }

    public function testV10CompletingPreservesEveryAttachInTheDavObject(): void {
        $stored = self::attaches($this->data);

        $result = self::json($this->module()->call('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1', 'confirm' => true], 'alice'));

        self::assertSame($stored, self::attaches($this->data));
        self::assertSame($this->expected(false), self::attaches($result['icalendar']));
    }

    /**
     * C1 of the second B5 review, for Tasks: with the Files scope unreadable, every task tool and plan answers the same
     * 503 for a task that exists and one that does not, with or without exclusion tags, before any task is read.
     */
    #[DataProvider('gates')]
    public function testC1AnUnreadableScopeIsTheSame503ForEveryTaskTool(bool $tagsOff): void {
        if ($tagsOff) {
            $this->tags = [];
            $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        }
        $this->scope = new FilesReferenceScope(FilesScopes::over($this, $this->guard, $this->tree->filesystemRoot(), static fn (): bool => false));
        $module = $this->module();
        $this->dav->expects(self::never())->method('update');
        $this->dav->expects(self::never())->method('delete');
        foreach (['t1', 'missing'] as $uid) {
            foreach ([fn () => $module->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => $uid], 'alice'),
                fn () => $module->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice'),
                fn () => $module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => $uid, 'summary' => 'x'], 'alice'),
                fn () => $module->preview('tasks_complete_task', ['calendar' => self::PATH, 'uid' => $uid], 'alice'),
                // A list that does not exist either: the scope is read before the calendar is resolved.
                fn () => $module->preview('tasks_complete_task', ['calendar' => '/remote.php/dav/calendars/alice/nope/', 'uid' => $uid], 'alice'),
                fn () => $module->call('tasks_read_task', ['calendar' => '/remote.php/dav/calendars/alice/nope/', 'uid' => $uid], 'alice')] as $i => $run) {
                try {
                    $run();
                    self::fail("task tool $i answered for $uid");
                } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    /** @return list<string> ATTACH values tasks_read_task shows for a stored task carrying the given ATTACH lines */
    private function readAttach(string ...$lines): array {
        $this->data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20261001T100000Z\r\n"
            . "SUMMARY:Work\r\n" . implode('', array_map(static fn (string $line) => $line . "\r\n", $lines)) . "END:VTODO\r\nEND:VCALENDAR\r\n";
        $task = self::json($this->module()->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice'));
        return self::attaches($task['icalendar']);
    }

    private function publicLink(string $token, int $nodeId, string $type): string {
        $share = $this->createMock(IShare::class);
        $share->method('getNodeId')->willReturn($nodeId);
        $share->method('getNodeType')->willReturn($type);
        $this->tokens[$token] = $share;
        return self::HOST . '/s/' . $token;
    }

    /** @return array<string, array{string, list<string>}> exclusion state and the links that stay */
    public static function folderLinkStates(): array {
        return [
            'exclusion tag configured' => ['tag', ['file']],
            // An unreadable configuration removes every local reference, the file link included.
            'unreadable exclusion configuration' => ['invalid', []],
            'no tag, scope only' => ['scope', ['folder', 'file']],
        ];
    }

    #[DataProvider('folderLinkStates')]
    public function testA6PublicFolderLinkIsRemovedWhileAnExclusionIsConfigured(string $state, array $kept): void {
        $clean = $this->tree->addFolder('/alice/files/Docs/Clean');
        match ($state) {
            'invalid' => $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '{"broken":',
            'scope' => [$this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]', $this->useScope()],
            default => null,
        };
        $links = ['folder' => $this->publicLink('folderToken', $clean, 'folder'), 'file' => $this->publicLink('fileToken', $this->okId, 'file')];

        $shown = $this->readAttach('ATTACH:' . $links['folder'], 'ATTACH:' . $links['file']);

        // A folder link is an anonymous capability over the whole subtree, whatever its root shows.
        self::assertSame(array_values(array_intersect_key($links, array_flip($kept))), $shown);
        self::assertSame(array_values($links), self::attaches($this->data), 'the DAV object keeps both');
    }

    public function testA6NextcloudShapesAreCheckedOnLoopbackIpAndAliasHosts(): void {
        $hidden = [
            'http://localhost/remote.php/dav/files/alice/Secret/plan.pdf',
            'http://127.0.0.1/f/' . $this->taggedId,
            'http://[::1]:8080/index.php/apps/files/?fileid=' . $this->inheritedId,
            'http://192.168.0.10/f/' . $this->taggedId,
            'https://alias.example.net/nextcloud/index.php/f/' . $this->inheritedId,
            'https://alias.example.net/cloud/remote.php/webdav/Secret/plan.pdf',
            'http://localhost/apps/whatever/42',
        ];
        $kept = [
            'http://192.168.0.10/f/' . $this->okId,
            'https://alias.example.net/nextcloud/apps/files/files/' . $this->okId,
            'https://other.example.org/wiki/page',
        ];

        $shown = $this->readAttach(...array_map(static fn (string $url) => 'ATTACH:' . $url, [...$hidden, ...$kept]));

        self::assertSame($kept, $shown);
    }

    public function testA6CalendarAppParametersAreCheckedOnAKeptAttach(): void {
        $okLink = $this->publicLink('okToken', $this->okId, 'file');
        $shown = $this->readAttach(
            // As the Calendar app writes it, for an allowed file.
            'ATTACH;FMTTYPE=application/pdf;FILENAME=/Docs/ok.pdf;X-NC-FILE-ID=' . $this->okId . ';X-NC-HAS-PREVIEW=false:' . $okLink,
            // Value classified as external, parameters naming a tagged file.
            'ATTACH;FILENAME=/Docs/tagged.pdf;X-NC-FILE-ID=' . $this->taggedId . ':https://files.example.org/f/1',
            // A stale X-NC-FILE-ID of a hidden file behind a link to an allowed one.
            'ATTACH;X-NC-FILE-ID=' . $this->inheritedId . ':' . $okLink,
            'ATTACH;FILENAME=/Secret/plan.pdf:https://files.example.org/x',
            'ATTACH;X-NC-FILE-ID=abc:https://files.example.org/y',
        );

        self::assertSame([$okLink], $shown);
    }

    public function testV10NoUrlIsFetched(): void {
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string)$p->getType(),
            (new \ReflectionClass(OutputReferenceFilter::class))->getConstructor()->getParameters(),
        );
        self::assertNotContains(\OCP\Http\Client\IClientService::class, $types);
        self::assertNotContains(\OCP\Http\Client\IClient::class, $types);
    }
}

/** Talk room double. */
final class StructuredReferenceRoomStub {
    public function __construct(private string $token) {}
    public function getToken(): string {
        return $this->token;
    }
}

/** Talk chat surface the reader reads from. */
interface StructuredReferenceChat {
    /** @return array<IComment> */
    public function getHistory(object $room, int $offset, int $limit, bool $includeLastKnown, int $threadId = 0): array;
}
