<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Server;
use OCP\Share\IManager as IShareManager;

/**
 * V9, phase 1 (real): a real Talk conversation with a real room share of a file. When the file is excluded by a tag,
 * directly or through an ancestor, talk_read_messages keeps the message and its type and withholds the attachment id
 * for every participant; attaching or quoting the excluded file is refused like a file that does not exist. A file
 * only outside the Files scope, with no tag, is refused the same way, and an unreadable scope is a 503 for the existing
 * and the missing reference alike; the wiring of the scope into those gates is checked on the real container.
 */
final class TalkReferenceTest extends ExclusionTestCase {
    /** @return array{string, int} conversation token and attachment id of a freshly attached file */
    private function conversationWithAttachment(McpClient $alice): array {
        $room = $alice->write('talk_create_group', ['name' => 'mcp-it ' . $this->run, 'participants' => [self::BOB]]);
        $this->assertTrue($room->ok(), 'talk_create_group: ' . $room->describe());
        $token = (string)($room->json()['conversation_token'] ?? '');
        $this->assertNotSame('', $token, 'the conversation has a token: ' . $room->describe());

        $this->makeFile('home', 'open/talk.txt', 'talk attachment');
        $attach = $alice->write('talk_attach_file', ['conversation_token' => $token, 'path' => $this->path('home', 'open', 'talk.txt')]);
        $this->assertTrue($attach->ok(), 'talk_attach_file: ' . $attach->describe());
        $id = $attach->json()['attachmentId'] ?? null;
        $this->assertIsInt($id, 'the attachment has an id: ' . $attach->describe());
        return [$token, $id];
    }

    /** @return list<array<string, mixed>> */
    private function messages(McpClient $client, string $token): array {
        $reply = $client->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
        $this->assertTrue($reply->ok(), 'talk_read_messages: ' . $reply->describe());
        $messages = $reply->json()['messages'] ?? null;
        $this->assertIsArray($messages, $reply->describe());
        return $messages;
    }

    /** @param list<array<string, mixed>> $messages */
    private function attachmentIds(array $messages): array {
        return array_values(array_filter(array_map(static fn (array $m) => $m['attachmentId'] ?? null, $messages), static fn ($id) => $id !== null));
    }

    public function testTheAttachmentIdOfAFileExcludedByATagIsWithheldFromEveryParticipant(): void {
        $alice = $this->mcp();
        $bob = $this->mcp(self::BOB);
        [$token, $id] = $this->conversationWithAttachment($alice);
        $this->exclude();

        // Control: an exclusion is configured, the file is not excluded, and the id is reported to both.
        $this->assertSame([$id], $this->attachmentIds($this->messages($alice, $token)), 'alice sees the id before the tag');
        $this->assertSame([$id], $this->attachmentIds($this->messages($bob, $token)), 'bob sees the id before the tag');
        $before = count($this->messages($alice, $token));

        $file = $this->node(self::ALICE, $this->path('home', 'open', 'talk.txt'));
        $this->tag($file);
        foreach (['alice' => $alice, 'bob' => $bob] as $who => $client) {
            $messages = $this->messages($client, $token);
            $this->assertSame([], $this->attachmentIds($messages), "$who: the id of the file excluded by its own tag is withheld");
            $this->assertCount($before, $messages, "$who: the message itself stays");
            $reply = $client->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
            $this->assertStringNotContainsString('talk.txt', $reply->raw, "$who: no file name in the answer");
        }

        $this->untag($file);
        $this->assertSame([$id], $this->attachmentIds($this->messages($bob, $token)), 'the id returns when the tag is removed');

        $this->tag($this->node(self::ALICE, $this->path('home', 'open')));
        foreach (['alice' => $alice, 'bob' => $bob] as $who => $client) {
            $this->assertSame([], $this->attachmentIds($this->messages($client, $token)), "$who: the id of a file under an excluded ancestor is withheld");
        }
    }

