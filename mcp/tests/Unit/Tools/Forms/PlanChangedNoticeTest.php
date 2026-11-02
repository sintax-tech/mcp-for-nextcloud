<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms;

require_once __DIR__ . '/Editing/EditingFixture.php';

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;
use OCA\Mcp\Modules\Forms\Creation\CreateFormFlow;
use OCA\Mcp\Modules\Forms\Presentation\CreateFormMessages;
use OCA\Mcp\Modules\Forms\Creation\CreateFormPlan;
use OCA\Mcp\Modules\Forms\Creation\CreateFormPlanRenderer;
use OCA\Mcp\Modules\Forms\Presentation\CreateFormTools;
use OCA\Mcp\Modules\Forms\Creation\CreateFormWriter;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory;
use OCA\Mcp\Tests\Unit\Tools\Forms\Editing\EditingFixture;
use OCA\Mcp\Modules\Forms\Editing\EditFlow;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Editing\EditPlan;
use OCA\Mcp\Modules\Forms\Editing\EditPlanRenderer;
use OCA\Mcp\Modules\Forms\Integration\EditState;
use OCA\Mcp\Modules\Forms\Presentation\EditTools;
use OCA\Mcp\Modules\Forms\Editing\EditWriter;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Tools\ToolModule;
use PHPUnit\Framework\TestCase;

/**
 * A Forms plan that no longer matches names what changed, and the creation warning names the installed version.
 */
final class PlanChangedNoticeTest extends TestCase {
	/**
	 * The creation warning quotes 5.4.0 and 5.4.1 as different sentences, each with its own version.
	 *
	 * @return void
	 */
	public function testTheCreationWarningNamesTheInstalledVersion(): void {
		$old = $this->preview('5.4.0');
		$new = $this->preview('5.4.1');
		self::assertNotSame(CreateFormMessages::warning('5.4.0'), CreateFormMessages::warning('5.4.1'));
		self::assertStringContainsString('5.4.0', $old['text']);
		self::assertStringNotContainsString('5.4.1', $old['text']);
		self::assertStringContainsString('does not rename', $old['note']);
		self::assertStringContainsString('5.4.1', $new['text']);
		self::assertStringContainsString('rename or merge', $new['note']);
		self::assertStringNotContainsString('does not rename', $new['note']);
		self::assertStringContainsString('Forms version', $old['text']);
		self::assertStringContainsString('Forms version', $new['text']);
	}

