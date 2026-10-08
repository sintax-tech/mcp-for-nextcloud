<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\ToolGuide;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolPresentation;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The guide answers with what tools/list answers: the same titles, the same schemas and the same permission
 * filter, plus the notes a module declares about itself. These tests hold it to that, because the failure
 * it prevents is a model acting on a tool it was told about wrongly.
 */
final class ToolGuideTest extends TestCase {
    private GrantPolicy $policy;
    /** @var list<string> */
    private array $enabledApps = ['notes', 'deck'];

    protected function setUp(): void {
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $this->policy->setGrant('alice', 'files', 'read', true);
        $this->policy->setGrant('alice', 'files', 'edit', true);
        $this->policy->setGrant('alice', 'notes', 'read', true);
        $this->policy->setGrant('alice', 'notes', 'delete', true);
        $this->policy->setGrant('alice', 'deck', 'read', true);
    }

    /** Files with one write, Notes with one delete and notes of its own. */
    private function modules(): array {
        return [$this->files(), $this->notes()];
    }

    private function registry(?array $modules = null): ToolRegistry {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(
            fn (string $app, IUser $user) => in_array($app, $this->enabledApps, true));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        return new ToolRegistry($modules ?? $this->modules(), $this->policy, $apps, $users,
            $this->createMock(LoggerInterface::class));
    }

    /** @return array<string, mixed> */
    private function guide(array $arguments = [], ?ToolRegistry $registry = null): array {
        return ($registry ?? $this->registry())->call(ToolGuide::TOOL, $arguments, 'alice');
    }

    /** @return string the markdown of a guide result */
    private function markdown(array $result): string {
        $this->assertSame('text', $result['content'][0]['type']);
        return $result['content'][0]['text'];
    }

