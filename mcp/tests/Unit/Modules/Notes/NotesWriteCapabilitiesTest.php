<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes;

final class NotesWriteCapabilitiesTest extends \OCA\Mcp\Tests\Support\Notes\NotesTestCase
{

    public function testCreatePreviewsWithoutFoldersAndExecutesInCategoryZero(): void
    {
        $class = \OCA\Mcp\Modules\Notes\Creation\NoteCreator::class;
        self::assertTrue(class_exists($class), 'Create capability is missing');
        $cap = $this->capability($class);
        $args = ['title' => 'New', 'content' => '# New', 'category' => '0'];
        $plan = $cap->preview($args, 'alice');
        self::assertTrue($plan['note']['categoryCreated']);
        self::assertSame([], $this->tree->ops);
        $result = $cap->execute($args, 'alice');
        self::assertSame('0', $result['category']);
        self::assertSame('# New', $this->tree->nodes['/alice/files/Notes/0/New.md']['content']);
    }

    public function testEditPreservesPartialRenameFailureAndSavedContent(): void
    {
        $class = \OCA\Mcp\Modules\Notes\Editing\NoteEditor::class;
        self::assertTrue(class_exists($class), 'Edit capability is missing');
        $cap = $this->capability($class);
        $path = '/alice/files/Notes/Work/Before.md';
        $args = $this->writes()['notes_edit'];
        $cap->preview($args, 'alice');
        self::assertSame([], $this->tree->ops);
        $this->tree->failMove[] = $path;
        try {
            $cap->execute($args, 'alice');
            self::fail('Expected rename refusal');
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            self::assertStringEndsWith('The new content was saved; only the title was not changed.', $e->getMessage());
        }
        self::assertSame('# Changed', $this->tree->nodes[$path]['content']);
    }

    public function testMoveRefusesCollisionAndDoesNotOverwrite(): void
    {
        $class = \OCA\Mcp\Modules\Notes\Moving\NoteMover::class;
        self::assertTrue(class_exists($class), 'Move capability is missing');
        $cap = $this->capability($class);
        $this->tree->addFile('/alice/files/Notes/Target/Before.md', 'Keep');
        $args = $this->writes()['notes_move'];
        self::assertTrue($cap->preview($args, 'alice')['titleTaken']);
        try {
            $cap->execute($args, 'alice');
            self::fail('Expected collision');
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            self::assertSame('A note with this title already exists in the destination category.', $e->getMessage());
        }
        self::assertSame('Keep', $this->tree->nodes['/alice/files/Notes/Target/Before.md']['content']);
        self::assertSame([], $this->tree->ops);
    }

    public function testDeleteRefusesUnavailableTrashBeforeLookingUpNote(): void
    {
        $class = \OCA\Mcp\Modules\Notes\Deletion\NoteDeleter::class;
        self::assertTrue(class_exists($class), 'Delete capability is missing');
        $cap = $this->capability($class);
        $this->enabledApps = ['notes'];
        try {
            $cap->execute(['id' => 99999], 'alice');
            self::fail('Expected trash refusal');
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            self::assertStringStartsWith('Deletion blocked:', $e->getMessage());
        }
        self::assertSame(1, $this->rootReads);
        self::assertSame([], $this->tree->ops);
    }
}
