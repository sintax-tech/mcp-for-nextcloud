<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Results;

use OCA\Mcp\Tools\Forms\Results\{ResultsBudget,SubmissionReads};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/NativeResults.php';

/** Discriminates complete response budgets and bounded native header fetch independently of happy-path shaping. */
final class ResultsBudgetTest extends TestCase {
	/** Counts all projected answer strings: 100000 is retained, 100001 refuses without slicing.
	 * @return void
	 */
	public function testExactTotalCharacterBoundary(): void {
		$answers=[];for($i=0;$i<13;$i++){$answers[]=['id'=>$i+1,'questionId'=>9,'type'=>'short','encoding'=>'text','value'=>str_repeat('a',$i===12?3883:8000)];}
		$data=['questions'=>[],'submission'=>['answers'=>$answers]];self::assertSame($data,array_intersect_key(ResultsBudget::envelope($data,[],false)['untrusted'],$data));
		$data['submission']['answers'][12]['value'].='a';$this->expectException(ToolFailure::class);ResultsBudget::envelope($data,[],false);
	}
	/** Distinguishes a serialized payload cap from text budgets; numeric IDs remain intact, never shortened.
	 * @return void
	 */
	public function testSerializedPayloadOverOneMiBRefusesWholePage(): void {
		$row=['id'=>PHP_INT_MAX,'questionId'=>PHP_INT_MAX,'type'=>'short','encoding'=>'text','value'=>''];$submissions=array_fill(0,5,['id'=>1,'answers'=>array_fill(0,2000,$row)]);
		$data=['questions'=>[],'submissions'=>$submissions];self::assertGreaterThan(1048576,strlen(json_encode(['untrusted'=>$data],JSON_PRETTY_PRINT)));
		$this->expectException(ToolFailure::class);$this->expectExceptionMessage('Reduce limit');ResultsBudget::envelope($data,[],true);
	}
	/** A 2001st projected answer is refused, while an ordinary empty submission is valid.
	 * @return void
	 */
	public function testAnswerCountBoundary(): void {
		$data=['questions'=>[],'submission'=>['answers'=>array_fill(0,2000,['id'=>1,'questionId'=>9,'type'=>'short','encoding'=>'text','value'=>''])]];
		self::assertCount(2000,ResultsBudget::envelope($data,[],false)['untrusted']['submission']['answers']);$data['submission']['answers'][]=$data['submission']['answers'][0];
		$this->expectException(ToolFailure::class);ResultsBudget::envelope($data,[],false);
	}
	/** The native reader requests a real maximum and refuses the sentinel row, not a silently shortened universe.
	 * @return void
	 */
	public function testHeaderSentinelUsesBoundedNativeLimit(): void {
		$mapper=new class {
			/** Returns a repeated reference sentinel without manufacturing 100000 entity objects.
			 * @param int $formId Native form.
			 * @param mixed $uid Own filter or null.
			 * @param mixed $query Deliberately no search.
			 * @param int $limit Native database bound.
			 * @param int $offset Native start.
			 * @return array Sentinel result.
			 */
			public function findByForm(int $formId,mixed $uid,mixed $query,int $limit,int $offset):array {if([$formId,$uid,$query,$limit,$offset]!==[5,null,null,50001,0]){throw new \RuntimeException('Unbounded native call');}return array_fill(0,50001,new \stdClass());}
		};
		$c=$this->createMock(ContainerInterface::class);$c->method('get')->willReturn($mapper);$this->expectException(ToolFailure::class);$this->expectExceptionMessage('listing limit');(new SubmissionReads($c))->headers(5,'alice','all');
	}
	/** The complete active structure accepts 2000 members and refuses the next without silently omitting it.
	 * @return void
	 */
	public function testActiveStructureBoundary(): void {
		$rows=[];for($i=1;$i<=2000;$i++){$rows[]=NativeResults::entity('Question',['id'=>$i,'formId'=>5,'order'=>$i,'type'=>'short','name'=>'','text'=>'']);}
		$reads=$this->createMock(SubmissionReads::class);$reads->method('questions')->willReturnCallback(static function()use(&$rows):array{return $rows;});$reads->method('options')->willReturn([]);
		$schema=new \OCA\Mcp\Tools\Forms\Results\ResultsSchema($reads);self::assertCount(2000,$schema->active(5));$rows[]=NativeResults::entity('Question',['id'=>2001,'formId'=>5,'order'=>2001,'type'=>'short','name'=>'','text'=>'']);
		$this->expectException(ToolFailure::class);$schema->active(5);
	}

	/** Counts the page total independently of per-submission limits and serialized text size.
	 * @return void
	 */
	public function testPageAnswerCountBoundary(): void {
		$data=['questions'=>[],'submissions'=>array_fill(0,5,['answers'=>array_fill(0,2000,[])])];
		self::assertCount(5,ResultsBudget::envelope($data,[],true)['untrusted']['submissions']);
		$data['submissions'][]=['answers'=>[[]]];$this->expectException(ToolFailure::class);$this->expectExceptionMessage('Reduce limit');ResultsBudget::envelope($data,[],true);
	}

}
