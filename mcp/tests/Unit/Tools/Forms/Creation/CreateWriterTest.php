<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

use OCA\Mcp\Tools\Forms\Creation\{CreateFormContext,CreateFormReadback,CreateFormRequest,CreateFormWriter,CreateNativeThrottle,FormsCreateNativeFactory,UploadFolderPrecheck};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/** Discriminates sinks versus readback, partial native batches, prechecks and stage revalidation. */
final class CreateWriterTest extends TestCase {
	/** Confirms an observed native-style full sequence and only newly generated identifiers.
	 * @return void
	 */
	public function testCompleteAndPartial201Batch(): void {
		foreach ([false, true] as $partial) {
			$state = new CreateNativeState(); $state->partialOptions = $partial;
			[$writer, $context] = $this->writer($state);
			$result = $writer->write($context, $this->request(), 'alice');
			self::assertSame($partial ? 'unconfirmed' : 'complete', $result['status']);
			self::assertSame(['form', 'metadata', 'question', 'settings', 'options'], $state->calls);
			self::assertSame($partial ? ['A', 'C'] : ['A', 'B', 'C'], array_column($result['applied']['questions'][0]['options'], 'text'));
			self::assertStringNotContainsString('ownerId', json_encode($result)); self::assertStringNotContainsString('native-capability', json_encode($result['applied']['questions']));
			self::assertStringContainsString('native-capability', $result['applied']['editorUrl']);
		}
	}
	/** Refuses known folder matches after S0 without invoking title or following stages or exposing Files data.
	 * @return void
	 */
	public function testCollisionAndOlderPinControl(): void {
		foreach (['5.4.1', '5.3.7'] as $version) {
			$state = new CreateNativeState(); $state->collisions = ['secret-file-name'];
			[$writer, $context] = $this->writer($state, null, $version);
			$result = $writer->write($context, $this->request(), 'alice');
			self::assertSame($version === '5.4.1' ? 'partial' : 'complete', $result['status']);
			if ($version === '5.4.1') { self::assertSame(['form'], $state->calls); self::assertSame('', $result['applied']['title']); self::assertFalse($result['nativeCallAttempted']); }
			self::assertStringNotContainsString('secret-file-name', json_encode($result));
		}
	}
	/** Does not infer zero effect from failures after native entry, nor leak native diagnostics.
	 * @return void
	 */
	public function testPostSinkFailureHasNoAutomaticRetryOrFalseReceipt(): void {
		foreach (['form', 'metadata', 'question', 'settings', 'options'] as $stage) {
			$state = new CreateNativeState(); $state->throwAfter = $stage; [$writer, $context] = $this->writer($state);
			$result = $writer->write($context, $this->request(), 'alice'); self::assertSame('unconfirmed', $result['status']); self::assertTrue($result['nativeCallAttempted']);
			self::assertCount(1, array_filter($state->calls, static fn (string $call): bool => $call === $stage));
			self::assertStringNotContainsString('secret DB', json_encode($result));
			if ($stage === 'form') { self::assertSame([], $result['applied']); self::assertNotNull($state->form); }
			if ($stage === 'metadata') { self::assertSame('', $result['applied']['title']); self::assertSame('Form', $state->form->data['title']); }
		}
	}
	/** Preserves conflict refusal before a subsequent sink when the already observed prefix changes.
	 * @return void
	 */
	public function testConcurrentChangeAndRenewedGrantRefusal(): void {
		foreach (['change', 'deny'] as $mode) {
			$state = new CreateNativeState();
			$check = static function () use ($state, $mode): void {
				if ($state->calls === ['form']) {
					if ($mode === 'deny') { throw new ToolFailure('Denied'); }
					$state->form->data['title'] = 'Concurrent';
				}
			};
			[$writer, $context] = $this->writer($state, $check); $result = $writer->write($context, $this->request(), 'alice');
			self::assertNotSame('complete', $result['status']); self::assertSame(['form'], $state->calls);
		}
	}
	/** Detects a changed already-observed prefix immediately before question creation.
	 * @return void
	 */
	public function testChangedPrefixBeforeQuestionIsPartialWithoutAttempt(): void {
		foreach (['title', 'state', 'shares', 'owner', 'lock'] as $mode) {
		$state = new CreateNativeState(); $checks = 0;
		$check = static function () use ($state, &$checks, $mode): void {
			$checks++;
			if ($checks === 6) {
				switch ($mode) {
					case 'title': $state->form->data['title'] = 'Concurrent change'; break;
					case 'state': $state->form->data['state'] = 1; break;
					case 'shares': $state->shares = [['synthetic' => true]]; break;
					case 'owner': $state->form->setOwnerId('bob'); break;
					case 'lock': $state->form->data['lockedUntil'] = 999999; break;
				}
			}
		};
		[$writer, $context] = $this->writer($state, $check);
		$result = $writer->write($context, $this->request(), 'alice');
		self::assertSame(['form', 'metadata'], $state->calls);
		self::assertSame('partial', $result['status']); self::assertFalse($result['nativeCallAttempted']);
		self::assertSame('questions.0.create', $result['interruptedStage']);
		self::assertSame('Form', $result['applied']['title']); self::assertSame([], $state->questions);
		}
	}
	/** Builds a writer with actual readback/throttle but explicit synthetic native collaborators.
	 * @param CreateNativeState $state Native-shaped mutable fixture.
	 * @param callable|null $check Optional admission change between stages.
	 * @param string $version Exact pin controlling the native title precheck.
	 * @return array{CreateFormWriter,CreateFormContext} Isolated writer and context.
	 */
	private function writer(CreateNativeState $state, ?callable $check = null, string $version = '5.4.1'): array {
		$factory = $this->createMock(FormsCreateNativeFactory::class);
		if ($check !== null) { $factory->method('assertAllowed')->willReturnCallback($check); }
		$urls = $this->createMock(\OCP\IURLGenerator::class); $urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $args): string => '/native/' . $args['hash'] . '/edit');
		$throttler = $this->createMock(\OCP\Security\Bruteforce\IThrottler::class); $request = $this->createMock(\OCP\IRequest::class); $request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$context = new CreateFormContext($version, $state, $state, $state, $state, $state, $state, $state);
		return [new CreateFormWriter($factory, new CreateFormReadback($urls), new UploadFolderPrecheck(), new CreateNativeThrottle($throttler, $request)), $context];
	}
	/** Returns a request whose option middle failure is observable as a non-prefix subset.
	 * @return array<string,mixed> Validated aggregate request.
	 */
	private function request(): array { return CreateFormRequest::of(['title' => 'Form', 'questions' => [['type' => 'dropdown', 'text' => 'Choice', 'options' => ['A', 'B', 'C']]]]); }
}
