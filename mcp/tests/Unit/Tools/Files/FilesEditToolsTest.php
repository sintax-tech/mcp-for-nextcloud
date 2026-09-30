<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Checkout\CheckoutToken;

/**
 * files_replace, files_checkout and the shared-write guard as the Files tools expose them: the read
 * tools gain an access description, every write outside the personal scope asks the user first, and the
 * checkout hands out two single-use links without ever putting the content in the tool result.
 */
final class FilesEditToolsTest extends FilesToolsTestCase {
    private const FILE = '/alice/files/Documentos/ata.md';

    public function testReplaceSwapsTheOnlyOccurrenceAndReturnsTheDiff(): void {
        $out = $this->json('files_replace', ['path' => '/Documentos/ata.md', 'old' => 'Ata', 'new' => 'Ata de']);
        $this->assertSame("# Ata de\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertSame(
            "--- antes\n+++ depois\n@@ -1,2 +1,2 @@\n-# Ata\n+# Ata de\n olá\n\\ No newline at end of file\n", $out['diff']);
        $this->assertStringStartsWith('/MCP backups/Documentos/ata.md.', $out['backup']);
        $this->assertSame('personal', $out['access']['scope']);
    }

    /**
     * A Latin-1 CSV has bytes that mb_scrub cannot decode. Replacing an ASCII snippet must leave every one
     * of those bytes exactly as it was: writing back the extracted text would have turned each into "?".
     */
    public function testReplaceLeavesBytesOutsideTheSnippetUntouched(): void {
        $latin1 = "nome;cidade\nJo\xE3o;S\xE3o Paulo\nMaria;Rio\n";
        $this->tree->addFile('/alice/files/Documentos/pessoas.csv', $latin1, 'text/csv');
        $this->json('files_replace', ['path' => '/Documentos/pessoas.csv', 'old' => 'Rio', 'new' => 'Recife']);
        $written = $this->tree->nodes['/alice/files/Documentos/pessoas.csv']['content'];
        $this->assertSame(str_replace('Rio', 'Recife', $latin1), $written);
        $this->assertSame(2, substr_count($written, "\xE3"), 'os acentos Latin-1 precisam sobreviver byte a byte');
        $this->assertStringNotContainsString('?', $written);
    }

    /** A UTF-8 file with a BOM keeps its BOM: mb_scrub and the extractor drop it, the bytes do not. */
    public function testReplaceKeepsTheByteOrderMark(): void {
        $withBom = "\u{FEFF}# Ata\n";
        $this->tree->addFile('/alice/files/Documentos/bom.md', $withBom, 'text/markdown');
        $this->json('files_replace', ['path' => '/Documentos/bom.md', 'old' => 'Ata', 'new' => 'Ata de']);
        $written = $this->tree->nodes['/alice/files/Documentos/bom.md']['content'];
        $this->assertSame("\u{FEFF}# Ata de\n", $written);
        $this->assertStringStartsWith("\u{FEFF}", $written);
    }

    /** Line endings are bytes too: CRLF in, CRLF out, without the extractor normalising them. */
    public function testReplaceKeepsCarriageReturns(): void {
        $this->tree->addFile('/alice/files/Documentos/crlf.txt', "um\r\ndois\r\n", 'text/plain');
        $this->json('files_replace', ['path' => '/Documentos/crlf.txt', 'old' => 'dois', 'new' => 'tres']);
        $this->assertSame("um\r\ntres\r\n", $this->tree->nodes['/alice/files/Documentos/crlf.txt']['content']);
    }

