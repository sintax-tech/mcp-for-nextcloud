<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * files_mkdir: create folders, never overwrite what is already there.
 *
 * A folder that exists is not an error to route around and not something to touch — it is a clear refusal,
 * because the reorganization is going to move things into this tree and must not silently land in a folder
 * that already has content of a different plan.
 */
final class FilesMkdirTest extends FilesToolsTestCase {
    /** @return array{path:string, created:bool, created_paths:list<string>} */
    private function mkdir(string $path, array $extra = []): array {
        return $this->json('files_mkdir', ['path' => $path] + $extra);
    }

    public function testItIsDeclaredWithTheCreateGrant(): void {
        $definition = array_column($this->module->definitions(), null, 'name')['files_mkdir'];
        $this->assertSame('files', $definition['module']);
        $this->assertSame('create', $definition['operation'], 'files.create é o grant, e nasce desligado');
        $this->assertSame(['path'], $definition['inputSchema']['required']);
        $this->assertArrayHasKey('confirm_shared', $definition['inputSchema']['properties']);
        $this->assertFalse($definition['inputSchema']['additionalProperties']);
        $this->assertStringContainsString('confirm_shared', $definition['description']);
    }

    public function testItCreatesTheFolderAndSaysSo(): void {
        $out = $this->mkdir('/2026');
        $this->assertSame('/2026', $out['path']);
        $this->assertTrue($out['created']);
        $this->assertSame(['/2026'], $out['created_paths']);
        $this->assertTrue(array_key_exists('/alice/files/2026', $this->tree->nodes));
        $this->assertSame(['mkdir /alice/files/2026'], $this->tree->ops);
    }

    /** The parents are created too, and the result names every level, so the agent knows what appeared. */
    public function testItCreatesMissingParentsAndNamesThem(): void {
        $out = $this->mkdir('/2026/Q1/contratos');
        $this->assertTrue($out['created']);
        $this->assertSame(['/2026', '/2026/Q1', '/2026/Q1/contratos'], $out['created_paths']);
        $this->assertSame(['mkdir /alice/files/2026', 'mkdir /alice/files/2026/Q1', 'mkdir /alice/files/2026/Q1/contratos'], $this->tree->ops);
    }