	/**
	 * A Forms version that moves between the plan and the confirmation is named in the warning.
	 *
	 * @return void
	 */
	public function testAChangedFormsVersionIsNamedInTheWarning(): void {
		$version = '5.4.0';
		$factory = $this->createMock(FormsCreateNativeFactory::class);
		$factory->method('open')->willReturnCallback(function () use (&$version): CreateFormContext {
			return $this->context($version);
		});
		$writer = $this->createMock(CreateFormWriter::class);
		$writer->expects(self::never())->method('write');
		$flow = new CreateFormFlow($factory, new CreateFormPlan(new PlanState((new InMemoryConfig())->mock($this))), $writer);
		$args = ['title' => 'Cadastro'];
		$plan = $flow->preview($args, 'alice');
		$before = PlanRenderer::render($this->module(), CreateFormTools::TOOL, $plan);
		self::assertStringNotContainsString('### ', $before);
		self::assertStringContainsString('5.4.0', $before);
		$version = '5.4.1';
		try {
			$flow->handle($args + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
			self::fail('the old Forms version was confirmed');
		} catch (PlanChanged $changed) {
			$message = $changed->plan['warnings'][0]['message'] ?? '';
			self::assertNotSame('', $message);
			self::assertStringContainsString('nativeVersion', $message);
			self::assertStringContainsString('5.4.1', $message);
			$text = PlanRenderer::render($this->module(), CreateFormTools::TOOL, $changed->plan);
			self::assertStringContainsString('### ', $text);
			self::assertStringContainsString('Warnings', $text);
			self::assertStringContainsString('nativeVersion', $text);
			self::assertStringContainsString('5.4.1', $text);
			self::assertNotSame($before, $text);
		}
	}

	/**
	 * A question description that moves outside the requested cells is named in the warning.
	 *
	 * @return void
	 */
	public function testAChangedQuestionDescriptionIsNamedInTheWarning(): void {
		$fixture = new EditingFixture();
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->method('open')->willReturnCallback(static fn (): \OCA\Mcp\Modules\Forms\Editing\EditContext => $fixture->context());
		$plans = new EditPlan(new PlanState((new InMemoryConfig())->mock($this)));
		$throttle = new CreateNativeThrottle($this->createMock(\OCP\Security\Bruteforce\IThrottler::class), $this->createMock(\OCP\IRequest::class));
		$flow = new EditFlow($factory, new EditState(), $plans, new EditWriter($factory, new EditState(), $plans, $throttle));
		$args = ['form_id' => 1, 'question_id' => 2, 'changes' => ['text' => 'Email']];
		$plan = $flow->preview($args, 'alice', false);
		$before = PlanRenderer::render($this->module(), EditTools::name(false), $plan);
		self::assertStringNotContainsString('Recibo interno', $before);
		$fixture->question->data['description'] = 'Recibo interno';
		try {
			$flow->handle($args + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice', false);
			self::fail('the old question description was confirmed');
		} catch (PlanChanged $changed) {
			$message = $changed->plan['warnings'][0]['message'] ?? '';
			self::assertNotSame('', $message);
			self::assertStringContainsString('description', $message);
			self::assertStringContainsString('Recibo interno', $message);
			self::assertStringNotContainsString('Recibo interno', json_encode($changed->plan['before']));
			self::assertStringNotContainsString('Recibo interno', json_encode($changed->plan['after']));
			$body = (new EditPlanRenderer())->render($changed->plan);
			self::assertStringContainsString('Recibo interno', $body);
			$text = PlanRenderer::render($this->module(), EditTools::name(false), $changed->plan);
			self::assertStringContainsString('### ', $text);
			self::assertStringContainsString('Warnings', $text);
			self::assertStringContainsString('description', $text);
			self::assertStringContainsString('Recibo interno', $text);
			self::assertNotSame($before, $text);
			self::assertSame([], $fixture->calls);
		}
	}

	/**
	 * A description of 350 identical characters whose only change is the suffix stays visible after the envelope cut.
	 *
	 * @return void
	 */
	public function testAChangedDescriptionSuffixStaysVisible(): void {
		$fixture = new EditingFixture();
		$fixture->question->data['description'] = str_repeat('a', 350) . 'OLD_SUFFIX';
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->method('open')->willReturnCallback(static fn (): \OCA\Mcp\Modules\Forms\Editing\EditContext => $fixture->context());
		$plans = new EditPlan(new PlanState((new InMemoryConfig())->mock($this)));
		$throttle = new CreateNativeThrottle($this->createMock(\OCP\Security\Bruteforce\IThrottler::class), $this->createMock(\OCP\IRequest::class));
		$flow = new EditFlow($factory, new EditState(), $plans, new EditWriter($factory, new EditState(), $plans, $throttle));
		$args = ['form_id' => 1, 'question_id' => 2, 'changes' => ['required' => true]];
		$plan = $flow->preview($args, 'alice', false);
		$fixture->question->data['description'] = str_repeat('a', 350) . 'NEW_SUFFIX';
		try {
			$flow->handle($args + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice', false);
			self::fail('the old question description was confirmed');
		} catch (PlanChanged $changed) {
			self::assertStringContainsString('NEW_SUFFIX', $changed->plan['warnings'][0]['message']);
			$body = (new EditPlanRenderer())->render($changed->plan);
			self::assertStringContainsString('NEW\_SUFFIX', $body);
			$text = PlanRenderer::render($this->module(), EditTools::name(false), $changed->plan);
			$warnings = strstr($text, '### ');
			self::assertIsString($warnings);
			self::assertStringNotContainsString('SUFFIX', $warnings);
			self::assertStringContainsString($body, $text);
			self::assertSame([], $fixture->calls);
		}
	}

	/**
	 * One inert creation preview for an explicit Forms version.
	 *
	 * @param string $version Installed Forms release.
	 * @return array{note: string, text: string} The warning note and the rendered plan.
	 */
	private function preview(string $version): array {
		$factory = $this->createMock(FormsCreateNativeFactory::class);
		$factory->method('open')->willReturn($this->context($version));
		$writer = $this->createMock(CreateFormWriter::class);
		$writer->expects(self::never())->method('write');
		$flow = new CreateFormFlow($factory, new CreateFormPlan(new PlanState((new InMemoryConfig())->mock($this))), $writer);
		$plan = $flow->preview(['title' => 'Cadastro'], 'alice');
		return [
			'note' => (string)$plan['notes'][0],
			'text' => PlanRenderer::render($this->module(), CreateFormTools::TOOL, $plan),
		];
	}

	/**
	 * A unit context whose only live method is the settings check the flow calls before planning.
	 *
	 * @param string $version Installed Forms release.
	 * @return CreateFormContext Inert graph.
	 */
	private function context(string $version): CreateFormContext {
		$service = new class {
			/**
			 * Accepts the isolated unit settings boundary.
			 *
			 * @param array<string, mixed> $settings Validated settings.
			 * @param string $type Supported type.
			 * @return bool Native-style validation outcome.
			 */
			public function areExtraSettingsValid(array $settings, string $type): bool {
				return true;
			}
		};
		return new CreateFormContext($version, new \stdClass(), new \stdClass(), $service, new \stdClass(), new \stdClass(), new \stdClass(), new \stdClass());
	}

	/**
	 * A module that only renders the Forms plan bodies under test.
	 *
	 * @return ToolModule Renderer stand-in.
	 */
	private function module(): ToolModule {
		return new class implements ToolModule, RendersPlans {
			/** @return list<array<string, mixed>> */
			public function definitions(): array {
				return [];
			}

			/**
			 * @param string $name Tool name.
			 * @param array<string, mixed> $arguments Call arguments.
			 * @param string $userId Actor.
			 * @return array{content: list<array{type: string, text: string}>} Unused.
			 */
			public function call(string $name, array $arguments, string $userId): array {
				return ['content' => []];
			}

			/**
			 * @param string $tool Tool name.
			 * @param array<string, mixed> $plan Structured plan.
			 * @return string|null Readable body.
			 */
			public function renderPlan(string $tool, array $plan): ?string {
				if ($tool === EditTools::name(false)) {
					return (new EditPlanRenderer())->render($plan);
				}
				return (new CreateFormPlanRenderer())->render($plan);
			}
		};
	}
}