    private function files(): ToolModule {
        return new class implements ToolModule {
            public function definitions(): array {
                return [
                    ['name' => 'files_read', 'description' => 'Reads a file.', 'module' => 'files', 'operation' => 'read',
                        'inputSchema' => ['type' => 'object', 'properties' => [
                            'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Absolute path.'],
                        ], 'required' => ['path'], 'additionalProperties' => false]],
                    ['name' => 'files_edit', 'description' => 'Replaces the content.', 'module' => 'files', 'operation' => 'edit',
                        'inputSchema' => ['type' => 'object', 'properties' => [
                            'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Absolute path.'],
                            'mode' => ['type' => 'string', 'enum' => ['replace', 'patch'], 'default' => 'replace', 'description' => 'How to apply.'],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        ], 'required' => ['path', 'mode'], 'additionalProperties' => false]],
                ];
            }
            public function call(string $name, array $arguments, string $userId): array {
                throw new \LogicException('the guide never calls a module');
            }
        };
    }

    private function notes(): ToolModule {
        return new class implements ToolModule, ToolGuideNotes {
            public function definitions(): array {
                return [
                    ['name' => 'notes_list', 'description' => 'Lists the notes.', 'module' => 'notes', 'operation' => 'read',
                        'app' => 'notes', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
                    ['name' => 'notes_delete', 'description' => 'Deletes a note.', 'module' => 'notes', 'operation' => 'delete',
                        'app' => 'notes', 'inputSchema' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the note.'],
                            'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'Must be true.'],
                            'confirm_shared' => ['type' => 'boolean', 'description' => 'Confirms a shared note.'],
                        ], 'required' => ['id'], 'additionalProperties' => false]],
                ];
            }
            public function call(string $name, array $arguments, string $userId): array {
                throw new \LogicException('the guide never calls a module');
            }
            public function guideNotes(): array {
                return ['A note is addressed by the id notes_list returns.'];
            }
        };
    }

    public function testTheOverviewListsOnlyWhatThisUserMayCall(): void {
        $modules = $this->guide()['structuredContent']['modules'];

        $this->assertSame(['files', 'notes'], array_column($modules, 'module'));
        $this->assertSame([2, 2], array_column($modules, 'count'));
        $this->assertStringContainsString('- **Files** (`files`): 2 tools — Read file, Edit file.', $this->markdown($this->guide()));

        // A grant taken away takes the whole module with it: files.read off, nothing of files is listed.
        $this->policy->setGrant('alice', 'files', 'edit', false);
        $this->policy->setGrant('alice', 'files', 'read', false);
        $this->assertSame(['notes'], array_column($this->guide()['structuredContent']['modules'], 'module'));

        // A module whose app is off for the user disappears as well, exactly as it does from tools/list.
        $this->enabledApps = [];
        $this->assertSame([], $this->guide()['structuredContent']['modules']);
        $this->assertStringContainsString('No module is available', $this->markdown($this->guide()));
    }

    /** The fixture modules throw on call(): a guide that reached a handler would fail this test. */
    public function testTheGuideOnlyReadsDefinitions(): void {
        $this->guide(['module' => 'files']);
        $this->guide(['tool' => 'files_edit']);
        $this->expectNotToPerformAssertions();
    }

    public function testTheModuleDetailReflectsTheSchemaOfEachTool(): void {
        $result = $this->guide(['module' => 'files']);
        $markdown = $this->markdown($result);

        $this->assertStringContainsString('`path` (string, required, length from 1) — Absolute path.', $markdown);
        $this->assertStringContainsString('`path` (string, required, length from 1)', $markdown);
        $this->assertStringContainsString('`mode` (string, required, default replace, one of replace, patch)', $markdown);
        $this->assertStringContainsString('`limit` (integer, optional, default 25, 1..100)', $markdown);
        $this->assertStringContainsString('Reads only.', $markdown);
        $this->assertStringContainsString('Writes: it changes something in this Nextcloud.', $markdown);
        // A write asks for confirm and, when the schema has it, for the shared confirmation too.
        $this->assertStringContainsString('Needs `confirm` (boolean, must be true) before it writes.', $markdown);
        // A read has no gate at all: the guide does not print an empty line where one would be.
        $this->assertSame(1, substr_count($markdown, 'Needs `confirm`'));

        $tools = array_column($result['structuredContent']['tools'], null, 'name');
        $this->assertSame(['confirm'], $tools['files_edit']['confirmation']);
        $this->assertSame([], $tools['files_read']['confirmation']);
        $this->assertTrue($tools['files_read']['read_only']);
        $this->assertFalse($tools['files_edit']['read_only']);
        $this->assertSame([
            ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Absolute path.',
                'constraints' => ['length from 1']],
            ['name' => 'mode', 'type' => 'string', 'required' => true, 'description' => 'How to apply.',
                'constraints' => ['default replace', 'one of replace, patch']],
            ['name' => 'limit', 'type' => 'integer', 'required' => false, 'description' => '',
                'constraints' => ['default 25', '1..100']],
            // The guide reads the published schema, so it shows the `confirm` the registry adds to every write.
            ['name' => 'confirm', 'type' => 'boolean', 'required' => false, 'description' => CommonMessages::confirmParameter(),
                'constraints' => []],
        ], $tools['files_edit']['parameters']);
    }

    public function testChangingASchemaChangesWhatTheGuideSays(): void {
        $before = $this->markdown($this->guide(['module' => 'files']));
        $this->assertStringContainsString('`limit` (integer, optional, default 25, 1..100)', $before);

        $files = new class implements ToolModule {
            public function definitions(): array {
                return [['name' => 'files_read', 'description' => 'Reads a file.', 'module' => 'files', 'operation' => 'read',
                    'inputSchema' => ['type' => 'object', 'properties' => [
                        'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000, 'description' => 'Absolute path.'],
                    ], 'required' => ['path'], 'additionalProperties' => false]]];
            }
            public function call(string $name, array $arguments, string $userId): array {
                throw new \LogicException('the guide never calls a module');
            }
        };
        $after = $this->markdown($this->guide(['module' => 'files'], $this->registry([$files])));

        $this->assertStringContainsString('`path` (string, required, length 1..4000)', $after);
        $this->assertStringNotContainsString('`limit`', $after);
    }

    public function testTheExtraGatesOfASchemaAreNamed(): void {
        $markdown = $this->markdown($this->guide(['module' => 'notes']));

        $this->assertStringContainsString('Needs `confirm` (boolean, must be true) before it writes.', $markdown);
        $this->assertStringContainsString('Needs `confirm_shared` (boolean) before it writes.', $markdown);
        $this->assertStringContainsString('A note is addressed by the id notes_list returns.', $markdown);
        $this->assertSame(['confirm', 'confirm_shared'],
            $this->guide(['module' => 'notes'])['structuredContent']['tools'][1]['confirmation']);
    }

    public function testASingleToolIsDetailedWithItsModule(): void {
        $result = $this->guide(['tool' => 'files_edit']);

        $this->assertSame('files_edit', $result['structuredContent']['name']);
        $this->assertSame('files', $result['structuredContent']['module']);
        $this->assertSame(['confirm'], $result['structuredContent']['tool']['confirmation']);
        $this->assertStringContainsString("# Edit file (`files_edit`)", $this->markdown($result));
        $this->assertStringContainsString('Module: Files (`files`). Grant operation: `edit`.', $this->markdown($result));
        $this->assertSame(['path', 'mode', 'limit', 'confirm'], array_column($result['structuredContent']['tool']['parameters'], 'name'));

        // The tool of a module that needs an app says which one.
        $delete = $this->guide(['tool' => 'notes_delete']);
        $this->assertStringContainsString('Needs the `notes` app enabled.', $this->markdown($delete));
        $this->assertSame(['A note is addressed by the id notes_list returns.'], $delete['structuredContent']['notes']);
    }

    public function testToolWinsOverModule(): void {
        $result = $this->guide(['module' => 'files', 'tool' => 'notes_list']);

        $this->assertSame('notes_list', $result['structuredContent']['name']);
        $this->assertStringContainsString('Module: Notes (`notes`).', $this->markdown($result));
    }

    public function testAnUnknownModuleOrToolIsRefusedInAReadableWay(): void {
        $module = $this->guide(['module' => 'mail']);
        $this->assertTrue($module['isError']);
        $this->assertStringContainsString('no module "mail" available to you', $this->markdown($module));
        $this->assertStringContainsString('The modules you can use are: files, notes.', $this->markdown($module));

        $tool = $this->guide(['tool' => 'mail_send']);
        $this->assertTrue($tool['isError']);
        $this->assertStringContainsString('no tool "mail_send" available to you', $this->markdown($tool));
        $this->assertStringContainsString('without arguments', $this->markdown($tool));

        // A tool hidden by a grant or a disabled app is refused the same way as one that does not exist.
        $this->enabledApps = [];
        $this->assertTrue($this->guide(['tool' => 'notes_delete'])['isError']);
    }

    public function testTheGuideCarriesTheResultTwice(): void {
        $result = $this->guide(['module' => 'files']);

        $this->assertArrayNotHasKey('isError', $result);
        $this->assertSame('files', $result['structuredContent']['module']);
        $this->assertStringContainsString('Edit file', $result['structuredContent']['tools'][1]['title']);
        $this->assertStringContainsString('# Files (`files`)', $result['content'][0]['text']);
        $this->assertArrayHasKey('language_note', $result['structuredContent']);
    }

    public function testTheGuideIsListedAsAReadOnlyTool(): void {
        $listed = array_column($this->registry()->list('alice'), null, 'name');

        $this->assertSame('Tool guide', $listed[ToolGuide::TOOL]['title']);
        $this->assertSame([
            'title' => 'Tool guide',
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $listed[ToolGuide::TOOL]['annotations']);
        $this->assertSame(['module', 'tool'], array_keys($listed[ToolGuide::TOOL]['inputSchema']['properties']));
    }

    public function testTheInstructionsPointAtTheGuideAndAtTheConfirmation(): void {
        $instructions = ToolPresentation::INSTRUCTIONS;

        $this->assertStringContainsString('"Tool guide"', $instructions);
        $this->assertStringContainsString('confirm=true', $instructions);
        $this->assertStringContainsString('ask whether they really want it done', $instructions);
        $this->assertStringContainsString('never invent an approval_id', $instructions);
    }

    /** The guide is written for the model, so it stays in English and asks to be relayed. */
    public function testTheInstructionsSayTheTextsAreEnglishAndMustBeRelayed(): void {
        $instructions = ToolPresentation::INSTRUCTIONS;

        $this->assertStringContainsString('Tool descriptions, plans and the Tool guide are written in English', $instructions);
        $this->assertStringContainsString('translate what you show them into their language', $instructions);
        $this->assertStringContainsString("Reply in the user's language", $instructions);
        // The instructions themselves are read by the model, so they never follow the rule they state.
        $this->assertDoesNotMatchRegularExpression('/[À-ÿ]/u', $instructions);
    }

    public function testEveryAnswerOpensSayingTheGuideIsInEnglish(): void {
        foreach ([[], ['module' => 'files'], ['tool' => 'files_edit']] as $arguments) {
            $result = $this->guide($arguments);

            $this->assertStringStartsWith(
                'This guide is in English: relay it to the user in their own language.',
                $this->markdown($result),
                json_encode($arguments) . ' must open with the language note',
            );
            $this->assertSame('This guide is in English: relay it to the user in their own language.',
                $result['structuredContent']['language_note']);
        }
    }
}