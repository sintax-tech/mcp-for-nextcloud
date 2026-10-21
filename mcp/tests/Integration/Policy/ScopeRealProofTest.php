<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\ResourceScope\ScopeItemsInaccessible;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCP\Files\Folder;
use OCP\Server;
use OCP\Share\IManager;

/** B8: native shares and WebDAV changes, observed over HTTP with unchanged Basic and OAuth credentials. */
final class ScopeRealProofTest extends ExclusionTestCase {
    /** @var array<string, Credential> Credentials belong to this test, never to an earlier lifecycle test. */
    private array $credentials = [];

    protected function setUp(): void {
        parent::setUp();
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        foreach ([self::ALICE, self::BOB] as $uid) {
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            foreach (['reply', 'attach', 'quote', 'create'] as $operation) {
                $policy->setGrant($uid, 'talk', $operation, true);
            }
        }
    }

    protected function login(string $uid, string $kind = 'basic'): Credential {
        return $this->credentials[$uid . '/' . $kind] ??= $kind === 'bearer'
            ? LoginFactory::bearer($uid) : LoginFactory::basic($uid);
    }

    /** @param list<Folder>|null $folders */
    private function scope(string $uid, ?array $folders): void {
        Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'], 'admin',
            $uid, 'files', $folders === null ? null : array_map(static fn (Folder $folder): string => (string)$folder->getId(), $folders));
    }

    private function cleanScope(string $uid): void {
        $this->later(fn () => $this->scope($uid, null));
    }

    public function testS4S5NativeShareAndWebDavMovesUseTheRecipientsOwnScopeOnTheNextCall(): void {
        $alice = $this->mcp();
        $bob = $this->mcp(self::BOB);
        $selected = $this->makeFolder('home', 'selected');
        $this->makeFile('home', 'other/moving.txt', 'outside then inside');
        $private = $this->makeFolder('home', 'private');
        $this->makeFile('home', 'private/private.txt', 'no native access for bob');
        $share = $this->shareToUser($selected, self::ALICE, self::BOB);
        $target = $share->getTarget();
        $bobSelected = $this->makeFolder('home', 'selected', self::BOB);
        $this->cleanScope(self::ALICE);
        $this->cleanScope(self::BOB);
        $this->scope(self::ALICE, [$selected, $private]);
        $this->scope(self::BOB, [$bobSelected]);

        $inside = $this->path('home', 'selected/moving.txt');
        $outside = $this->path('home', 'other/moving.txt');
        $this->assertRefusedAsMissing($alice, $alice->tool('files_read', ['path' => $outside]), 'outside before MOVE');
        $this->dav()->move($outside, $inside);
        $this->assertReadable($alice, $inside, 'outside then inside', 'owner after native MOVE into scope');
        // The owner selected the shared folder; that never selects it for the recipient.
        $this->assertRefusedAsMissing($bob, $bob->tool('files_read', ['path' => $target . '/moving.txt']), 'recipient outside own scope');
        $native = Http::request('GET', Http::BASE . '/remote.php/dav/files/' . self::BOB . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($target . '/moving.txt', '/')))),
            ['Authorization: ' . $this->login(self::BOB)->authorization()]);
        $this->assertSame(200, $native['status'], 'native ACL permits the shared file despite the MCP scope');
        $this->assertSame('outside then inside', $native['body']);
        // This CLI process mounted bob before the share existed; mirror a new admin request.
        Server::get(\OC\Files\SetupManager::class)->tearDown();
        $received = $this->node(self::BOB, $target);
        $this->assertInstanceOf(Folder::class, $received);
        $this->scope(self::BOB, [$received]);
        $this->assertReadable($bob, $target . '/moving.txt', 'outside then inside', 'recipient selects own mounted folder');
        $this->assertReadable($alice, $this->path('home', 'private/private.txt'), 'no native access for bob', 'private file selected by the owner');
        $beforeRevision = Server::get(PolicyStateMapper::class)->state()['revision'];
        $beforeScope = Server::get(ResourceScopeMapper::class)->forUser(self::BOB);
        try {
            $this->scope(self::BOB, [$private]);
            $this->fail('An owner-only folder must not be selectable for the recipient');
        } catch (ScopeItemsInaccessible $e) {
            $this->assertSame([(string)$private->getId()], $e->items);
        }
        $this->assertSame($beforeRevision, Server::get(PolicyStateMapper::class)->state()['revision'], 'inaccessible selection writes no revision');
        $this->assertSame($beforeScope, Server::get(ResourceScopeMapper::class)->forUser(self::BOB), 'the recipient scope is unchanged');

        $this->dav()->move($inside, $outside);
        $this->assertRefusedAsMissing($alice, $alice->tool('files_read', ['path' => $outside]), 'owner after native MOVE out');
        $this->assertRefusedAsMissing($bob, $bob->tool('files_read', ['path' => $target . '/moving.txt']), 'recipient after native MOVE out');
        $this->dav()->move($outside, $inside);
        $this->assertReadable($alice, $inside, 'outside then inside', 'owner after second native MOVE in');
        $this->assertReadable($bob, $target . '/moving.txt', 'outside then inside', 'recipient after second native MOVE in');
    }

    public function testS9BasicAndBearerKeepTheirCredentialsAndApplyEachScopeChange(): void {
        $one = $this->makeFolder('home', 'one');
        $two = $this->makeFolder('home', 'two');
        $this->makeFile('home', 'one/one.txt', 'first scope');
        $this->makeFile('home', 'two/two.txt', 'second scope');
        $basic = $this->mcp();
        $bearer = $this->mcp(self::ALICE, 'bearer');
        $this->cleanScope(self::ALICE);
        $generations = Server::get(AuthUsersMapper::class)->generations(self::ALICE);
        $epoch = Server::get(PolicyStateMapper::class)->state()['auth_epoch'];
        foreach ([[$one, 'one/one.txt', 'first scope', 'two/two.txt'], [$two, 'two/two.txt', 'second scope', 'one/one.txt']] as [$folder, $allowed, $content, $denied]) {
            $this->scope(self::ALICE, [$folder]);
            foreach ([$basic, $bearer] as $client) {
                $this->assertReadable($client, $this->path('home', $allowed), $content, 'same credential, current scope');
                $refused = $client->tool('files_read', ['path' => $this->path('home', $denied)]);
                $this->assertRefusedAsMissing($client, $refused, 'same credential outside current scope');
                $this->assertStringStartsWith(FileAccessMessages::notFound(), $refused->text(), 'D6 generic refusal');
                $this->assertSame(200, $client->listStatus(), 'the existing login remains valid');
            }
            $this->assertSame($generations, Server::get(AuthUsersMapper::class)->generations(self::ALICE));
            $this->assertSame($epoch, Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
        }
    }

    public function testV9PhaseTwoTalkWithholdsAnAttachmentOutsideFilesScopeWithoutAnyTag(): void {
        $alice = $this->mcp();
        $bob = $this->mcp(self::BOB);
        $allowed = $this->makeFolder('home', 'allowed', self::BOB);
        $file = $this->makeFile('home', 'outside/scope-secret.txt', 'native room share');
        $room = $alice->write('talk_create_group', ['name' => $this->run, 'participants' => [self::BOB]]);
        $this->assertTrue($room->ok(), $room->describe());
        $token = (string)$room->json()['conversation_token'];
        $attached = $alice->write('talk_attach_file', ['conversation_token' => $token, 'path' => $this->path('home', 'outside/scope-secret.txt')]);
        $this->assertTrue($attached->ok(), $attached->describe());
        $id = $attached->json()['attachmentId'];
        $read = fn () => $bob->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
        $before = $read();
        $this->assertTrue($before->ok(), $before->describe());
        $cards = array_values(array_filter($before->json()['messages'], static fn (array $m): bool => ($m['attachmentId'] ?? null) === $id));
        $this->assertCount(1, $cards, 'control: native Talk ACL exposes the attachment before scope');
        $messageId = $cards[0]['id'];
        $control = $bob->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id]);
        $this->assertTrue($control->ok(), 'native ACL also permits the quote draft before scope: ' . $control->describe());
        $this->assertStringContainsString('scope-secret.txt', $control->raw);
        // Read envelopes omit names structurally; only attachmentId depends on Files scope.
        $this->assertStringNotContainsString('scope-secret.txt', $before->raw);
        $replyArguments = ['conversation_token' => $token, 'message' => 'Answer without attachment metadata', 'reply_to' => (int)$messageId];
        $replyControls = [];
        foreach ([false, true] as $confirm) {
            $replyControl = $bob->tool('talk_reply', $replyArguments + ['confirm' => $confirm]);
            $this->assertTrue($replyControl->ok(), 'unrestricted reply control: ' . $replyControl->describe());
            $this->assertStringNotContainsString('scope-secret.txt', $replyControl->raw);
            $controlData = $confirm ? $replyControl->json() : $replyControl->structured();
            $this->assertIsArray($controlData, $replyControl->describe());
            $this->assertNotSame([], $controlData, 'the real reply control has a payload');
            $this->assertArrayNotHasKey('attachmentId', $controlData);
            $replyControls[(int)$confirm] = $controlData;
        }
        $countBeforeScope = count($read()->json()['messages']);
        $share = Server::get(IManager::class)->getShareById('ocRoomShare:' . $id, self::BOB);
        $this->assertSame((int)$file->getId(), (int)$share->getNode()->getId(), 'a real readable room share');
        $this->cleanScope(self::BOB);
        $this->scope(self::BOB, [$allowed]);
        $hidden = $read();
        $this->assertTrue($hidden->ok(), $hidden->describe());
        $cardsAfter = array_values(array_filter($hidden->json()['messages'], static fn (array $m): bool => $m['id'] === $messageId));
        $this->assertCount(1, $cardsAfter, 'the attachment message stays');
        $this->assertSame($cards[0]['type'], $cardsAfter[0]['type']);
        $this->assertArrayHasKey('attachmentId', $cardsAfter[0]);
        $this->assertNull($cardsAfter[0]['attachmentId']);
        $this->assertStringNotContainsString('scope-secret.txt', $hidden->raw);
        $ownerRead = $alice->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
        $this->assertTrue($ownerRead->ok(), $ownerRead->describe());
        $this->assertContains($id, array_column($ownerRead->json()['messages'], 'attachmentId'), 'the participant scope never restricts the owner');

        $missing = $bob->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => 2000000000]);
        foreach ([false, true] as $confirm) {
            $quote = $bob->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id, 'confirm' => $confirm]);
            $this->assertRefusedAsMissing($bob, $quote, 'quote outside Files scope, including its plan', $missing);
            $this->assertStringNotContainsString('scope-secret.txt', $quote->raw);
            $this->assertArrayNotHasKey('attachmentId', $quote->json() ?? []);
        }
        $this->assertCount($countBeforeScope, $read()->json()['messages'], 'refused quotes post nothing');
        foreach ([false, true] as $confirm) {
            $reply = $bob->tool('talk_reply', $replyArguments + ['confirm' => $confirm]);
            $this->assertTrue($reply->ok(), $reply->describe());
            $this->assertStringNotContainsString('scope-secret.txt', $reply->raw);
            $actualBody = $confirm ? $reply->json() : $reply->structured();
            $this->assertIsArray($actualBody, $reply->describe());
            $this->assertArrayNotHasKey('attachmentId', $actualBody, 'reply result/plan carries no attachment id');
            $controlBody = $replyControls[(int)$confirm];
            if ($confirm) {
                $this->assertIsInt($controlBody['messageId']);
                $this->assertIsInt($actualBody['messageId']);
                unset($controlBody['messageId'], $actualBody['messageId']);
            }
            $this->assertSame($controlBody, $actualBody, 'reply format is identical with open and restricted Files scope');
        }
        $this->assertStringNotContainsString('scope-secret.txt', $read()->raw);
    }
}
