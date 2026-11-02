<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Results;

use OCA\Mcp\Modules\Forms\Integration\ResultsAccess;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Tests\Unit\Tools\Forms\FormsFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NativeResults.php';

/** Checks native RESULT mode, not submit/edit or the cosmetic permission of a prior submitter. */
final class ResultsAccessTest extends TestCase {
	/** Reproduces only the native RESULTS admission and verifies the exact mode passed to it.
	 * @return void
	 */
	public function testOwnAllAndUidZeroBeforeAnyDataMapper(): void {
		foreach (['alice','0'] as $uid) {
			foreach ([['submit'],['edit'],['results']] as $permissions) {
				$form=FormsFixture::form(5,'owner');
				$native=new class($form,$permissions) {
					/** Receives a real-shaped entity and current native permissions.
					 * @param object $form Native entity.
					 * @param array $permissions Native permission list.
					 */
					public function __construct(private object $form,private array $permissions) {}
					/** Admits historical own access, without demanding submit or cosmetic results.
					 * @param int $id Form.
					 * @param string $mode Native admission mode.
					 * @return object Authorized Form.
					 */
					public function getFormIfAllowed(int $id,string $mode):object { if($id!==5||$mode!=='results'){throw new \RuntimeException('wrong gate');}return $this->form; }
					/** Returns actual native grants, without a prior-submission cosmetic results flag.
					 * @param object $form Authorized entity.
					 * @return array Native grants.
					 */
					public function getPermissions(object $form):array {return $this->permissions;}
				};
				$forms=$this->createMock(FormsServices::class);$forms->expects(self::once())->method('open')->with($uid)->willReturn(['service'=>$native,'version'=>'5.3.0']);
				try{$result=(new ResultsAccess($forms))->open($uid,5);self::assertFalse($uid==='0'&&!in_array('results',$permissions,true));self::assertSame(in_array('results',$permissions,true)?'all':'own',$result['mode']);}
				catch(ToolFailure $error){self::assertSame('0',$uid);self::assertNotContains('results',$permissions);self::assertStringContainsString('identifier',$error->getMessage());}
			}
		}
	}
	/** Both real handlers refuse UID0 own before any data/schema mapper or Files admission.
	 * @return void
	 */
	public function testBothHandlersUidZeroHaveZeroDataReads(): void {
		$form=FormsFixture::form(5,'owner');
		$native=new class($form) {
			/** Receives the natively admitted form.
			 * @param object $form Fixture entity.
			 */
			public function __construct(private object $form) {}
			/** Represents prior own response admission by native canSeeResults.
			 * @param int $id Requested form.
			 * @param string $mode RESULTS gate.
			 * @return object Native form.
			 */
			public function getFormIfAllowed(int $id,string $mode):object{return $this->form;}
			/** Own access adds no native results permission.
			 * @param object $form Admitted form.
			 * @return array Native permissions.
			 */
			public function getPermissions(object $form):array{return ['submit'];}
		};
		$forms=$this->createMock(FormsServices::class);$forms->expects(self::exactly(2))->method('open')->with('0')->willReturn(['service'=>$native,'version'=>'5.4.1']);
		$container=$this->createMock(\Psr\Container\ContainerInterface::class);$container->expects(self::never())->method('get');
		$reads=new \OCA\Mcp\Modules\Forms\Integration\SubmissionReads($container);$schema=new \OCA\Mcp\Modules\Forms\Integration\ResultsSchema($reads);
		$files=$this->createMock(\OCA\Mcp\Modules\Forms\ResultFileAccess::class);$files->expects(self::never())->method('begin');
		$policy=$this->createMock(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->method('within')->willReturnCallback(static fn($uid,$work)=>$work());$policy->method('granted')->willReturn(true);
		$shape=new \OCA\Mcp\Modules\Forms\Integration\SubmissionShape($reads,new \OCA\Mcp\Modules\Forms\Integration\AnswerShape($files));
		$mac=new \OCA\Mcp\Mcp\Confirmation\PlanState((new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this));
		$flow=new \OCA\Mcp\Modules\Forms\Integration\ResultsReadFlow($policy,new ResultsAccess($forms),$reads,$schema,$shape,$files,$mac);
		foreach([new \OCA\Mcp\Modules\Forms\Reading\ListSubmissionsHandler($flow),new \OCA\Mcp\Modules\Forms\Reading\ReadSubmissionHandler($flow)]as$handler){
			try{$handler->handle(['form_id'=>5,'submission_id'=>1],'0');self::fail('UID0 own must refuse');}catch(ToolFailure$error){self::assertStringContainsString('identifier',$error->getMessage());}
		}
	}

}
