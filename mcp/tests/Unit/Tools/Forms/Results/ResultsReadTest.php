<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Results;

use OCA\Mcp\Modules\Forms\Integration\ResultsAccess;
use OCA\Mcp\Modules\Forms\Integration\SubmissionReads;
use OCA\Mcp\Modules\Forms\Integration\ResultsSchema;
use OCA\Mcp\Modules\Forms\Integration\ResultsReadFlow;
use OCA\Mcp\Modules\Forms\Integration\SubmissionShape;
use OCA\Mcp\Modules\Forms\Integration\AnswerShape;
use OCA\Mcp\Modules\Forms\Presentation\ResultsBudget;
use OCA\Mcp\Modules\Forms\Presentation\ResultsMessages;
use OCA\Mcp\Modules\Forms\Reading\SubmissionCursor;
use OCA\Mcp\Modules\Forms\Reading\SubmissionOrder;
use OCA\Mcp\Modules\Forms\ResultFileAccess;
use OCA\Mcp\Modules\Forms\Reading\ListSubmissionsHandler;
use OCA\Mcp\Modules\Forms\Reading\ReadSubmissionHandler;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\Forms\FormsFixture;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/NativeResults.php';
require_once __DIR__ . '/ResultState.php';

/** Real product pipeline with native entities and discriminant storage/access boundaries. */
final class ResultsReadTest extends TestCase {
	private ResultState $state;
	private ResultsAccess $access;
	private ResultFileAccess $files;
	private ResultsReadFlow $flow;
	private bool $granted = true;
	private string $mode = 'all';
	private PlanState $mac;
	/** Builds the actual mapper/shape/cursor/budget flow, never stubbing its decisions.
	 * @return void
	 */
	protected function setUp(): void {
		$this->state = new ResultState();
		$this->state->headers = [NativeResults::entity('Submission', ['id'=>1,'formId'=>5,'userId'=>'alice','timestamp'=>10]), NativeResults::entity('Submission', ['id'=>2,'formId'=>5,'userId'=>'anon-user-private','timestamp'=>10])];
		$this->state->questions = [NativeResults::entity('Question', ['id'=>9,'formId'=>5,'order'=>1,'type'=>'short','name'=>'n','text'=>'Question'])];
		$this->state->answers = [1=>[NativeResults::entity('Answer',['id'=>11,'submissionId'=>1,'questionId'=>9,'text'=>'001'])],2=>[NativeResults::entity('Answer',['id'=>12,'submissionId'=>2,'questionId'=>9,'text'=>'Second'])]];
		$this->access = $this->createMock(ResultsAccess::class);
		$this->access->method('open')->willReturnCallback(fn (): array => ['service'=>new \stdClass(),'form'=>FormsFixture::form(5,'alice'),'mode'=>$this->mode,'version'=>'5.4.1']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class): object => new ResultMapper($this->state, substr($class,strrpos($class,'\\')+1)));
		$reads = new SubmissionReads($container); $schema = new ResultsSchema($reads);
		$this->files = $this->createMock(ResultFileAccess::class); $this->files->method('begin')->willReturn(false);
		$policy = $this->createMock(GrantPolicy::class); $policy->method('within')->willReturnCallback(static fn ($uid,$work) => $work());
		$policy->method('granted')->willReturnCallback(fn (): bool => $this->granted);
		$config = new InMemoryConfig(); $config->system['secret']='test-only'; $this->mac = new PlanState($config->mock($this));
		$this->flow = new class($policy,$this->access,$reads,$schema,new SubmissionShape($reads,new AnswerShape($this->files)),$this->files,$this->mac) extends ResultsReadFlow {
			/** Reduces only the cursor ceiling to exercise producer/consumer boundaries with four entities.
			 * @return int Largest admitted offset in this discriminant fixture.
			 */
			protected function maxOffset(): int { return 2; }
		};
	}
	/** Decodes only the complete public product result.
	 * @param array $args Validated test request.
	 * @param bool $list Selects the actual handler.
	 * @return array Product envelope.
	 */
	private function call(array $args = [], bool $list = true): array {
		$handler = $list ? new ListSubmissionsHandler($this->flow) : new ReadSubmissionHandler($this->flow);
		$reply = $handler->handle(['form_id'=>5] + $args, 'alice');
		return json_decode($reply['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	}
	/** Ensures each semantic refusal remains a complete failure, without leaking native details.
	 * @param callable $work Request expected to refuse.
	 * @param string $contains Fixed public diagnostic fragment.
	 * @return void
	 */
	private function refused(callable $work, string $contains = ''): void {
		try { $work(); self::fail('Expected refusal'); }
		catch (ToolFailure $error) { self::assertStringNotContainsString('private database path',$error->getMessage()); if ($contains !== '') { self::assertStringContainsString($contains,$error->getMessage()); } }
	}
	/** Orders tied headers completely and never emits respondent identities.
	 * @return void
	 */
	public function testAllOwnAnonymousAndCursorRoundTrip(): void {
		$first=$this->call(['limit'=>1]); $rows=$first['untrusted']['submissions'];self::assertSame(2,$rows[0]['id']);self::assertTrue($rows[0]['anonymous']);
		self::assertStringNotContainsString('anon-user',json_encode($first));self::assertStringNotContainsString('userId',json_encode($first));
		$next=$this->call(['limit'=>1,'cursor'=>$first['nextCursor']]);self::assertSame('001',$next['untrusted']['submissions'][0]['answers'][0]['value']);self::assertNull($next['nextCursor']);
		$this->mode='own';$own=$this->call();self::assertSame([1],array_column($own['untrusted']['submissions'],'id'));
		self::assertSame('001',$this->call(['submission_id'=>1],false)['untrusted']['submission']['answers'][0]['value']);
		$this->refused(fn()=>$this->call(['submission_id'=>2],false));
	}
	/** Omits inactive/foreign/file answers before any Files or text inspection in both handlers.
	 * @return void
	 */
	public function testInactiveOmissionIsUniformAndIgnoresMalformedFileText(): void {
		$before=$this->call();$single=$this->call(['submission_id'=>1],false);
		$this->files->expects(self::never())->method('resolve');
		$this->state->answers[1][]=NativeResults::entity('Answer',['id'=>99,'submissionId'=>1,'questionId'=>999,'fileId'=>77,'text'=>"hidden\xff"]);
		self::assertSame($before,$this->call());self::assertSame($single,$this->call(['submission_id'=>1],false));
	}
	/** A natively declared future type outside MCP support refuses even when no submission answers it.
	 * @return void
	 */
	public function testNativeFutureTypeWithoutAnswerStillRefuses(): void {
		self::assertContains('future', \OCA\Forms\Constants::ANSWER_TYPES);
		self::assertNotContains('future', \OCA\Mcp\Modules\Forms\Reading\ResultsTypes::SUPPORTED);
		$this->state->questions[]=NativeResults::entity('Question',['id'=>10,'formId'=>5,'order'=>2,'type'=>'future','name'=>'n','text'=>'Unknown']);
		$this->refused(fn()=>$this->call(),'structure');$this->refused(fn()=>$this->call(['submission_id'=>1],false),'structure');
	}
	/** Invalid answer bytes affect only the selected page or single, not the header universe.
	 * @return void
	 */
	public function testUtf8IsPageRelativeAndNeverRepaired(): void {
		$this->state->answers[1][0]->setText("bad\xff");$page=$this->call(['limit'=>1]);self::assertSame(2,$page['untrusted']['submissions'][0]['id']);
		$this->refused(fn()=>$this->call(['limit'=>1,'cursor'=>$page['nextCursor']]),'UTF-8');$this->refused(fn()=>$this->call(['submission_id'=>1],false),'UTF-8');
		$this->state->questions[0]->setText("bad\xff");$this->refused(fn()=>$this->call(['limit'=>1]),'UTF-8');
	}
	/** A missing Files grant omits active uploads without consuming budget or leaking ids/text.
	 * @return void
	 */
	public function testFilesOmissionAndBudgetHaveNoHiddenSignals(): void {
		$this->state->questions[0]->setType('file');$this->state->answers=[];$control=$this->call();$this->files->expects(self::never())->method('resolve');
		$hidden=NativeResults::entity('Answer',['id'=>99,'submissionId'=>1,'questionId'=>9,'fileId'=>777,'text'=>str_repeat('hidden',9000)."\xff"]);$this->state->answers[1]=array_fill(0,2001,$hidden);
		self::assertSame($control,$this->call());
	}
	/** Every collection error aborts the read rather than returning a misleading empty set.
	 * @return void
	 */
	public function testMapperFailuresNeverBecomeEmpty(): void {
		foreach (['SubmissionMapper','QuestionMapper','OptionMapper','AnswerMapper'] as $kind) { $this->state->fail=$kind;$this->refused(fn()=>$this->call()); }
	}
	/** Denied grant and a forged cursor cannot reach header or answer data.
	 * @return void
	 */
	public function testNoReadBeforeGrantOrCursorAdmission(): void {
		$this->granted=false;$this->access->expects(self::never())->method('open');$this->refused(fn()=>$this->call());self::assertSame([],$this->state->calls);
	}
	/** Authenticates public cursor state before the bounded mapper read.
	 * @return void
	 */
	public function testForgedCursorHasZeroDataReads(): void {
		$this->refused(fn()=>$this->call(['cursor'=>'v1.invalid.invalid']));self::assertSame([],$this->state->calls);
	}
	/** A state-changing insertion and a page-size mismatch both invalidate continuation.
	 * @return void
	 */
	public function testChangedHeadersAndParametersRejectCursor(): void {
		$first=$this->call(['limit'=>1]);$this->refused(fn()=>$this->call(['limit'=>2,'cursor'=>$first['nextCursor']]));
		$this->state->headers[0]->setTimestamp(11);$this->refused(fn()=>$this->call(['limit'=>1,'cursor'=>$first['nextCursor']]));
	}
	/** A revocation or selected identity change during answer fetch never returns the already-shaped data.
	 * @return void
	 */
	public function testFinalReauthorizationAndIdentityRefuse(): void {
		$this->state->afterAnswers=function():void{$this->mode='own';};$this->refused(fn()=>$this->call());
		$this->mode='all';$this->state->afterAnswers=function():void{$this->state->headers[0]->setTimestamp(999);};$this->refused(fn()=>$this->call());
	}
	/** Preserves stored structured text while declaring malformed native JSON unparsed.
	 * @return void
	 */
	public function testStructuredJsonAndHistoricalLabelsRemainExact(): void {
		$this->state->questions[0]->setType('ranking');$this->state->answers[1][0]->setText('[2,1]');$row=$this->call(['submission_id'=>1],false)['untrusted']['submission']['answers'][0];self::assertSame([2,1],$row['value']);self::assertSame('[2,1]',$row['storedText']);
		$this->state->answers[1][0]->setText('{oops');$row=$this->call(['submission_id'=>1],false)['untrusted']['submission']['answers'][0];self::assertSame('unparsed',$row['encoding']);self::assertSame('{oops',$row['storedText']);
	}
	/** Refuses oversized data instead of quietly slicing answer values.
	 * @return void
	 */
	public function testTextBoundaryPreserves8000AndRefuses8001(): void {
		$this->state->answers[1][0]->setText(str_repeat('a',8000));self::assertSame(8000,strlen($this->call(['submission_id'=>1],false)['untrusted']['submission']['answers'][0]['value']));
		$this->state->answers[1][0]->setText(str_repeat('a',8001));$this->refused(fn()=>$this->call(['submission_id'=>1],false),'limit');
	}
	/** Cursor producer and consumer share a reducible offset ceiling and bind exact actor bytes.
	 * @return void
	 */
	public function testReducedCursorCeilingAndActorBinding(): void {
		$c=new SubmissionCursor($this->mac,'alice',['form'=>5],2);$state=str_repeat('a',64);self::assertSame(['o'=>2,'s'=>$state],$c->decode($c->encode(2,$state)));
		$this->refused(fn()=>(new SubmissionCursor($this->mac,'bob',['form'=>5],2))->decode($c->encode(2,$state)));
		$this->expectException(\LogicException::class);$c->encode(3,$state);
	}
	/** Header digest ignores text entirely and keeps different invalid internal UID bytes distinct.
	 * @return void
	 */
	public function testHeaderDigestDoesNotRepairOrUseAnswerText(): void {
		$a=[['id'=>1,'formId'=>5,'timestamp'=>1,'userId'=>"\xff"]];$b=$a;$b[0]['userId']="\xfe";
		self::assertNotSame(SubmissionOrder::digest($this->mac,'alice',$a),SubmissionOrder::digest($this->mac,'alice',$b));
	}
	/** A page at the offset ceiling remains readable and never fabricates an unusable continuation.
	 * @return void
	 */
	public function testFlowCeilingSignalsUnreachableRemainder(): void {
		foreach ([3,4] as $id) { $this->state->headers[]=NativeResults::entity('Submission',['id'=>$id,'formId'=>5,'userId'=>'alice','timestamp'=>10]); }
		$first=$this->call(['limit'=>1]);$second=$this->call(['limit'=>1,'cursor'=>$first['nextCursor']]);
		$last=$this->call(['limit'=>1,'cursor'=>$second['nextCursor']]);self::assertTrue($last['hasMore']);self::assertTrue($last['offsetLimitReached']);self::assertNull($last['nextCursor']);self::assertSame(2,$last['untrusted']['submissions'][0]['id']);
	}

}