    /** A snippet that is not valid UTF-8 cannot be located safely, so it is refused before anything else. */
    public function testReplaceRefusesASnippetThatIsNotValidUtf8(): void {
        $this->tree->addFile('/alice/files/Documentos/pessoas.csv', "a;\xE3o\n", 'text/csv');
        $this->assertSame('O trecho informado não é UTF-8 válido; envie-o exatamente como aparece no arquivo.',
            $this->failure('files_replace', ['path' => '/Documentos/pessoas.csv', 'old' => "\xE3", 'new' => 'x']));
        $this->assertSame("a;\xE3o\n", $this->tree->nodes['/alice/files/Documentos/pessoas.csv']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    /** The count is over bytes, so the same snippet twice in a Latin-1 file is still ambiguous. */
    public function testReplaceCountsOccurrencesOverBytes(): void {
        $this->tree->addFile('/alice/files/Documentos/pessoas.csv', "Rio\nS\xE3o\nRio\n", 'text/csv');
        $this->assertSame('O trecho informado aparece 2 vezes no arquivo; informe um trecho único.',
            $this->failure('files_replace', ['path' => '/Documentos/pessoas.csv', 'old' => 'Rio', 'new' => 'x']));
    }

    /**
     * Restoring a file that lives inside /MCP backups would roll a recovery copy back and then back up that
     * copy, so the folder would grow on every attempt and the user would be editing their own safety net.
     */
    public function testVersionRestoreRefusesAFileInsideTheBackupFolder(): void {
        $copy = '/alice/files/MCP backups/Documentos/ata.md.20260921-141320.bak';
        $this->tree->addFile($copy, '# Ata', 'text/markdown');
        $this->assertSame('Arquivos em "/MCP backups" não podem ser editados pelo MCP.', $this->failure(
            'files_version_restore',
            ['path' => '/MCP backups/Documentos/ata.md.20260921-141320.bak', 'version' => '1759100000', 'confirm' => true],
        ));
        $this->assertSame([], $this->tree->ops, 'nem backup do backup, nem escrita, nem remoção');
        $this->assertSame('# Ata', $this->tree->nodes[$copy]['content']);
    }

    /** The restore path is guarded exactly like the edit path, before the guard and before any backup. */
    public function testTheBackupFolderIsRefusedForEveryWriteTool(): void {
        $this->tree->addFile('/MCP backups/ata.md', '# Ata', 'text/markdown');
        foreach (['files_edit', 'files_replace', 'files_checkout'] as $tool) {
            $arguments = $tool === 'files_replace' ? ['old' => 'Ata', 'new' => 'x'] : ($tool === 'files_edit' ? ['content' => 'x'] : []);
            $this->assertSame('Arquivos em "/MCP backups" não podem ser editados pelo MCP.',
                $this->failure($tool, ['path' => '/MCP backups/ata.md'] + $arguments), $tool);
        }
        $this->assertSame([], $this->tree->ops);
    }

    /**
     * The rule is "the MCP never loses a file", and a backup overwrite would lose one. Two edits inside the
     * same second land on the same base name, so the second has to get its own file and the first has to
     * still be readable.
     */
    public function testTwoBackupsInTheSameSecondKeepBothCopies(): void {
        $first = $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v2'])['backup'];
        $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v3']);
        $second = $this->json('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'v4'])['backup'];
        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('.bak', $first);
        $this->assertStringEndsWith('-3.bak', $second, 'a terceira cópia precisa de sufixo próprio');
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $first]['content'], 'a primeira cópia não pode ser sobrescrita');
        $this->assertSame('v3', $this->tree->nodes['/alice/files' . $second]['content'], 'cada guarda o conteúdo que existia na sua hora');
    }

    public function testReplaceRefusesASnippetThatIsNotThere(): void {
        $this->assertSame('O trecho informado não aparece no arquivo.',
            $this->failure('files_replace', ['path' => '/Documentos/ata.md', 'old' => 'inexistente', 'new' => 'x']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testReplaceRefusesAnAmbiguousSnippetAndSaysHowOftenItOccurs(): void {
        $this->tree->addFile('/alice/files/Documentos/repetido.md', 'a a a', 'text/markdown');
        $this->assertSame('O trecho informado aparece 3 vezes no arquivo; informe um trecho único.',
            $this->failure('files_replace', ['path' => '/Documentos/repetido.md', 'old' => 'a', 'new' => 'b']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testReplaceRespectsTheEtagAndWritesNothingOnConflict(): void {
        $this->assertSame('O recurso foi alterado desde a última leitura (etag divergente); nada foi gravado.',
            $this->failure('files_replace', ['path' => '/Documentos/ata.md', 'old' => 'Ata', 'new' => 'x', 'etag' => 'outro']));
        $this->assertSame("# Ata\nolá", $this->tree->nodes[self::FILE]['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testReplaceRefusesBinariesAndTheBackupFolder(): void {
        $this->tree->addFile('/alice/files/foto.jpg', 'bin', 'image/jpeg');
        $this->assertSame('Somente arquivos de texto podem ser editados pelo MCP.',
            $this->failure('files_replace', ['path' => '/foto.jpg', 'old' => 'bin', 'new' => 'x']));
        $this->assertSame('Arquivos em "/MCP backups" não podem ser editados pelo MCP.',
            $this->failure('files_replace', ['path' => '/MCP backups/ata.md', 'old' => 'Ata', 'new' => 'x']));
    }

    public function testCheckoutReturnsTwoLinksAndNeverTheContent(): void {
        $out = $this->json('files_checkout', ['path' => '/Documentos/ata.md']);
        $this->assertSame(['path', 'etag', 'size', 'mime', 'access', 'download_url', 'upload_url', 'expires_at'], array_keys($out));
        $this->assertArrayNotHasKey('content', $out);
        $this->assertNotSame($out['download_url'], $out['upload_url']);
        $this->assertSame('https://cloud.test/apps/mcp/' . $this->downloadToken(), $out['download_url']);
        $this->assertSame('https://cloud.test/apps/mcp/' . $this->uploadToken(), $out['upload_url']);
        $this->assertSame($this->tree->nodes[self::FILE]['etag'], $out['etag']);
    }

    public function testCheckoutStoresOnlyHashesAndRemembersTheEtag(): void {
        $this->json('files_checkout', ['path' => '/Documentos/ata.md']);
        foreach ($this->store->rows as $row) {
            $this->assertSame(64, strlen((string)$row['token_hash']));
            $this->assertStringNotContainsString('ncmcp_co_', json_encode($row, JSON_THROW_ON_ERROR));
        }
        $upload = $this->store->ofKind(CheckoutToken::KIND_UPLOAD);
        $this->assertSame($this->tree->nodes[self::FILE]['etag'], $upload->etag);
        $this->assertSame('/Documentos/ata.md', $upload->path);
        $this->assertSame((int)$this->tree->nodes[self::FILE]['id'], $upload->fileId);
        $this->assertSame('alice', $upload->userId);
        $this->assertFalse($upload->sharedConfirmed);
    }

    public function testTheTwoLinksHaveTheirOwnLifetimes(): void {
        $out = $this->json('files_checkout', ['path' => '/Documentos/ata.md']);
        $this->assertSame(1790000000 + \OCA\Mcp\Tools\Files\CheckoutService::UPLOAD_TTL, $this->store->ofKind(CheckoutToken::KIND_UPLOAD)->expiresAt);
        $this->assertSame(1790000000 + \OCA\Mcp\Tools\Files\CheckoutService::DOWNLOAD_TTL, $this->store->ofKind(CheckoutToken::KIND_DOWNLOAD)->expiresAt);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1790000000 + \OCA\Mcp\Tools\Files\CheckoutService::UPLOAD_TTL), $out['expires_at']);
    }

    /** A checkout of a shared file only issues links once the user confirmed. */
    public function testCheckoutOnASharedFileAsksForConfirmationFirst(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $out = $this->json('files_checkout', ['path' => '/Compartilhado/plano.md']);
        $this->assertArrayNotHasKey('download_url', $out);
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('Pedro Almeida', $out['sharedBy']);
        $this->assertSame([], $this->store->rows);
    }

    public function testCheckoutOnATeamFolderIssuesLinksOnlyAfterConfirmation(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFile('/alice/files/Engenharia/especificacao.md', 'x', 'text/markdown', ['scope' => 'team']);
        $this->assertTrue($this->json('files_checkout', ['path' => '/Engenharia/especificacao.md'])['requiresConfirmation']);
        $out = $this->json('files_checkout', ['path' => '/Engenharia/especificacao.md', 'confirm_shared' => true]);
        $this->assertArrayHasKey('download_url', $out);
        $this->assertTrue($this->store->ofKind(CheckoutToken::KIND_UPLOAD)->sharedConfirmed);
    }

    public function testEditOnASharedFileAsksForConfirmationWithoutWriting(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $out = $this->json('files_edit', ['path' => '/Compartilhado/plano.md', 'content' => 'novo']);
        $this->assertArrayNotHasKey('isError', $this->tool('files_edit', ['path' => '/Compartilhado/plano.md', 'content' => 'novo']));
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('plano', $this->tree->nodes['/alice/files/Compartilhado/plano.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testEditWritesOnceTheUserConfirmed(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $out = $this->json('files_edit', ['path' => '/Compartilhado/plano.md', 'content' => 'novo', 'confirm_shared' => true]);
        $this->assertSame('novo', $this->tree->nodes['/alice/files/Compartilhado/plano.md']['content']);
        $this->assertSame('-plano', explode("\n", $out['diff'])[3]);
    }

    /** A node Nextcloud refuses to update stays refused, confirmation or not. */
    public function testASharedFileWithoutUpdatePermissionIsDeniedEitherWay(): void {
        $path = '/alice/files/Compartilhado/leitura.md';
        $this->tree->addFile($path, 'x', 'text/markdown',
            ['scope' => 'shared', 'permissions' => \OCP\Constants::PERMISSION_READ]);
        foreach ([false, true] as $confirmed) {
            $this->assertSame('Sem acesso a este recurso no Nextcloud.',
                $this->failure('files_edit', ['path' => '/Compartilhado/leitura.md', 'content' => 'y', 'confirm_shared' => $confirmed]));
        }
        $this->assertSame([], $this->tree->ops);
    }

    /** The confirmation payload is what the agent shows the user, so it must not be an error. */
    public function testTheConfirmationIsANonErrorResultWithNoDiffAndNoBackup(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', 'plano', 'text/markdown', ['scope' => 'shared']);
        $result = $this->tool('files_replace', ['path' => '/Compartilhado/plano.md', 'old' => 'plano', 'new' => 'x']);
        $this->assertArrayNotHasKey('isError', $result);
        $payload = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['requiresConfirmation', 'scope', 'owner', 'ownerDisplayName', 'sharedBy', 'resource', 'message'],
            array_keys($payload));
        $this->assertSame('/Compartilhado/plano.md', $payload['resource']);
    }

    /** files_read keeps its text in content[0] and only adds metadata in content[1]. */
    public function testReadKeepsItsTextFirstAndAppendsTheMetadata(): void {
        $result = $this->tool('files_read', ['path' => '/Documentos/ata.md']);
        $this->assertCount(2, $result['content']);
        $this->assertSame("# Ata\nolá", $result['content'][0]['text']);
        $metadata = json_decode($result['content'][1]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['path', 'etag', 'size', 'mime', 'access'], array_keys($metadata));
        $this->assertSame('personal', $metadata['access']['scope']);
        $this->assertSame($this->tree->nodes[self::FILE]['etag'], $metadata['etag']);
    }

    /** Search results carry the same description a listing does. */
    public function testSearchReportsTheScopeOfEveryHit(): void {
        $this->tree->addFile('/alice/files/Engenharia/ata-equipe.md', 'x', 'text/markdown', ['scope' => 'team']);
        $entries = $this->json('files_search', ['query' => 'ata']);
        $scopes = [];
        foreach ($entries as $entry) {
            $scopes[$entry['path']] = $entry['access'];
        }
        $this->assertSame('personal', $scopes['/Documentos/ata.md']['scope']);
        $this->assertSame('team', $scopes['/Engenharia/ata-equipe.md']['scope']);
        $this->assertSame('Engenharia', $scopes['/Engenharia/ata-equipe.md']['teamFolder']);
    }

    /** Downloads and uploads get separate random tokens; two checkouts never repeat one. */
    public function testEveryCheckoutMintsFreshTokens(): void {
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $out = $this->json('files_checkout', ['path' => '/Documentos/ata.md']);
            $tokens[] = $this->uploadToken();
            $tokens[] = $this->downloadToken();
            $this->assertStringStartsWith('ncmcp_co_u_', $this->uploadToken());
            $this->assertStringStartsWith('ncmcp_co_d_', $this->downloadToken());
            $this->assertGreaterThan(40, strlen($this->uploadToken()));
        }
        $this->assertCount(10, array_unique($tokens));
    }
}
