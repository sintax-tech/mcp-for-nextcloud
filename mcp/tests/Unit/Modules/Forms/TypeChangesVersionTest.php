<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once __DIR__ . '/StructureHarness.php';

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Editing\TypeChanges;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Integration\StructureTarget;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Modules\Forms\Restructuring\StructureFlow;
use OCA\Mcp\Modules\Forms\Restructuring\StructurePlan;
use OCA\Mcp\Modules\Forms\Restructuring\StructureWriter;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

/** A restricted pin refuses type changes before the writer marks an attempt or enters the native controller. */
final class TypeChangesVersionTest extends TestCase {
	/** The preview and confirmation of an older plan both stop before the writer or native lock.
	 * @return void
	 */
	public function testPreviewAndOldConfirmationRefuseBeforeTheWriter(): void {
		$f = new StructureFixture(); $version = '5.4.1';
		// Read the changing installed version for each request, as the real factory does.
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->method('open')->willReturnCallback(static function () use ($f, &$version): EditContext {
			return self::context($f, $version);
		});
		$writer = $this->createMock(StructureWriter::class);
		$writer->expects(self::never())->method('write');
		$states = new StructureState(new StructureTarget());
		$plans = new StructurePlan(new PlanState((new InMemoryConfig())->mock($this)));
		$flow = new StructureFlow($factory, $states, $plans, $writer);
		$op = new TypeChanges(); $args = StructureHarness::arguments()[$op->name()];
		$plan = $flow->preview($op, $args, 'alice');
		$version = '5.4.2';
		foreach ([false, true] as $confirm) {
			try {
				if ($confirm) {
					$flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
				} else {
					$flow->preview($op, $args, 'alice');
				}
				self::fail('Forms 5.4.2 admitted a type-change plan');
			} catch (ToolFailure $error) {
				self::assertSame(StructureMessages::refused(), $error->getMessage());
			}
			self::assertSame([], $f->calls);
			self::assertNull($f->form->fields['lockedBy']);
			self::assertSame(0, $f->form->fields['lockedUntil']);
			self::assertSame('short', $f->questions[2]->data['type']);
		}
	}

	/** Even a writer handed an older effect refuses in pre-call revalidation, while attempted is still false.
	 * @return void
	 */
	public function testWriterRevalidationThrowsWithoutAnAttemptReceipt(): void {
		$f = new StructureFixture(); $op = new TypeChanges();
		$r = $op->request(StructureHarness::arguments()[$op->name()]);
		$states = new StructureState(new StructureTarget());
		$old = self::context($f, '5.4.1'); $current = self::context($f, '5.4.2');
		$s = $states->read($old, 1, 'alice'); $e = $op->effect($old, $r, $s);
		$plans = new StructurePlan(new PlanState((new InMemoryConfig())->mock($this)));
		$plan = $plans->of($op, $r, $old, $s, $e, 'alice');
		$factory = $this->createMock(EditNativeFactory::class);
		$factory->expects(self::once())->method('assertAllowed')->with('alice', $current);
		$throttler = $this->createMock(\OCP\Security\Bruteforce\IThrottler::class);
		$throttler->expects(self::never())->method('registerAttempt');
		$writer = new StructureWriter($factory, $states, $plans,
			new CreateNativeThrottle($throttler, $this->createMock(\OCP\IRequest::class)));
		try {
			$writer->write($op, $current, $r, $s, $e, $plan, 'alice');
			self::fail('Restricted pin returned an attempted-write receipt');
		} catch (ToolFailure $error) {
			// StructureWriter propagates this exception only while its attempted flag is false.
			self::assertSame(StructureMessages::refused(), $error->getMessage());
		}
		self::assertSame([], $f->calls);
		self::assertNull($f->form->fields['lockedBy']);
		self::assertSame(0, $f->form->fields['lockedUntil']);
		self::assertSame(1, $f->form->fields['lastUpdated']);
	}

	/** A proven older pin retains the existing single native update and truthful attempt receipt.
	 * @return void
	 */
	public function test541StillChangesTheType(): void {
		$f = new StructureFixture(); $op = new TypeChanges();
		$args = StructureHarness::arguments()[$op->name()];
		$flow = StructureHarness::flow($this, $f);
		$plan = $flow->preview($op, $args, 'alice');
		$result = $flow->handle($op, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$receipt = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('applied', $receipt['status']);
		self::assertTrue($receipt['nativeCallAttempted']);
		self::assertSame(['updateQuestion'], array_column($f->calls, 0));
		self::assertSame('long', $f->questions[2]->data['type']);
	}

	/** Copies a native-shaped graph with the exact installed pin, without changing collaborator identity.
	 * @param StructureFixture $f Native-shaped test state.
	 * @param string $version Exact version under test.
	 * @return EditContext Per-operation graph.
	 */
	private static function context(StructureFixture $f, string $version): EditContext {
		$c = $f->context();
		return new EditContext($version, $c->controller, $c->config, $c->service, $c->questions, $c->options, $c->shares);
	}
}
