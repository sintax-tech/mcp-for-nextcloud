<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Editing;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Files\Editing\EditRequest;
use OCA\Mcp\Modules\Files\Tools\EditFile;
use OCA\Mcp\Tests\Support\Files\FilesEditFixture;

final class EditFileTest extends FilesEditFixture {
    public function testGlobalConfirmationDoesNotImplySharedConfirmation(): void {
        $request = EditRequest::fromArray(['path' => '/Notes/readme.md', 'content' => 'New', 'confirm' => true]);
        self::assertNull($request->etag);
        self::assertFalse($request->confirmShared);
        $request = EditRequest::fromArray(['path' => '/Notes/readme.md', 'content' => 'New', 'etag' => 'e102', 'confirm_shared' => true]);
        self::assertSame('e102', $request->etag);
        self::assertTrue($request->confirmShared);
    }

    public function testPreviewHasNoEffectsAndExecutionUsesTheSameRequest(): void {
        $tool = new EditFile($this->editor);
        $arguments = ['path' => '/Notes/readme.md', 'content' => "# Notes\nNew"];
        $plan = $tool->preview($arguments, new ToolContext('alice'));
        self::assertSame('files_edit', $plan['action']);
        self::assertSame(['before' => 10, 'after' => 11], $plan['size']);
        self::assertSame('e102', $plan['etag']);
        self::assertSame('/MCP backups', $plan['backup']);
        self::assertSame('personal', $plan['access']['scope']);
        self::assertSame([], $plan['shared']);
        self::assertFalse($plan['requiresSharedConfirmation']);
        self::assertTrue($plan['recoverable']);
        self::assertStringContainsString('-é', $plan['diff']);
        self::assertStringContainsString('+New', $plan['diff']);
        self::assertSame([], $this->tree->ops);
        self::assertSame([], $this->locks->forbidden);
        $result = $tool->execute($arguments, new ToolContext('alice'));
        $written = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($plan['diff'], $written['diff']);
        self::assertSame("# Notes\nNew", $this->tree->nodes[self::FILE]['content']);
        self::assertSame("# Notes\né", $this->tree->nodes['/alice/files' . $written['backup']]['content']);
        $writes = array_values(array_filter($this->tree->ops, fn ($op) => str_starts_with($op, 'write ')));
        self::assertCount(2, $writes);
        self::assertStringContainsString('/MCP backups/', $writes[0]);
        self::assertSame('write ' . self::FILE, $writes[1]);
    }
}
