<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\CheckoutService;
use OCA\Mcp\Tools\Files\FileCreation;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tools\UnknownToolException;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * files_upload and files_create: a new file, never one that already exists.
 *
 * files_upload only plans and mints the single-use link; the bytes reach the file through the checkout route,
 * which {@see \OCA\Mcp\Tests\Unit\Checkout\CheckoutCreateTest} covers. files_create writes small text inline.
 * Both refuse an occupied name, a missing folder and a name Nextcloud itself refuses, before anything is written.
 */
final class FilesCreateTest extends FilesToolsTestCase {
    /** @return \OCA\Mcp\Checkout\CheckoutToken|null the create link minted so far, if any */
    private function createToken(): ?CheckoutToken {
        return $this->store->ofKind(CheckoutToken::KIND_CREATE);
    }

    /**
     * @param string $tool files_upload or files_create
     * @param array<string, mixed> $arguments arguments of the call
     * @return ArgumentValidationException the refusal of the plan, which is also the refusal of the write
     */
    private function invalid(string $tool, array $arguments): ArgumentValidationException {
        try {
            $this->plan($tool, $arguments);
        } catch (ArgumentValidationException $e) {
            return $e;
        }
        $this->fail("$tool accepted an invalid path");
    }

    /** @return string the message of the ToolFailure the plan raised */
    private function planFailure(string $tool, array $arguments): string {
        try {
            $this->plan($tool, $arguments);
        } catch (\OCA\Mcp\Tools\ToolFailure $e) {
            return $e->getMessage();
        }
        $this->fail("plan of $tool did not fail");
    }

    public function testBothToolsAreDeclaredUnderTheCreateGrant(): void {
        $definitions = array_column($this->module->definitions(), null, 'name');
        foreach (['files_upload' => ['path'], 'files_create' => ['path', 'content']] as $tool => $required) {
            $this->assertSame('files', $definitions[$tool]['module'], $tool);
            $this->assertSame('create', $definitions[$tool]['operation'], $tool);
            $this->assertSame($required, $definitions[$tool]['inputSchema']['required'], $tool);
            $this->assertArrayHasKey('confirm_shared', $definitions[$tool]['inputSchema']['properties'], $tool);
            $this->assertFalse($definitions[$tool]['inputSchema']['additionalProperties'], $tool);
        }
        $this->assertSame('integer', $definitions['files_upload']['inputSchema']['properties']['size']['type']);
        $this->assertStringContainsString('-T /tmp/file -X PUT', $definitions['files_upload']['description']);
        $this->assertStringContainsString('-H "Content-Type: application/octet-stream"', $definitions['files_upload']['description']);
        $this->assertStringContainsString('files_checkout', $definitions['files_upload']['description']);
        $this->assertStringContainsString('files_upload', $definitions['files_create']['description']);
        foreach (['files_upload', 'files_create'] as $tool) {
            $this->assertStringContainsString('with content', $definitions[$tool]['description'], $tool);
        }
        $this->assertStringContainsString('with content', implode(' ', $this->module->guideNotes()));
    }

    public function testTheUploadPlanNamesTheFileTheFolderAndTheLimitAndMintsNothing(): void {
        $plan = $this->plan('files_upload', ['path' => '/Documentos/Relatório.docx', 'size' => 2048]);

        $this->assertSame('files_upload', $plan['action']);
        $this->assertSame('/Documentos/Relatório.docx', $plan['path']);
        $this->assertSame('Relatório.docx', $plan['name']);
        $this->assertSame('/Documentos', $plan['folder']);
        $this->assertSame(2048, $plan['size']);
        $this->assertSame(CheckoutService::DEFAULT_MAX_BYTES, $plan['uploadMaxBytes']);
        $this->assertSame(CheckoutService::UPLOAD_TTL, $plan['uploadTtlSeconds']);
        $this->assertFalse($plan['requiresSharedConfirmation']);
        $this->assertTrue($plan['linksAfterConfirmation']);
        $this->assertNull($this->createToken(), 'a plan never mints a link');
        $this->assertSame([], $this->tree->ops);
    }

