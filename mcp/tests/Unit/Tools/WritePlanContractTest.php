<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarApprovalTest;
use OCA\Mcp\Tests\Unit\Tools\Contacts\ContactsPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Files\FilesPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Talk\TalkPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Tasks\TasksPlanRendererTest;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use PHPUnit\Framework\TestCase;

/**
 * No write tool of any module may reach a person as a generic field list.
 *
 * Each writing module declares {@see RendersPlans}, and its own test (built with that module's fakes) renders the
 * plan of every one of its write tools through {@see AssertsReadablePlans}. This test closes the loop: every module
 * the app registers is either on the short list of read-only modules or is covered by such a test, so a new module
 * cannot be added without the contract.
 */
final class WritePlanContractTest extends TestCase {
    /** Registered modules that publish no write tool. */
    private const READ_ONLY = [\OCA\Mcp\Tools\People\PeopleModule::class];

    /** @return array<class-string, class-string> test class of each writing module, which runs the contract with that module's fakes */
    private static function contractTests(): array {
        return [
            \OCA\Mcp\Tools\Files\FilesModule::class => FilesPlanRendererTest::class,
            \OCA\Mcp\Tools\Notes\NotesModule::class => NotesPlanRendererTest::class,
            \OCA\Mcp\Tools\Deck\DeckToolModule::class => DeckPlanRendererTest::class,
            \OCA\Mcp\Tools\Talk\TalkModule::class => TalkPlanRendererTest::class,
            \OCA\Mcp\Tools\Contacts\ContactsModule::class => ContactsPlanRendererTest::class,
            \OCA\Mcp\Tools\Tasks\TasksModule::class => TasksPlanRendererTest::class,
            \OCA\Mcp\Tools\Calendar\CalendarModule::class => CalendarApprovalTest::class,
        ];
    }

    public function testEveryRegisteredModuleIsReadOnlyOrCoveredByTheContract(): void {
        $covered = self::contractTests();
        foreach (Application::MODULES as $module) {
            if (in_array($module, self::READ_ONLY, true)) {
                self::assertFalse(is_subclass_of($module, PreviewsWrites::class), $module . ' previews writes, so it is not read-only');
                continue;
            }
            self::assertArrayHasKey($module, $covered, $module . ' is registered but has no readability contract test');
        }
    }

    public function testEveryWritingModuleDeclaresItsPlanText(): void {
        foreach (self::contractTests() as $module => $test) {
            self::assertTrue(is_subclass_of($module, RendersPlans::class), $module . ' must implement RendersPlans');
            self::assertTrue(is_subclass_of($module, PreviewsWrites::class), $module . ' must implement PreviewsWrites');
            self::assertContains(AssertsReadablePlans::class, class_uses($test) ?: [], $test . ' must run the readability contract');
        }
    }

    public function testNoCoveredModuleIsMissingFromTheApplication(): void {
        self::assertSame([], array_values(array_diff(array_keys(self::contractTests()), Application::MODULES)));
    }
}
