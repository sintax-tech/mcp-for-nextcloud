<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms;

use OCA\Mcp\Modules\Forms\Creation\{CreateFormFlow, CreateFormHandler};
use OCA\Mcp\Modules\Forms\Editing\{EditFlow, EditQuestionHandler, EditOptionHandler};
use OCA\Mcp\Modules\Forms\Integration\{FormsServices, FormFieldWhitelist, FormDirectLinks, PublicLinkShaper, FormCandidates, FormReauthorizer, ResultsReadFlow};
use OCA\Mcp\Modules\Forms\Presentation\FormStructureShape;
use OCA\Mcp\Modules\Forms\Reading\{ListFormsHandler, ReadFormHandler, ListSubmissionsHandler, ReadSubmissionHandler};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolPresentation;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FormsFixture.php';

/** Deterministic module-boundary oracle, paired with the existing real native admission/effects contracts. */
final class FormsCharacterizationTest extends TestCase {
	private array $events = [];

	public function testTheMovedModuleKeepsEveryCallAndPlan(): void {
		$actual = [];
		foreach (['success', 'failure'] as $mode) {
			$this->events = [];
			$module = $this->module($mode);
			foreach ($module->definitions() as $definition) {
				$name = $definition['name'];
				$arguments = ['form_id' => 5, 'question_id' => 9, 'option_id' => 2, 'title' => 'A hostile <script>', 'changes' => ['text' => 'new'], 'confirm' => true, 'plan_state' => 'fixed'];
				$actual[$mode][$name]['call'] = $this->capture(fn () => $module->call($name, $arguments, 'bob'));
				if ($definition['operation'] !== 'read') {
					$actual[$mode][$name]['preview'] = $this->capture(fn () => $module->preview($name, $arguments, 'bob'));
				}
			}
			$actual[$mode]['events'] = $this->events;
			$actual[$mode]['guide'] = $module->guideNotes();
		}
		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$path = __DIR__ . '/forms-characterization.json';
		if (getenv('MCP_RE18_RECORD') === '1') { file_put_contents($path, $json); }
		self::assertSame(file_get_contents($path), $json);
	}

	private function capture(callable $call): array {
		try { return ['result' => $call()]; }
		catch (\Throwable $error) {
			$chain = [];
			do { $chain[] = [get_class($error), $error->getMessage(), $error->getCode()]; } while ($error = $error->getPrevious());
			return ['exceptions' => $chain];
		}
	}

	private function module(string $mode): object {
		$record = function (string $kind, array $args, string $uid, ?bool $option = null) use ($mode): array {
			$this->events[] = [$kind, $args, $uid, $option];
			if ($mode === 'failure') { throw new ToolFailure('native refusal', 13, new \RuntimeException('native cause', 7)); }
			return ['kind' => $kind, 'actor' => $uid, 'option' => $option, 'arguments' => $args];
		};
		$create = $this->createMock(CreateFormFlow::class);
		foreach (['preview', 'handle'] as $method) { $create->method($method)->willReturnCallback(fn ($args, $uid) => $record('create.' . $method, $args, $uid)); }
		$edit = $this->createMock(EditFlow::class);
		foreach (['preview', 'handle'] as $method) { $edit->method($method)->willReturnCallback(fn ($args, $uid, $option) => $record('edit.' . $method, $args, $uid, $option)); }
		$results = $this->createMock(ResultsReadFlow::class);
		$results->method('run')->willReturnCallback(fn ($args, $uid, $list) => $record('results', $args, $uid, $list));
		$native = new FormsFixture('bob'); $native->forms[5] = FormsFixture::form(5, 'bob');
		$services = $this->createMock(FormsServices::class);
		$user = $this->createMock(\OCP\IUser::class); $user->method('getUID')->willReturn('bob');
		$services->method('open')->willReturnCallback(function ($uid) use ($native, $user, $mode): array {
			$this->events[] = ['open', $uid];
			if ($mode === 'failure') { throw new ToolFailure('native refusal', 13, new \RuntimeException('native cause', 7)); }
			return ['service' => $native, 'mapper' => $native, 'user' => $user, 'version' => '5.4.1'];
		});
		$urls = $this->createMock(\OCP\IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn ($route, $args) => 'https://cloud.test/' . $route . '?' . http_build_query($args));
		$shape = new FormFieldWhitelist(new FormDirectLinks($urls), new PublicLinkShaper($urls), new FormStructureShape());
		$config = $this->createMock(\OCP\IConfig::class); $config->method('getSystemValueString')->willReturn('characterization secret');
		return FormsTestModule::build(
			new ListFormsHandler($services, new FormCandidates(new FormReauthorizer()), $shape, new PlanState($config)),
			new ReadFormHandler($services, $shape), new ListSubmissionsHandler($results), new ReadSubmissionHandler($results),
			new CreateFormHandler($create), new EditQuestionHandler($edit), new EditOptionHandler($edit),
		);
	}
}