    /**
     * The node a room share hands back is the owner's, and it is clean here; the participant sees the file under a
     * folder of their own that is excluded. Reading, the quote draft and the confirmed quote of that participant all
     * follow the participant's tree, and answer as for an attachment that does not exist; the owner is unaffected.
     */
    public function testAParticipantWhoseViewOfTheFileIsExcludedCannotQuoteIt(): void {
        $alice = $this->mcp();
        $bob = $this->mcp(self::BOB);
        [$token, $id] = $this->conversationWithAttachment($alice);
        $file = $this->node(self::ALICE, $this->path('home', 'open', 'talk.txt'));
        $this->assertSame([$id], $this->attachmentIds($this->messages($bob, $token)), 'bob sees the attachment before the tag');
        // Where bob sees the file: the target Talk gave the room share for him, inside a folder of his own.
        $target = Server::get(IShareManager::class)->getShareById('ocRoomShare:' . $id, self::BOB)->getTarget();
        $this->assertNotSame('/', dirname($target), 'bob sees the file inside a folder of his own: ' . $target);
        $bobFolder = $this->ensureFolder(self::BOB, dirname($target));
        $this->assertNotSame((int)$file->getParent()->getId(), (int)$bobFolder->getId(), 'that folder is not on the owner\'s path');
        $before = count($this->messages($alice, $token));
        $this->exclude();
        $this->tag($bobFolder);
        $this->later(fn () => $this->untag($bobFolder));
        $this->assertTrue($this->newGuard()->isVisible($file), 'the owner node stays clean');

        $this->assertSame([$id], $this->attachmentIds($this->messages($alice, $token)), 'alice, whose view is clean, still sees the id');
        $this->assertSame([], $this->attachmentIds($this->messages($bob, $token)), 'bob does not');

        $bogus = $bob->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => 2000000000]);
        $this->assertTrue($bogus->isError(), 'quoting an attachment that does not exist is refused: ' . $bogus->describe());
        foreach (['draft' => $bob->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id]),
            'confirmed' => $bob->write('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id])] as $step => $reply) {
            $this->assertTrue($reply->isError(), "$step: bob cannot quote the file his tree excludes: " . $reply->describe());
            $this->assertSame($bogus->fingerprint(), $reply->fingerprint(), "$step: and the answer is that of an attachment that does not exist");
            $this->assertStringNotContainsString('talk.txt', $reply->raw, "$step: no file name");
        }
        $this->assertCount($before, $this->messages($alice, $token), 'nothing was posted');

        $draft = $alice->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id]);
        $this->assertTrue($draft->ok(), 'alice can still draft the quote: ' . $draft->describe());
    }

    public function testAttachingOrQuotingAnExcludedFileIsRefusedLikeAMissingOneAndPostsNothing(): void {
        $alice = $this->mcp();
        [$token, $id] = $this->conversationWithAttachment($alice);
        $before = count($this->messages($alice, $token));
        $this->makeFile('home', 'locked/sensitive.txt', 'sensitive');
        $this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
        $this->tag($this->node(self::ALICE, $this->path('home', 'open', 'talk.txt')));
        $this->exclude();

        $excluded = ['conversation_token' => $token, 'path' => $this->path('home', 'locked', 'sensitive.txt')];
        $missing = ['conversation_token' => $token, 'path' => $this->path('home', 'locked', 'nothing-here.txt')];
        $reference = $alice->tool('talk_attach_file', ['conversation_token' => $token, 'path' => '/mcp-it-missing-' . $this->run . '.txt']);
        $this->assertTrue($reference->isError(), 'a missing file is refused: ' . $reference->describe());
        foreach ([$alice->tool('talk_attach_file', $excluded), $alice->write('talk_attach_file', $excluded),
            $alice->tool('talk_attach_file', $missing), $alice->write('talk_attach_file', $missing)] as $reply) {
            $this->assertTrue($reply->isError(), 'attaching a file under an excluded folder is refused: ' . $reply->describe());
            $this->assertSame($reference->fingerprint(), $reply->fingerprint(), 'and answers like a file that does not exist (status and whole result)');
        }

        $bogus = $alice->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => 2000000000]);
        $this->assertTrue($bogus->isError(), 'quoting an attachment that does not exist is refused: ' . $bogus->describe());
        foreach ([$alice->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id]),
            $alice->write('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id])] as $reply) {
            $this->assertTrue($reply->isError(), 'quoting the attachment of an excluded file is refused: ' . $reply->describe());
            $this->assertSame($bogus->fingerprint(), $reply->fingerprint(), 'and answers like an attachment that does not exist (status and whole result)');
        }
        $this->assertCount($before, $this->messages($alice, $token), 'nothing was posted');
    }

    /**
     * The real container wires the Files scope into every gate that could skip it (M1 of the B5 merge review): the
     * Talk resolvers get the structured-reference filter with the user's Files scope, and the Files gate of the tools
     * and of nc://files gets the FilesScope.
     */
    public function testTheContainerWiresTheFilesScopeIntoEveryFileReferenceGate(): void {
        $property = static fn (object $object, string $name): mixed => (new \ReflectionProperty($object, $name))->getValue($object);
        foreach ([\OCA\Mcp\Modules\Talk\Attachments\UserFileResolver::class, \OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess::class] as $class) {
            $filter = $property(Server::get($class), 'references');
            $this->assertInstanceOf(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class, $filter, $class);
            $this->assertInstanceOf(\OCA\Mcp\Access\ResourceScope\FilesReferenceScope::class, $property($filter, 'scope'), $class);
        }
        $this->assertInstanceOf(\OCA\Mcp\Access\ResourceScope\FilesScope::class,
            $property($property($property(Server::get(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class), 'fileReader'), 'visibilityGuard'), 'scope'), 'nc://files');
        $this->assertInstanceOf(\OCA\Mcp\Access\ResourceScope\FilesScope::class,
            $property($property($property(($property(Server::get(\OCA\Mcp\Modules\Files\FilesModule::class), 'factories')['files_read'])(), 'reader'), 'visibilityGuard'), 'scope'), 'files tools');
    }

    /**
     * V9 with the Files scope on the real server, with no exclusion tag: alice's Files scope holds one folder only.
     * Attaching a file outside it, and quoting the attachment of a file outside it, answer exactly like a missing file
     * or attachment, as a plan and confirmed, and post nothing. When her scope cannot be read, the same calls answer the
     * endpoint's 503 for the existing and the missing reference alike (C1 of the second B5 review).
     */
    public function testAFileOnlyOutsideTheFilesScopeIsRefusedLikeAMissingOneAndAnUnreadableScopeIs503(): void {
        $alice = $this->mcp();
        [$token, $id] = $this->conversationWithAttachment($alice);
        $this->makeFile('home', 'outside/out.txt', 'outside the scope');
        $this->makeFile('home', 'scoped/in.txt', 'inside the scope');
        $this->later(function (): void {
            foreach ([\OCA\Mcp\Db\ResourceScopeMapper::MODES_TABLE, \OCA\Mcp\Db\ResourceScopeMapper::ITEMS_TABLE] as $table) {
                $qb = $this->official()->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(\OCA\Mcp\Db\IdentityKey::user(self::ALICE))))->executeStatement();
            }
        });
        Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(
            Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'], 'admin', self::ALICE, 'files',
            [(string)$this->node(self::ALICE, $this->path('home', 'scoped'))->getId()]);
        $before = count($this->messages($alice, $token));

        $outside = ['conversation_token' => $token, 'path' => $this->path('home', 'outside', 'out.txt')];
        $missing = ['conversation_token' => $token, 'path' => $this->path('home', 'outside', 'nothing-here.txt')];
        $quoted = ['conversation_token' => $token, 'attachment_id' => $id];
        $bogus = ['conversation_token' => $token, 'attachment_id' => 2000000000];
        $reference = $alice->tool('talk_attach_file', $missing);
        $this->assertTrue($reference->isError(), 'a missing file is refused: ' . $reference->describe());
        foreach (['plan' => $alice->tool('talk_attach_file', $outside), 'confirmed' => $alice->write('talk_attach_file', $outside)] as $step => $reply) {
            $this->assertSame($reference->fingerprint(), $reply->fingerprint(), "attach $step: a file only outside the scope answers like a missing one");
        }
        $noAttachment = $alice->tool('talk_quote_file', $bogus);
        $this->assertTrue($noAttachment->isError(), $noAttachment->describe());
        foreach (['plan' => $alice->tool('talk_quote_file', $quoted), 'confirmed' => $alice->write('talk_quote_file', $quoted)] as $step => $reply) {
            $this->assertSame($noAttachment->fingerprint(), $reply->fingerprint(), "quote $step: the attachment of a file outside the scope answers like a missing one");
            $this->assertStringNotContainsString('talk.txt', $reply->raw, "quote $step: no file name");
        }
        $this->assertTrue($alice->tool('talk_attach_file', ['conversation_token' => $token, 'path' => $this->path('home', 'scoped', 'in.txt')])->ok(),
            'a file inside the scope can still be attached (plan)');
        $this->assertCount($before, $this->messages($alice, $token), 'nothing was posted');

        // An inconsistent mode row (another uid behind alice's key) makes her scope read fail closed.
        $qb = $this->official()->getQueryBuilder();
        $qb->insert(\OCA\Mcp\Db\ResourceScopeMapper::MODES_TABLE)
            ->setValue('user_key', $qb->createNamedParameter(\OCA\Mcp\Db\IdentityKey::user(self::ALICE)))
            ->setValue('module', $qb->createNamedParameter('calendar'))
            ->setValue('user_id', $qb->createNamedParameter('mcp-it-collision'))
            ->setValue('policy_rev', $qb->createNamedParameter('1'))
            ->executeStatement();
        foreach (['attach plan, existing' => $alice->tool('talk_attach_file', $outside), 'attach plan, missing' => $alice->tool('talk_attach_file', $missing),
            'attach, existing' => $alice->write('talk_attach_file', $outside), 'attach, missing' => $alice->write('talk_attach_file', $missing),
            'quote plan, existing' => $alice->tool('talk_quote_file', $quoted), 'quote plan, missing' => $alice->tool('talk_quote_file', $bogus),
            'quote, existing' => $alice->write('talk_quote_file', $quoted), 'quote, missing' => $alice->write('talk_quote_file', $bogus)] as $step => $reply) {
            $this->assertSame(503, $reply->status, "$step: an unreadable scope is the endpoint's 503: " . $reply->describe());
        }
    }
}
