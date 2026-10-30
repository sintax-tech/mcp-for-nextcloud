<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Forms\Creation\{CreateFormContext,CreateFormFlow,CreateFormMessages,CreateFormPlan,CreateFormPlanRenderer,CreateFormWriter,FormsCreateNativeFactory};
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Tests preview/confirmation boundaries without mistaking doubles for native actor-capture runtime proof. */
final class CreateFlowTest extends TestCase {
	/** Binds the full structure/warning/actor/version; preview and drift never reach the writer.
	 * @return void
	 */
	public function testPreviewFullInertPlanAndChangedConfirmation(): void {
		$context = $this->context('5.4.1');
		$factory = $this->createMock(FormsCreateNativeFactory::class); $factory->method('open')->willReturn($context);
		$writer = $this->createMock(CreateFormWriter::class); $writer->expects(self::never())->method('write');
		$plans = new CreateFormPlan(new PlanState((new InMemoryConfig())->mock($this)));
		$flow = new CreateFormFlow($factory, $plans, $writer);
		$args = ['title' => "<script>\n# hacked", 'questions' => [['type' => 'short', 'text' => 'Name', 'validation' => 'email']]];
		$preview = $flow->preview($args, 'alice'); self::assertNull($preview['before']);
		self::assertSame($args['title'], $preview['after']['title']); self::assertContains(CreateFormMessages::warning(), $preview['notes']);
		$body = (new CreateFormPlanRenderer())->render($preview);
		self::assertStringNotContainsString('<script>', $body); self::assertStringNotContainsString("\n# hacked", $body); self::assertStringContainsString('Name', $body);
		try { $flow->handle($args + ['confirm' => true, 'plan_state' => $flow->preview($args, 'bob')['plan_state']], 'alice'); self::fail('Cross-actor state accepted'); }
		catch (PlanChanged $error) { self::assertSame($preview['after'], $error->plan['after']); }
		$args['title'] = 'Changed';
		$this->expectException(PlanChanged::class); $flow->handle($args + ['confirm' => true, 'plan_state' => $preview['plan_state']], 'alice');
	}
	/** Refuses direct calls without confirmation and incomplete argument validation before native graph admission.
	 * @return void
	 */
	public function testUnconfirmedNeverWrites(): void {
		$factory = $this->createMock(FormsCreateNativeFactory::class); $factory->expects(self::never())->method('open');
		$writer = $this->createMock(CreateFormWriter::class); $writer->expects(self::never())->method('write');
		$flow = new CreateFormFlow($factory, new CreateFormPlan(new PlanState((new InMemoryConfig())->mock($this))), $writer);
		$this->expectException(ToolFailure::class); $flow->handle(['title' => 'x'], 'alice');
	}
	/** Builds only an inert preflight double, not a native ApiController identity attestation.
	 * @param string $version Explicit version used in plan_state.
	 * @return CreateFormContext Unit context with no native write methods.
	 */
	private function context(string $version): CreateFormContext {
		$service = new class {
			/** Accepts only the isolated unit settings boundary.
			 * @param array $settings Validated settings.
			 * @param string $type Supported type.
			 * @return bool Native-style validation outcome.
			 */
			public function areExtraSettingsValid(array $settings, string $type): bool { return true; }
		};
		return new CreateFormContext($version, new \stdClass(), new \stdClass(), $service, new \stdClass(), new \stdClass(), new \stdClass(), new \stdClass());
	}
}
