<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms;

use OCA\Mcp\Modules\Forms\Integration\FormCandidates;
use OCA\Mcp\Modules\Forms\Integration\FormListOrder;
use OCA\Mcp\Modules\Forms\Integration\FormReauthorizer;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Modules\Forms\Reading\ListFormsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadFormHandler;
use OCA\Mcp\Modules\Forms\Integration\FormDirectLinks;
use OCA\Mcp\Modules\Forms\Integration\PublicLinkShaper;
use OCA\Mcp\Modules\Forms\Integration\FormFieldWhitelist;
use OCA\Mcp\Modules\Forms\Presentation\FormStructureShape;
use OCA\Mcp\Modules\Forms\Presentation\FormsResponseBudget;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FormsFixture.php';

/** Exercises post-fetch limits through handlers and budget edges, without allocating a hundred thousand native entities. */
final class FormsResponseLimitsTest extends TestCase {
	/** Reports field/list cuts through the real read handler, preserving the native entity and identity digest.
	 * @return void
	 */
	public function testHandlerReportsFieldAndSettingsCuts(): void {
		$service = new FormsFixture('bob'); $form = FormsFixture::form(5, 'bob'); $service->forms[5] = $form;
		$form->data['title'] = str_repeat('é', 8001);
		$form->data['questions'][0]['extraSettings']['allowedFileExtensions'] = array_fill(0, 201, 'txt');
		$before = $form->data; $digest = FormListOrder::digest([$form]);
		$data = $this->json((new ReadFormHandler($this->services($service), $this->shape()))->handle(['form_id' => 5], 'bob'));
		self::assertSame(8000, mb_strlen($data['untrusted']['form']['title'], 'UTF-8'));
		self::assertContains(['path' => 'form.title', 'totalChars' => 8001], $data['truncation']['fields']);
		self::assertSame([['path' => 'form.questions.0.extraSettings.allowedFileExtensions', 'totalItems' => 201]], $data['truncation']['lists']);
		self::assertCount(200, $data['untrusted']['form']['questions'][0]['extraSettings']['allowedFileExtensions']);
		self::assertSame(5, $data['untrusted']['form']['id']); self::assertSame(9, $data['untrusted']['form']['questions'][0]['id']);
		self::assertSame($before, $form->data); self::assertSame($digest, FormListOrder::digest([$form]));
	}

	/** Shares a text budget across forms but preserves identifiers, permissions and credential URLs exactly.
	 * @return void
	 */
	public function testTotalTextBudgetAndUncutControl(): void {
		$forms = array_fill(0, 13, ['id' => 7, 'title' => str_repeat('x', 8000), 'permissions' => ['edit'], 'links' => ['direct' => ['submit' => 'https://example.test/credential']]]);
		$data = FormsResponseBudget::envelope(['forms' => $forms]);
		self::assertSame(100000, array_sum(array_map(static fn (array $form): int => strlen($form['title']), $data['untrusted']['forms'])));
		self::assertSame([['path' => 'forms.12.title', 'totalChars' => 8000]], $data['truncation']['fields']);
		self::assertSame($forms[12]['links'], $data['untrusted']['forms'][12]['links']); self::assertSame($forms[12]['permissions'], $data['untrusted']['forms'][12]['permissions']);
		$small = ['id' => 9, 'title' => 'Complete', 'lockedBy' => str_repeat('a', 8001), 'questions' => [['id' => 2, 'name' => str_repeat('n', 8001), 'text' => 'Complete', 'extraSettings' => ['allowedFileExtensions' => array_fill(0, 200, 'txt')], 'options' => []]]];
		$control = FormsResponseBudget::envelope(['form' => $small]);
		self::assertSame($small, $control['untrusted']['form']); self::assertSame(['fields' => [], 'lists' => []], $control['truncation']);
	}

	/** Keeps exactly the complete structure limit, refusing the whole next structure rather than dropping an option.
	 * @return void
	 */
	public function testCompleteStructureBoundaryAndRefusal(): void {
		$form = ['id' => 1, 'questions' => [['id' => 2, 'text' => 'Question', 'options' => array_fill(0, 1999, ['id' => 3, 'text' => 'Option'])]]];
		$kept = FormsResponseBudget::envelope(['form' => $form]);
		self::assertCount(1999, $kept['untrusted']['form']['questions'][0]['options']);
		$form['questions'][0]['options'][] = ['id' => 4, 'text' => 'Last option'];
		try { FormsResponseBudget::envelope(['form' => $form]); self::fail('Incomplete structure must not escape'); }
		catch (ToolFailure $error) {
			self::assertStringContainsString('response limit', $error->getMessage());
			self::assertStringNotContainsString('context', $error->getMessage());
			self::assertStringContainsString('No partial question or option structure', $error->getMessage());
		}
		self::assertCount(2000, $form['questions'][0]['options'], 'Input remains intact');
	}

