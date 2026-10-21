<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\FileAccess\Sharing;

use OCA\Mcp\Mcp\Confirmation\ConfirmationMessages;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Platform\FileAccess\Sharing\SharingMessages;

use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Constants;
use PHPUnit\Framework\TestCase;

/**
 * The shared-write guard: a write outside the personal scope asks the user first and writes nothing
 * until they answer. Files, Notes and, after the integration, Deck and Calendar all get this exact JSON.
 */
final class SharedWriteGuardTest extends TestCase {
    private SharedWriteGuard $guard;
    private FakeTree $tree;

    protected function setUp(): void {
        $this->tree = new FakeTree($this);
        $this->guard = new SharedWriteGuard(new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager()));
    }

    private function node(string $scope, string $path, int $permissions = Constants::PERMISSION_ALL): object {
        $this->tree->addFile($path, 'x', 'text/markdown', ['scope' => $scope, 'permissions' => $permissions]);
        return $this->tree->node($path);
    }

    public function testPersonalFileNeedsNoConfirmation(): void {
        $this->assertNull($this->guard->guard($this->node('personal', '/alice/files/Documentos/ata.md'), 'alice', '/Documentos/ata.md', false));
    }

    public function testTeamFolderAsksForConfirmationAndNamesTheFolder(): void {
        $this->tree->mountPath = '/alice/files/Engenharia';
        $payload = $this->guard->guard($this->node('team', '/alice/files/Engenharia/x.md'), 'alice', '/Engenharia/x.md', false);
        $this->assertSame([
            'requiresConfirmation' => true,
            'scope' => 'team',
            'owner' => 'pedro',
            'ownerDisplayName' => 'Paulo Exemplo',
            'teamFolder' => 'Engenharia',
            'resource' => '/Engenharia/x.md',
            'message' => SharingMessages::teamFolder('Engenharia') . ' ' . ConfirmationMessages::confirmAdvice(),
        ], $payload);
    }

    public function testSharedFileNamesWhoSharedIt(): void {
        $payload = $this->guard->guard($this->node('shared', '/alice/files/C/x.md'), 'alice', '/C/x.md', false);
        $this->assertSame('shared', $payload['scope']);
        $this->assertSame('Paulo Exemplo', $payload['sharedBy']);
        $this->assertStringStartsWith(SharingMessages::sharedBy('Paulo Exemplo'), $payload['message']);
        $this->assertArrayNotHasKey('teamFolder', $payload);
    }

    public function testExternalStorageIsNamedWithoutAPerson(): void {
        $payload = $this->guard->guard($this->node('external', '/alice/files/E/x.md'), 'alice', '/E/x.md', false);
        $this->assertSame('external', $payload['scope']);
        $this->assertStringStartsWith(SharingMessages::externalStorage(), $payload['message']);
    }

    /** Every scope writes once the user confirmed, and the payload is then absent. */
    public function testConfirmationLetsEveryScopeThrough(): void {
        foreach (['team', 'shared', 'external'] as $scope) {
            $this->assertNull($this->guard->guard($this->node($scope, "/alice/files/$scope/x.md"), 'alice', "/$scope/x.md", true), $scope);
        }
    }

    /** Without the update permission Nextcloud refuses, and a confirmation does not buy anything. */
    public function testMissingUpdatePermissionIsDeniedEitherWay(): void {
        $node = $this->node('personal', '/alice/files/Documentos/leitura.md', Constants::PERMISSION_READ);
        foreach ([false, true] as $confirmed) {
            try {
                $this->guard->guard($node, 'alice', '/Documentos/leitura.md', $confirmed);
                $this->fail('a read-only node must never be writable');
            } catch (ToolFailure $e) {
                $this->assertSame(FileAccessMessages::forbidden(), $e->getMessage());
            }
        }
    }

    /** The payload is the contract the other modules copy, so its key set is fixed. */
    public function testPayloadKeysAreTheAgreedOnes(): void {
        $payload = $this->guard->guard($this->node('shared', '/alice/files/C/x.md'), 'alice', '/C/x.md', false);
        $this->assertSame(['requiresConfirmation', 'scope', 'owner', 'ownerDisplayName', 'sharedBy', 'resource', 'message'],
            array_keys($payload));
        $this->assertArrayNotHasKey('isError', $payload);
    }
}