    /** An existing folder is refused and left exactly as it was: no delete, no reuse, no surprise. */
    public function testItRefusesAFolderThatAlreadyExistsWithoutTouchingIt(): void {
        $this->tree->addFile('/alice/files/Documentos/2026/plano.md', 'plano', 'text/markdown');
        $this->assertSame(ReorganizationMessages::destinationExists(), $this->failure('files_mkdir', ['path' => '/Documentos/2026']));
        $this->assertSame('plano', $this->tree->nodes['/alice/files/Documentos/2026/plano.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testItRefusesAnExistingFileAsWell(): void {
        $this->assertSame(ReorganizationMessages::destinationExists(), $this->failure('files_mkdir', ['path' => '/Documentos/ata.md']));
        $this->assertSame([], $this->tree->ops);
    }

    public function testItRefusesTraversalAndTheBackupFolder(): void {
        try {
            $this->tool('files_mkdir', ['path' => '/../fora']);
            $this->fail('travessia não pode criar nada');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid argument: path', $e->getMessage());
        }
        $this->assertSame(EditingMessages::backupPath(), $this->failure('files_mkdir', ['path' => '/MCP backups/2026']));
        $this->assertSame([], $this->tree->ops);
    }

    /** Creating inside a team folder reaches other people, so it needs the confirmation first. */
    public function testATeamFolderDestinationAsksForConfirmationAndCreatesNothing(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFolder('/alice/files/Engenharia', ['scope' => 'team']);
        $out = $this->mkdir('/Engenharia/2026');
        $this->assertTrue($out['requiresConfirmation']);
        $this->assertSame('team', $out['scope']);
        $this->assertSame('Engenharia', $out['teamFolder']);
        $this->assertSame('/Engenharia/2026', $out['resource']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testATeamFolderDestinationIsCreatedOnceConfirmed(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $this->tree->addFolder('/alice/files/Engenharia', ['scope' => 'team']);
        $out = $this->mkdir('/Engenharia/2026', ['confirm_shared' => true]);
        $this->assertTrue($out['created']);
        $this->assertTrue(array_key_exists('/alice/files/Engenharia/2026', $this->tree->nodes));
    }

    /** A destination Nextcloud will not let the user write into is refused whatever the confirmation. */
    public function testADestinationTheUserCannotWriteToIsDeniedEitherWay(): void {
        // isCreatable follows the create permission in Nextcloud, so that is what the fake answers from.
        $this->tree->addFolder('/alice/files/Equipe', ['permissions' => \OCP\Constants::PERMISSION_READ]);
        $this->tree->nodes['/alice/files/Equipe']['updateable'] = false;
        foreach ([false, true] as $confirmed) {
            $this->assertSame(FileAccessMessages::forbidden(),
                $this->failure('files_mkdir', ['path' => '/Equipe/2026', 'confirm_shared' => $confirmed]));
        }
        $this->assertSame([], $this->tree->ops);
    }

    /** The root is a folder that already exists, so it is refused like any other. */
    public function testTheRootIsRefused(): void {
        $this->assertSame(ReorganizationMessages::destinationExists(), $this->failure('files_mkdir', ['path' => '/']));
    }

    public function testItRefusesAnExistingHiddenFileAsNotFound(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $this->config->mock($this), $tagMapper, $this->visibilityRoot());
        $this->setUp();

        $id = $this->tree->addFile('/alice/files/secret.txt', 'secret');
        $tagMapper->method('getTagIdsForObjects')->willReturn([(string)$id => ['999']]);

        $this->assertSame(FileAccessMessages::notFound(), $this->failure('files_mkdir', ['path' => '/secret.txt']));
        $this->tree->addFolder('/alice/files/ReadOnly', ['permissions' => \OCP\Constants::PERMISSION_READ]);
        foreach (['/secret.txt', '/ReadOnly/new'] as $path) {
            try {
                $this->plan('files_mkdir', ['path' => $path]);
                $this->fail('The unavailable destination must be refused');
            } catch (ToolFailure $e) {
                $this->assertSame($path === '/secret.txt' ? FileAccessMessages::notFound() : FileAccessMessages::forbidden(), $e->getMessage());
            }
        }
        $this->assertSame([], $this->tree->ops);
    }

    /** "0" is a level like any other, in the folder made and in the report of what was made. */
    public function testAFolderNamedZeroIsALevelLikeAnyOther(): void {
        $this->tree->addFolder('/alice/files/Docs');

        $out = $this->mkdir('/Docs/0/new');

        $this->assertSame(['/Docs/0', '/Docs/0/new'], $out['created_paths']);
        $this->assertSame(['mkdir /alice/files/Docs/0', 'mkdir /alice/files/Docs/0/new'], $this->tree->ops);
        $this->assertArrayHasKey('/alice/files/Docs/0/new', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Docs/new', $this->tree->nodes);
    }

    public function testAFolderUnderAHiddenFolderNamedZeroIsRefused(): void {
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $this->visibilityGuard = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $this->config->mock($this), $tagMapper);
        $this->setUp();
        $zeroId = $this->tree->addFolder('/alice/files/Docs/0');
        $tagMapper->method('getTagIdsForObjects')->willReturnCallback(static fn (array $ids): array => array_combine(
            $ids, array_map(static fn ($id): array => (int)$id === $zeroId ? ['999'] : [], $ids)));

        try {
            $this->plan('files_mkdir', ['path' => '/Docs/0/new']);
            $this->fail('files_mkdir planned a folder under the hidden folder 0');
        } catch (ToolFailure $e) {
            $this->assertSame(FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertSame(FileAccessMessages::notFound(), $this->failure('files_mkdir', ['path' => '/Docs/0/new']));
        $this->assertSame([], $this->tree->ops);
        $this->assertArrayNotHasKey('/alice/files/Docs/0/new', $this->tree->nodes);
        $this->assertArrayNotHasKey('/alice/files/Docs/new', $this->tree->nodes);
    }
}