	/** Refuses oversized serialized protected data instead of cutting a credential or identity to fit.
	 * @return void
	 */
	public function testSerializedPayloadLimit(): void {
		$form = ['id' => 1, 'links' => ['direct' => ['submit' => 'https://example.test/' . str_repeat('a', FormsResponseBudget::RESPONSE_BYTES)]]];
		$this->expectException(ToolFailure::class); $this->expectExceptionMessage('response limit');
		FormsResponseBudget::envelope(['form' => $form]);
	}

	/** Proves the list handler passes the page-specific payload refusal instead of the form-structure instruction.
	 * @return void
	 */
	public function testOversizedListPayloadSuggestsReducingLimit(): void {
		$service = new FormsFixture('bob'); $service->forms[1] = FormsFixture::form(1, 'bob');
		$urls = $this->createMock(\OCP\IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://example.test/' . str_repeat('a', FormsResponseBudget::RESPONSE_BYTES));
		$shape = new FormFieldWhitelist(new FormDirectLinks($urls), new PublicLinkShaper($urls), new FormStructureShape());
		$config = $this->createMock(\OCP\IConfig::class); $config->method('getSystemValueString')->willReturn('secret');
		$handler = new ListFormsHandler($this->services($service), new FormCandidates(new FormReauthorizer()), $shape, new PlanState($config));
		try { $handler->handle(['limit' => 200], 'bob'); self::fail('Oversized page escaped'); }
		catch (ToolFailure $error) {
			self::assertStringContainsString('Reduce limit and start again without a cursor.', $error->getMessage());
			self::assertStringContainsString('No partial page was returned.', $error->getMessage());
			self::assertStringNotContainsString('question or option', $error->getMessage());
			self::assertStringNotContainsString('Open the form in Forms', $error->getMessage());
		}
	}

	/** Round-trips the last valid offset and returns a complete terminal page with an explicit ceiling flag.
	 * @return void
	 */
	public function testReducedCursorCeilingRoundTrip(): void {
		$service = new FormsFixture('bob');
		foreach (range(1, 5) as $id) { $service->forms[$id] = FormsFixture::form($id, 'bob'); $service->forms[$id]->data['title'] = str_repeat('x', 8001); }
		$config = $this->createMock(\OCP\IConfig::class); $config->method('getSystemValueString')->willReturn('secret');
		$handler = new class($this->services($service), new FormCandidates(new FormReauthorizer()), $this->shape(), new PlanState($config)) extends ListFormsHandler {
			/** Reduces only the test's ceiling; the handler still uses it consistently for encode/decode/continuation.
			 * @return int Largest test offset.
			 */
			protected function maxOffset(): int { return 2; }
		};
		$first = $this->json($handler->handle(['limit' => 2], 'bob')); self::assertIsString($first['nextCursor']); self::assertFalse($first['offsetLimitReached']);
		$last = $this->json($handler->handle(['limit' => 2, 'cursor' => $first['nextCursor']], 'bob'));
		self::assertSame([3, 2], array_column($last['untrusted']['forms'], 'id')); self::assertTrue($last['hasMore']); self::assertNull($last['nextCursor']); self::assertTrue($last['offsetLimitReached']);
		self::assertCount(2, $last['truncation']['fields']);
	}

	/** Supplies a focused native-admission double; budget and handler logic remain real.
	 * @param FormsFixture $service Native behavior fixture.
	 * @return FormsServices Already admitted collaborators.
	 */
	private function services(FormsFixture $service): FormsServices {
		$user = $this->createMock(\OCP\IUser::class); $user->method('getUID')->willReturn('bob');
		$services = $this->createMock(FormsServices::class); $services->method('open')->willReturn(['service' => $service, 'mapper' => $service, 'user' => $user, 'version' => '5.4.1']); return $services;
	}

	/** Builds the actual whitelist and link shapers with only URL generation substituted.
	 * @return FormFieldWhitelist Production shape collaborators.
	 */
	private function shape(): FormFieldWhitelist {
		$urls = $this->createMock(\OCP\IURLGenerator::class); $urls->method('linkToRouteAbsolute')->willReturn('https://example.test/credential');
		return new FormFieldWhitelist(new FormDirectLinks($urls), new PublicLinkShaper($urls), new FormStructureShape());
	}

	/** Decodes the normal tool text envelope.
	 * @param array<string,mixed> $reply Tool result.
	 * @return array<string,mixed> Decoded response.
	 */
	private function json(array $reply): array { return json_decode($reply['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR); }
}