    public function testTheConfirmedUploadReturnsASingleUseLinkBoundToThePathAndWritesNothing(): void {
        $out = $this->json('files_upload', ['path' => '/Documentos/Relatório.docx']);

        $this->assertSame(['path', 'uploadUrl', 'method', 'expiresAt', 'maxBytes', 'curl', 'message'], array_keys($out));
        $this->assertSame('/Documentos/Relatório.docx', $out['path']);
        $this->assertSame('PUT', $out['method']);
        $token = $this->lastToken('mcp.checkout.upload');
        $this->assertStringStartsWith('ncmcp_co_c_', $token);
        $this->assertSame('https://cloud.test/apps/mcp/' . $token, $out['uploadUrl']);
        $this->assertStringContainsString('-T', $out['curl']);
        $this->assertStringContainsString($out['uploadUrl'], $out['curl']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1790000000 + CheckoutService::UPLOAD_TTL), $out['expiresAt']);

        $row = $this->createToken();
        $this->assertNotNull($row);
        $this->assertSame('alice', $row->userId);
        $this->assertSame('/Documentos/Relatório.docx', $row->path);
        $this->assertSame(0, $row->fileId, 'the token keeps the path, never a node id');
        $this->assertSame('personal', $row->scope);
        $this->assertSame(1790000000 + CheckoutService::UPLOAD_TTL, $row->expiresAt);
        $this->assertCount(1, $this->store->rows, 'one upload link, no download link');
        $this->assertArrayNotHasKey('/alice/files/Documentos/Relatório.docx', $this->tree->nodes);
        $this->assertSame([], $this->tree->ops);
    }

    /** An existing name is refused in the plan and in the confirmation, before any link exists. */
    public function testAnExistingNameIsRefusedAndPointsToCheckout(): void {
        foreach (['files_upload' => [], 'files_create' => ['content' => 'x']] as $tool => $extra) {
            $arguments = ['path' => '/Documentos/ata.md'] + $extra;
            $this->assertSame(FilesMessages::fileExists(), $this->planFailure($tool, $arguments), $tool);
            $this->assertSame(FilesMessages::fileExists(), $this->failure($tool, $arguments), $tool);
        }
        $this->assertStringContainsString('files_checkout', FilesMessages::fileExists());
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertNull($this->createToken());
        $this->assertSame([], $this->tree->ops);
    }

    /** An existing folder with the same name is as taken as a file. */
    public function testAFolderWithTheSameNameIsAlsoTaken(): void {
        $this->assertSame(FilesMessages::fileExists(), $this->failure('files_upload', ['path' => '/Documentos']));
    }

    public function testAMissingFolderIsRefusedWithTheAdviceToCreateIt(): void {
        foreach (['files_upload' => [], 'files_create' => ['content' => 'x']] as $tool => $extra) {
            $this->assertSame(FilesMessages::createFolderMissing(), $this->failure($tool, ['path' => '/Nao/Existe/novo.md'] + $extra), $tool);
        }
        $this->assertStringContainsString('files_mkdir', FilesMessages::createFolderMissing());
        // A hidden folder must not be told apart from a missing one, so the message is the generic not-found first.
        $this->assertStringStartsWith(CommonMessages::notFound(), FilesMessages::createFolderMissing());
        $this->assertNull($this->createToken());
        $this->assertSame([], $this->tree->ops);
    }

    public function testAFileInTheMiddleOfThePathIsNotAFolder(): void {
        $this->assertSame(FilesMessages::notAFolder(), $this->failure('files_upload', ['path' => '/Documentos/ata.md/novo.docx']));
    }

    public function testAFolderThatDoesNotAcceptNewFilesIsForbidden(): void {
        $this->tree->addFolder('/alice/files/Leitura', ['permissions' => \OCP\Constants::PERMISSION_READ]);
        foreach (['files_upload' => [], 'files_create' => ['content' => 'x']] as $tool => $extra) {
            $this->assertSame(CommonMessages::forbidden(), $this->planFailure($tool, ['path' => '/Leitura/novo.md'] + $extra), $tool);
            $this->assertSame(CommonMessages::forbidden(), $this->failure($tool, ['path' => '/Leitura/novo.md'] + $extra), $tool);
        }
        $this->assertNull($this->createToken());
    }

    public function testTheBackupFolderIsRefused(): void {
        $this->tree->addFolder('/alice/files/MCP backups');
        $this->assertSame(FilesMessages::backupPath(), $this->failure('files_upload', ['path' => '/MCP backups/novo.docx']));
        $this->assertSame(FilesMessages::backupPath(), $this->failure('files_create', ['path' => '/MCP backups/novo.md', 'content' => 'x']));
    }

    /**
     * Traversal, the root, a reserved name and a forbidden extension are argument errors: the G4 shape names the
     * argument and a fixed rule, and never repeats what was sent.
     */
    public function testInvalidNamesAreArgumentErrorsThatNeverEchoTheName(): void {
        foreach (['/../fora.docx', '/', '/Documentos/.htaccess', '/Documentos/relatorio.docx.part', '/Documentos/x.FILEPART',
            '/Documentos/' . str_repeat('n', 251) . '.docx'] as $path) {
            foreach (['files_upload' => [], 'files_create' => ['content' => 'x']] as $tool => $extra) {
                $e = $this->invalid($tool, ['path' => $path] + $extra);
                $this->assertSame('path', $e->details()['field'], "$tool $path");
                $this->assertNotSame('', $e->details()['rule']);
                foreach (['fora', 'htaccess', 'relatorio', 'FILEPART', 'nnnn'] as $echo) {
                    $this->assertStringNotContainsString($echo, $e->clientMessage(), "$tool $path");
                    $this->assertStringNotContainsString($echo, $e->getMessage(), "$tool $path");
                }
            }
        }
        $this->assertNull($this->createToken());
        $this->assertSame([], $this->tree->ops);
    }

    /** A declared size over the limit is refused before any link exists: the upload would be a 413 anyway. */
    public function testADeclaredSizeOverTheLimitIsRefusedInThePlanAndTheConfirmation(): void {
        $this->config->app['mcp'][CheckoutService::MAX_BYTES_KEY] = '1000';
        $this->assertSame(FilesMessages::uploadTooLarge(1000), $this->planFailure('files_upload', ['path' => '/novo.docx', 'size' => 1001]));
        $this->assertSame(FilesMessages::uploadTooLarge(1000), $this->failure('files_upload', ['path' => '/novo.docx', 'size' => 1001]));
        $this->assertNull($this->createToken());
        $this->assertSame(1000, $this->plan('files_upload', ['path' => '/novo.docx', 'size' => 1000])['size']);
    }

    /** A folder shared with other people asks the user first, and only a confirmed call mints the link. */
    public function testATeamFolderAsksForTheSharedConfirmationBeforeTheLink(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFolder('/alice/files/Engenharia', ['scope' => 'team']);

        $plan = $this->plan('files_upload', ['path' => '/Engenharia/novo.docx']);
        $this->assertTrue($plan['requiresSharedConfirmation']);
        $this->assertSame('team', $plan['shared'][0]['scope']);

        $out = $this->json('files_upload', ['path' => '/Engenharia/novo.docx']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('/Engenharia/novo.docx', $out['resource']);
        $this->assertNull($this->createToken(), 'no link before the shared confirmation');

        $out = $this->json('files_upload', ['path' => '/Engenharia/novo.docx', 'confirm_shared' => true]);
        $this->assertArrayHasKey('uploadUrl', $out);
        $this->assertTrue($this->createToken()->sharedConfirmed);
        $this->assertSame('team', $this->createToken()->scope);
    }

    /** Without files.create the registry neither plans nor writes, whatever the arguments say. */
    public function testWithoutTheCreateGrantNeitherToolIsReachable(): void {
        $policy = InMemoryConfig::policy($this->config->mock($this), new InMemoryOAuthStore());
        $policy->setGrant('alice', 'files', 'read', true);
        $policy->setGrant('alice', 'files', 'edit', true);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $registry = new ToolRegistry([$this->module], $policy, $this->apps, $this->users, $this->createMock(LoggerInterface::class));
        $this->assertNotContains('files_upload', array_column($registry->list('alice'), 'name'));
        foreach (['files_upload' => [], 'files_create' => ['content' => 'x']] as $tool => $extra) {
            try {
                $registry->call($tool, ['path' => '/novo.md', 'confirm' => true] + $extra, 'alice');
                $this->fail("$tool ran without files.create");
            } catch (UnknownToolException) {
            }
        }
        $this->assertNull($this->createToken());
        $this->assertSame([], $this->tree->ops);
    }

    public function testTheCreatePlanShowsNameFolderSizeAndAPreviewAndWritesNothing(): void {
        $plan = $this->plan('files_create', ['path' => '/Documentos/notas.md', 'content' => "# Notas\nolá"]);

        $this->assertSame('files_create', $plan['action']);
        $this->assertSame('/Documentos/notas.md', $plan['path']);
        $this->assertSame('notas.md', $plan['name']);
        $this->assertSame('/Documentos', $plan['folder']);
        $this->assertSame(strlen("# Notas\nolá"), $plan['size']);
        $this->assertSame("# Notas\nolá", $plan['preview']);
        $this->assertArrayNotHasKey('/alice/files/Documentos/notas.md', $this->tree->nodes);
        $this->assertSame([], $this->tree->ops);
    }

    /** The preview in the plan is an excerpt: a whole megabyte never travels in the plan. */
    public function testTheCreatePreviewIsCut(): void {
        $plan = $this->plan('files_create', ['path' => '/longo.txt', 'content' => str_repeat('a', 5000)]);
        $this->assertSame(FileCreation::PREVIEW_CHARS, mb_strlen($plan['preview']));
        $this->assertSame(5000, $plan['size']);
    }

    public function testTheConfirmedCreateWritesTheFileAndReturnsTheReceipt(): void {
        $out = $this->json('files_create', ['path' => '/Documentos/notas.md', 'content' => "# Notas\nolá"]);

        $this->assertSame(['path', 'size', 'etag', 'fileId', 'access'], array_keys($out));
        $this->assertSame('/Documentos/notas.md', $out['path']);
        $this->assertSame(strlen("# Notas\nolá"), $out['size']);
        $node = $this->tree->nodes['/alice/files/Documentos/notas.md'];
        $this->assertSame("# Notas\nolá", $node['content']);
        $this->assertSame($node['id'], $out['fileId']);
        $this->assertSame($node['etag'], $out['etag']);
        $this->assertSame('personal', $out['access']['scope']);
        $this->assertSame(['create /alice/files/Documentos/notas.md', 'write /alice/files/Documentos/notas.md'], $this->tree->ops);
        $this->assertSame("# Notas\nolá", $this->tool('files_read', ['path' => '/Documentos/notas.md'])['content'][0]['text'], 'the next read sees it');
    }

    public function testAnEmptyTextFileIsCreated(): void {
        $out = $this->json('files_create', ['path' => '/vazio.txt', 'content' => '']);
        $this->assertSame(0, $out['size']);
        $this->assertSame('', $this->tree->nodes['/alice/files/vazio.txt']['content']);
        $this->assertSame(['create /alice/files/vazio.txt'], $this->tree->ops);
    }

    public function testOnlyTheTextExtensionsAreAcceptedInline(): void {
        foreach (['md', 'txt', 'csv', 'json', 'html', 'xml', 'yaml', 'yml', 'MD'] as $extension) {
            $this->assertSame('files_create', $this->plan('files_create', ['path' => '/novo.' . $extension, 'content' => 'x'])['action'], $extension);
        }
        foreach (['/novo.docx', '/novo.pdf', '/novo', '/novo.md.exe'] as $path) {
            $e = $this->invalid('files_create', ['path' => $path, 'content' => 'x']);
            $this->assertSame('path', $e->details()['field'], $path);
            $this->assertStringContainsString('files_upload', $e->details()['rule'], $path);
        }
    }

    public function testInlineContentIsCappedAtOneMegabyte(): void {
        $limit = FileCreation::MAX_INLINE_BYTES;
        $this->assertSame(1024 * 1024, $limit);
        $this->assertSame(FilesMessages::createTooLarge($limit), $this->planFailure('files_create', ['path' => '/grande.txt', 'content' => str_repeat('a', $limit + 1)]));
        $this->assertSame(FilesMessages::createTooLarge($limit), $this->failure('files_create', ['path' => '/grande.txt', 'content' => str_repeat('a', $limit + 1)]));
        $this->assertStringContainsString('files_upload', FilesMessages::createTooLarge($limit));
        $this->assertArrayNotHasKey('/alice/files/grande.txt', $this->tree->nodes);
        $this->assertSame($limit, $this->json('files_create', ['path' => '/grande.txt', 'content' => str_repeat('a', $limit)])['size']);
    }

    /**
     * Somebody else creates the same name between the check and the write: what they wrote stays and nothing is
     * written over it.
     */
    public function testARaceWithAnotherCreatorNeverWritesOverTheirFile(): void {
        $this->tree->beforeCreate = function (string $path): void {
            $this->tree->addFile($path, 'do outro', 'text/plain');
        };
        $this->assertSame(FilesMessages::fileExists(), $this->failure('files_create', ['path' => '/Documentos/notas.md', 'content' => 'meu']));
        $this->assertSame('do outro', $this->tree->nodes['/alice/files/Documentos/notas.md']['content']);
        $this->assertNotContains('write /alice/files/Documentos/notas.md', $this->tree->ops);
    }

    public function testASharedFolderNeedsTheConfirmationBeforeTheInlineFileIsWritten(): void {
        $this->tree->addFolder('/alice/files/Compartilhado', ['scope' => 'shared']);
        $out = $this->json('files_create', ['path' => '/Compartilhado/notas.md', 'content' => 'x']);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertArrayNotHasKey('/alice/files/Compartilhado/notas.md', $this->tree->nodes);

        $this->json('files_create', ['path' => '/Compartilhado/notas.md', 'content' => 'x', 'confirm_shared' => true]);
        $this->assertSame('x', $this->tree->nodes['/alice/files/Compartilhado/notas.md']['content']);
    }

    // ---------------------------------------------------------------- review of 0.10.0 (B3, M1, B2)

    /** B3: the size the agent declared travels with the link, so the upload can tell an empty body from a failed client. */
    public function testTheDeclaredSizeIsKeptWithTheLink(): void {
        $this->json('files_upload', ['path' => '/com-tamanho.docx', 'size' => 48213]);
        $this->assertSame(48213, $this->createToken()->declaredSize());

        $this->store->rows = [];
        $this->json('files_upload', ['path' => '/sem-tamanho.docx']);
        $this->assertNull($this->createToken()->declaredSize());
        $this->assertSame(\OCA\Mcp\Checkout\CheckoutToken::NO_ETAG, $this->createToken()->etag);
    }

    /**
     * M1, documented: an EMPTY file that another client created with the same name in the instant before ours is
     * taken over — the touch keeps it, it has no content to lose, and our content goes into it.
     */
    public function testAnEmptyFileCreatedInTheWindowIsTakenOver(): void {
        $this->tree->beforeCreate = function (string $path): void {
            $this->tree->addFile($path, '', 'text/plain');
        };
        $out = $this->json('files_create', ['path' => '/Documentos/notas.md', 'content' => 'meu']);
        $this->assertSame('meu', $this->tree->nodes['/alice/files/Documentos/notas.md']['content']);
        $this->assertSame(3, $out['size']);
    }

    /** M1: the file changes between the empty creation and the write (another client touched or wrote it): 409, nothing written. */
    public function testAChangeBetweenTheCreationAndTheWriteIsRefused(): void {
        foreach (['touched' => '', 'written' => 'do outro'] as $label => $content) {
            $path = '/alice/files/Documentos/' . $label . '.md';
            $this->tree->beforeGet = function (string $read) use ($path, $content): void {
                if ($read === $path) {
                    $this->tree->beforeGet = null;
                    $this->tree->nodes[$path]['content'] = $content;
                    $this->tree->nodes[$path]['etag'] .= '+';
                }
            };
            $this->assertSame(FilesMessages::fileExists(), $this->failure('files_create', ['path' => '/Documentos/' . $label . '.md', 'content' => 'meu']), $label);
            $this->assertSame($content, $this->tree->nodes[$path]['content'], $label);
            $this->assertNotContains('write ' . $path, $this->tree->ops, $label);
        }
    }

    /** B2: a lock or a full quota keeps its own message; any other failure is the generic one, logged by class only. */
    public function testAFailedWriteKeepsTheLockAndQuotaMessagesAndLogsTheClassOnly(): void {
        foreach ([[new \OCP\Lock\LockedException('/alice/files/a.md'), CommonMessages::locked()],
            [new \OCP\Files\NotEnoughSpaceException('/alice/files/b.md'), CommonMessages::insufficientQuota()]] as $i => [$failure, $message]) {
            $this->tree->writeFailure = $failure;
            $this->assertSame($message, $this->failure('files_create', ['path' => "/falha$i.md", 'content' => 'x']), $failure::class);
        }
        $this->tree->writeFailure = new \RuntimeException('disk /alice/files/segredo.md broke');
        $this->creationLogger->expects($this->once())->method('error')->with('MCP new file write failed',
            ['app' => 'mcp', 'exception_class' => \RuntimeException::class]);
        $this->assertSame(FilesMessages::createFailed(), $this->failure('files_create', ['path' => '/falha.md', 'content' => 'x']));
    }
}
