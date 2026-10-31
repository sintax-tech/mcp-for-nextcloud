<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;
use OCA\Mcp\Tools\Deck\{CardLabelChanges, DeckGatewayInterface, DeckConflictException, DeckPlanRenderer};
use OCA\Mcp\Tools\Deck\Handler\EditCardLabelsHandler;
use OCA\Mcp\Mcp\{Confirmation\PlanState, Confirmation\PlanChanged, Tools\Validation\ArgumentValidationException};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
require_once __DIR__ . '/Stubs/deck_stubs.php';

final class CardLabelsTest extends TestCase {
	use DeckTestHelpers;
	private function label(int $id): array { return ['id'=>$id,'boardId'=>20,'title'=>'Label '.$id,'color'=>'008800']; }
	private function state(array $ids=[1,3], string $owner='alice'): array {
		return ['card'=>$this->card(['id'=>7]), 'boardId'=>20, 'board'=>$this->ownershipOf($owner),
			'labels'=>array_map($this->label(...),$ids),'requested'=>array_map($this->label(...),[1,2])];
	}
	private function handler(DeckGatewayInterface $gateway): EditCardLabelsHandler {
		$config=$this->createMock(\OCP\IConfig::class);$config->method('getSystemValueString')->willReturn('secret');
		return new EditCardLabelsHandler($gateway,$this->createMock(LoggerInterface::class),$this->cardFormatter(),
			fn()=>new PlanState($config));
	}
	private function args(EditCardLabelsHandler $h): array {
		$args=['cardId'=>7,'add'=>[2,2],'remove'=>[1]];
		return $args+['confirm'=>true,'plan_state'=>$h->preview($args,'alice')['plan_state']];
	}
	public static function invalid(): iterable {
		foreach ([[],['add'=>[0]],['add'=>['2']],['add'=>[true]],['add'=>[1],'remove'=>[1]],
			['add'=>array_fill(0,101,1)],['add'=>['key'=>1]],['remove'=>'1']] as $a) { yield [$a]; }
	}
	#[DataProvider('invalid')]
	public function testRejectsInvalidDelta(array $a): void {
		$this->expectException(ArgumentValidationException::class);CardLabelChanges::parse($a);
	}
	public function testPreviewNeverWritesAndPreservesThirdLabel(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::never())->method('changeCardLabel');$h=$this->handler($g);
		$p=$h->preview(['cardId'=>7,'add'=>[2,2],'remove'=>[1]],'alice');
		self::assertSame([2,3],array_column($p['after'],'id'));self::assertSame([2],$p['add']);
	}
	public function testIncrementalWritesAndPersistThenThrowAreReadBack(): void {
		$s=$this->state();$g=$this->createMock(DeckGatewayInterface::class);
		$g->method('cardLabelState')->willReturnCallback(function()use(&$s){return $s;});
		$g->expects(self::exactly(2))->method('changeCardLabel')->willReturnCallback(
			function($uid,$card,$id,$add,$requested,$expected)use(&$s):void{
				self::assertSame(CardLabelChanges::snapshot($s),$expected);
				$s['labels']=CardLabelChanges::delta($s,['add'=>$add?[$id]:[],'remove'=>$add?[]:[$id]])['after'];
				if($add){throw new \RuntimeException('event failed after persistence');}
			});
		$h=$this->handler($g);$r=$this->payload($h->handle($this->args($h),'alice'));
		self::assertTrue($r['confirmed']);self::assertSame([2,3],array_column($r['labels'],'id'));
		self::assertSame([2],$r['added']);self::assertSame([1],$r['removed']);self::assertCount(1,$r['warnings']);
		self::assertStringContainsString('may not have made',$r['warnings'][0]);
		self::assertSame('observed-state',$r['verification']);
	}
	public function testSatisfiedRequestsAreNoopsAndSharedBoardsRequireConfirmation(): void {
		$s=$this->state([2,3],'pedro');$g=$this->createMock(DeckGatewayInterface::class);
		$g->method('cardLabelState')->willReturn($s);$g->expects(self::never())->method('changeCardLabel');
		$h=$this->handler($g);$args=$this->args($h);
		self::assertSame('shared',$this->payload($h->handle($args,'alice'))['scope']);
		$r=$this->payload($h->handle($args+['confirm_shared'=>true],'alice'));
		self::assertFalse($r['changed']);self::assertSame([2],$r['unchanged']['alreadyAssigned']);
		self::assertSame([1],$r['unchanged']['notAssigned']);
	}
	public function testConcurrentStateRequiresANewPlanWithNoWrite(): void {
		$s=$this->state();$g=$this->createMock(DeckGatewayInterface::class);
		$g->method('cardLabelState')->willReturnCallback(function()use(&$s){return $s;});
		$g->expects(self::never())->method('changeCardLabel');$h=$this->handler($g);$a=$this->args($h);
		$s['requested'][1]['title']='Changed label';
		try{$h->handle($a,'alice');self::fail('stale plan accepted');}catch(PlanChanged $e){
			self::assertNotSame($a['plan_state'],$e->plan['plan_state']);
		}
	}
	public function testPrewriteConflictReturnsNewPlanAndNeverRetries(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::once())->method('changeCardLabel')->willThrowException(new DeckConflictException());
		$h=$this->handler($g);$a=$this->args($h);$this->expectException(PlanChanged::class);$h->handle($a,'alice');
	}
	public function testUnknownReadbackStopsWithoutRepeatingWriteOrClaimingSuccess(): void {
		$written=false;$g=$this->createMock(DeckGatewayInterface::class);
		$g->method('cardLabelState')->willReturnCallback(function()use(&$written){
			if($written){throw new \RuntimeException('revoked');}return $this->state();});
		$g->expects(self::once())->method('changeCardLabel')->willReturnCallback(function()use(&$written):void{$written=true;});
		$h=$this->handler($g);$r=$this->payload($h->handle($this->args($h),'alice'));
		self::assertFalse($r['confirmed']);self::assertStringContainsString('do not repeat',$r['message']);
		self::assertSame(['add'=>[], 'remove'=>[]],$r['applied']);
		self::assertSame(['add'=>[2], 'remove'=>[1]],$r['pending']);
		self::assertNull($r['labels']);self::assertFalse($r['labelsCurrent']);
		self::assertSame([1,3],array_column($r['lastObservedLabels'],'id'));
	}
	public function testFailureBeforeSecondWriteReportsOnlyObservedPartialState(): void {
		$s=$this->state();$g=$this->createMock(DeckGatewayInterface::class);
		$g->method('cardLabelState')->willReturnCallback(function()use(&$s){return $s;});
		$g->expects(self::exactly(2))->method('changeCardLabel')->willReturnCallback(function($u,$c,$id,$add)use(&$s):void{
			if($add){throw new DeckConflictException();}$s['labels']=[$this->label(3)];
		});
		$h=$this->handler($g);$r=$this->payload($h->handle($this->args($h),'alice'));
		self::assertTrue($r['partial']);self::assertFalse($r['confirmed']);self::assertSame([1],$r['removed']);
		self::assertSame(['add'=>[], 'remove'=>[1]],$r['applied']);
		self::assertSame(['add'=>[2], 'remove'=>[]],$r['pending']);
		self::assertTrue($r['labelsCurrent']);self::assertSame([3],array_column($r['labels'],'id'));
	}
	public function testMissingPlanStateNeverWrites(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::never())->method('changeCardLabel');$h=$this->handler($g);
		$this->expectException(ArgumentValidationException::class);$h->handle(['cardId'=>7,'add'=>[2],'confirm'=>true],'alice');
	}
	public function testPlanCannotBeReusedForAnotherDeltaOrActor(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::never())->method('changeCardLabel');$h=$this->handler($g);$a=$this->args($h);
		foreach ([['cardId'=>7,'remove'=>[1],'confirm'=>true,'plan_state'=>$a['plan_state']],$a] as $i=>$args) {
			try{$h->handle($args,$i===0?'alice':'bob');self::fail('reused plan accepted');}
			catch(PlanChanged $e){self::assertNotSame($a['plan_state'],$e->plan['plan_state']);}
		}
	}
	public function testMessagesAndPlanTitleAreLocalizedInAllSupportedLanguages(): void {
		try {
			foreach(['en','pt_BR','es','de']as$lang){
				\OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n($lang));
				$message=\OCA\Mcp\Tools\Deck\DeckMessages::planLabels();
				$title=\OCA\Mcp\Mcp\Tools\ToolPresentation::title(EditCardLabelsHandler::TOOL);
				if($lang==='en'){self::assertStringContainsString('Only the requested',$message);}
				else{self::assertStringNotContainsString('Only the requested',$message);self::assertNotSame('Change Deck card labels',$title);}
			}
		}finally{\OCA\Mcp\L10n\Translator::reset();}
	}
	public function testRegistryRechecksDeckEditGrantAndAppBeforePreviewOrConfirm(): void {
		$data=new \OCA\Mcp\Tests\Unit\InMemoryConfig();$config=$data->mock($this);
		$policy=\OCA\Mcp\Tests\Unit\InMemoryConfig::policy($config,new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
		$policy->setGrant('alice','deck','edit',true);
		$apps=$this->createMock(\OCP\App\IAppManager::class);$enabled=true;
		$apps->method('isEnabledForUser')->willReturnCallback(function()use(&$enabled){return $enabled;});
		$users=$this->createMock(\OCP\IUserManager::class);$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$logger=$this->createMock(LoggerInterface::class);
		$module=new \OCA\Mcp\Tools\Deck\DeckToolModule($this->createMock(\Psr\Container\ContainerInterface::class),
			$apps,$logger,$this->createMock(\OCP\IURLGenerator::class),$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),$users,$config);
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::never())->method('changeCardLabel');
		(new \ReflectionProperty($module,'gateway'))->setValue($module,$g);
		$registry=new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module],$policy,$apps,$users,$logger);
		$args=['cardId'=>7,'add'=>[2]];
		$p=$registry->call(EditCardLabelsHandler::TOOL,$args,'alice')['structuredContent'];
		self::assertTrue($p['requiresConfirmation']);
		$confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state']];
		$policy->setGrant('alice','deck','edit',false);
		try{$registry->call(EditCardLabelsHandler::TOOL,$confirmed,'alice');self::fail('revoked grant accepted');}
		catch(\InvalidArgumentException){self::assertTrue(true);}
		$policy->setGrant('alice','deck','edit',true);$enabled=false;
		$this->expectException(\InvalidArgumentException::class);$registry->call(EditCardLabelsHandler::TOOL,$confirmed,'alice');
	}
	public function testCleanFirstFailureReturnsTheOriginalDeckError(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::once())->method('changeCardLabel')->willThrowException(new \OCA\Deck\NoPermissionException());
		$h=$this->handler($g);$result=$h->handle($this->args($h),'alice');
		self::assertTrue($result['isError']);
		self::assertSame(\OCA\Mcp\Tools\Deck\DeckMessages::errorNotFoundOrForbidden(),$result['content'][0]['text']);
	}
	public function testUnobservedWriteWithoutExceptionReportsAllPendingAndCurrentLabels(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$g->method('cardLabelState')->willReturn($this->state());
		$g->expects(self::once())->method('changeCardLabel');$h=$this->handler($g);
		$r=$this->payload($h->handle($this->args($h),'alice'));
		self::assertTrue($r['partial']);self::assertSame(['add'=>[2],'remove'=>[1]],$r['pending']);
		self::assertSame(['add'=>[],'remove'=>[]],$r['applied']);self::assertTrue($r['labelsCurrent']);
		self::assertSame([1,3],array_column($r['labels'],'id'));
	}
	public function testConcurrentOtherChangeAfterAWriteReturnsObservedLabelsAndPending(): void {
		$state=$this->state();$g=$this->createMock(DeckGatewayInterface::class);
		$g->method('cardLabelState')->willReturnCallback(function()use(&$state){return $state;});
		$g->expects(self::once())->method('changeCardLabel')->willReturnCallback(function()use(&$state):void{
			$state['labels']=[$this->label(3),$this->label(4)];
		});
		$h=$this->handler($g);$r=$this->payload($h->handle($this->args($h),'alice'));
		self::assertSame(['add'=>[],'remove'=>[1]],$r['applied']);
		self::assertSame(['add'=>[2],'remove'=>[]],$r['pending']);
		self::assertSame([3,4],array_column($r['labels'],'id'));self::assertTrue($r['labelsCurrent']);
	}
	public function testRendererEscapesBoardCardAndLabelText(): void {
		$g=$this->createMock(DeckGatewayInterface::class);$s=$this->state();$s['board']['name']='[board](https://bad)';
		$s['labels'][0]['title']='[label](https://bad)';$g->method('cardLabelState')->willReturn($s);
		$p=$this->handler($g)->preview(['cardId'=>7,'add'=>[2]],'alice');
		$text=(new DeckPlanRenderer())->render(EditCardLabelsHandler::TOOL,$p);
		self::assertNotNull($text);self::assertStringNotContainsString('[label](https://bad)',$text);
		self::assertStringNotContainsString('[board](https://bad)',$text);
	}
}
