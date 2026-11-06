<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarApprovalTest;
use OCA\Mcp\Tests\Unit\Tools\Contacts\ContactsPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Files\FilesPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Mail\Plans\MailPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Tables\Write\TablesPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Talk\TalkPlanRendererTest;
use OCA\Mcp\Tests\Unit\Tools\Tasks\TasksPlanRendererTest;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
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
	private const READ_ONLY = [\OCA\Mcp\Modules\People\PeopleModule::class, \OCA\Mcp\Modules\Logs\LogsModule::class];

	/** @return array<class-string, class-string> test class of each writing module, which runs the contract with that module's fakes */
	private static function contractTests(): array {
		return [
			\OCA\Mcp\Modules\Polls\PollsModule::class => \OCA\Mcp\Tests\Unit\Tools\Polls\PollsCreatePlanRendererTest::class,
			\OCA\Mcp\Tools\Files\FilesModule::class => FilesPlanRendererTest::class,
			\OCA\Mcp\Modules\Notes\NotesModule::class => NotesPlanRendererTest::class,
			\OCA\Mcp\Tools\Deck\DeckToolModule::class => DeckPlanRendererTest::class,
			\OCA\Mcp\Modules\Talk\TalkModule::class => TalkPlanRendererTest::class,
			\OCA\Mcp\Modules\Contacts\ContactsModule::class => ContactsPlanRendererTest::class,
			\OCA\Mcp\Modules\Tasks\TasksModule::class => TasksPlanRendererTest::class,
			\OCA\Mcp\Modules\Calendar\CalendarModule::class => CalendarApprovalTest::class,
			\OCA\Mcp\Modules\Mail\MailModule::class => MailPlanRendererTest::class,
			\OCA\Mcp\Modules\Tables\TablesModule::class => TablesPlanRendererTest::class,
			\OCA\Mcp\Modules\Forms\FormsModule::class => \OCA\Mcp\Tests\Unit\Tools\Forms\Creation\CreatePlanRendererTest::class,
		];
	}

	public function testEveryRegisteredModuleIsReadOnlyOrCoveredByTheContract(): void {
		$covered = self::contractTests();
		foreach (Application::MODULES as $module) {
			if (in_array($module, self::READ_ONLY, true)) {
				self::assertFalse(self::writes($module), $module . ' publishes a write, so it is not read-only');
				continue;
			}
			self::assertArrayHasKey($module, $covered, $module . ' is registered but has no readability contract test');
		}
	}

	/** A catalog module is read-only by its catalog, since its base always answers previews; a legacy module by its interfaces. */
	public function testReadOnlyIsDecidedByTheCatalogOfACatalogModule(): void {
		self::assertFalse(self::writes(\OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\RegisteredProbeModule::class));
		self::assertTrue(self::writes(\OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\ProbeModule::class));
		self::assertTrue(self::writes(\OCA\Mcp\Modules\Notes\NotesModule::class));
	}

	/**
	 * @param class-string $module registered module
	 * @return bool whether it publishes a write: a write tool in its catalog, or the preview interface of a legacy module
	 */
	private static function writes(string $module): bool {
		if (is_subclass_of($module, CatalogModule::class)) {
			return array_filter($module::toolDefinitions(), static fn ($definition): bool => WriteGate::isWrite($definition->toArray())) !== [];
		}
		return is_subclass_of($module, PreviewsWrites::class);
	}

	public function testEveryWritingModuleDeclaresItsPlanText(): void {
		foreach (self::contractTests() as $module => $test) {
			self::assertTrue(is_subclass_of($module, RendersPlans::class), $module . ' must implement RendersPlans');
			self::assertTrue(is_subclass_of($module, PreviewsWrites::class), $module . ' must implement PreviewsWrites');
			self::assertContains(AssertsReadablePlans::class, class_uses($test) ?: [], $test . ' must run the readability contract');
		}
	}

	/**
	 * Writes whose plan shows a name the person or the model chose, and that are covered by a plan the module really
	 * produced for a hostile name ({@see AssertsReadablePlans::assertHostileNameStaysInert()}), by tool and test.
	 */
	private const HOSTILE_NAME_TESTS = [
		'files_upload' => [FilesPlanRendererTest::class, 'testHostileNamesOfNewFilesStayInert'],
		'files_create' => [FilesPlanRendererTest::class, 'testHostileNamesOfNewFilesStayInert'],
	];

	/** The new file tools are named here, so the test that proves their names inert cannot disappear unnoticed. */
	public function testTheNewFileToolsHaveAHostileNameTest(): void {
		$files = (new \ReflectionClass(\OCA\Mcp\Tools\Files\FilesModule::class))->newInstanceWithoutConstructor();
		$writes = array_column(array_filter($files->definitions(), static fn (array $d): bool => \OCA\Mcp\Mcp\Confirmation\WriteGate::isWrite($d)), 'name');
		foreach (self::HOSTILE_NAME_TESTS as $tool => [$test, $method]) {
			self::assertContains($tool, $writes, $tool . ' is no longer a write of the Files module');
			self::assertTrue(method_exists($test, $method), $test . '::' . $method . ' is missing');
			self::assertStringContainsString('assertHostileNameStaysInert',
				(string)file_get_contents((string)(new \ReflectionMethod($test, $method))->getFileName()), $test);
		}
	}

	public function testNoCoveredModuleIsMissingFromTheApplication(): void {
		self::assertSame([], array_values(array_diff(array_keys(self::contractTests()), Application::MODULES)));
	}
}
