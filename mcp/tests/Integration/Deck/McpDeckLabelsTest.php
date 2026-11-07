<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('deck')]
final class McpDeckLabelsTest extends IntegrationTestCase {
	private function runLabels(string $uid,array $args): array {
		return DeckFixture::run($uid,'mcp-labels',['arguments'=>$args]);
	}
	private function data(array $out): array {
		self::assertFalse($out['result']['isError']??false,json_encode($out));
		return json_decode($out['result']['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	}
	public function testOwnerAndEditorIncrementalChangesPreserveThirdAndNeedBothConfirmations(): void {
		foreach ([DeckFixture::OWNER,DeckFixture::EDITOR] as $uid) {
			$f=DeckFixture::run(DeckFixture::OWNER,'seed');
			$f+=DeckFixture::run(DeckFixture::OWNER,'label-third',$f);
			$args=['cardId'=>$f['a'],'add'=>[$f['keep'],$f['toggle'],$f['toggle']]];
			$out=$this->runLabels($uid,$args);$p=$this->data($out);
			self::assertTrue($p['requiresConfirmation']);self::assertSame([$f['third']],$out['nativeLabels']);
			$confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state']];
			if($uid===DeckFixture::EDITOR){
				$shared=$this->runLabels($uid,$confirmed);self::assertSame('shared',$this->data($shared)['scope']);
				self::assertSame([$f['third']],$shared['nativeLabels']);
			}
			$written=$this->runLabels($uid,$confirmed+['confirm_shared'=>true]);
			self::assertTrue($this->data($written)['confirmed']);$expected=[$f['keep'],$f['toggle'],$f['third']];sort($expected);
			self::assertSame($expected,$written['nativeLabels']);
			$args=['cardId'=>$f['a'],'remove'=>[$f['toggle']]];
			$p=$this->data($this->runLabels($uid,$args));
			$written=$this->runLabels($uid,$args+['confirm'=>true,'confirm_shared'=>true,'plan_state'=>$p['plan_state']]);
			self::assertSame([$f['toggle']],$this->data($written)['removed']);
			$expected=[$f['keep'],$f['third']];sort($expected);self::assertSame($expected,$written['nativeLabels']);
			$noop=['cardId'=>$f['a'],'add'=>[$f['keep']],'remove'=>[$f['toggle']]];
			$p=$this->data($this->runLabels($uid,$noop));
			self::assertFalse($this->data($this->runLabels($uid,$noop+['confirm'=>true,'confirm_shared'=>true,
				'plan_state'=>$p['plan_state']]))['changed']);
		}
	}
	public function testForeignLabelsAndReadOnlyActorsCannotWrite(): void {
		$f=DeckFixture::run(DeckFixture::OWNER,'seed');
		foreach(['add','remove']as$op){
			$out=$this->runLabels(DeckFixture::OWNER,['cardId'=>$f['a'],$op=>[$f['foreignLabel']]]);
			self::assertTrue($out['result']['isError']);self::assertSame([],$out['nativeLabels']);
			self::assertStringNotContainsString('Other board',json_encode($out));
		}
		foreach([DeckFixture::READER,DeckFixture::OUTSIDER]as$uid){
			$out=$this->runLabels($uid,['cardId'=>$f['a'],'add'=>[$f['keep']]]);
			self::assertTrue($out['result']['isError']);
		}
	}
	public function testConcurrentLabelsMoveArchiveAndRevocationInvalidateConfirmation(): void {
		foreach (['label-concurrent', 'label-move', 'label-archive', 'board-archive', 'label-revoke'] as $action) {
			$fixture = DeckFixture::run(DeckFixture::OWNER, 'seed');
			$arguments = ['cardId' => $fixture['a'], 'add' => [$fixture['toggle']]];
			$plan = $this->data($this->runLabels(DeckFixture::EDITOR, $arguments));
			$nativeAction = $action === 'board-archive' ? 'label-archive' : $action;
			$target = $action === 'board-archive' ? 'board' : 'card';
			DeckFixture::run(DeckFixture::OWNER, $nativeAction, $fixture + ['target' => $target]);
			$out = $this->runLabels(DeckFixture::EDITOR, $arguments + [
				'confirm' => true,
				'confirm_shared' => true,
				'plan_state' => $plan['plan_state'],
			]);
			if ($action === 'label-concurrent') {
				self::assertArrayHasKey('changedPlan', $out);
				self::assertSame([$fixture['keep']], $out['nativeLabels']);
			} else {
				self::assertTrue($out['result']['isError']);
			}
			self::assertNotContains($fixture['toggle'], $out['nativeLabels'] ?? []);
		}
	}

	public function testNativeCrossBoardMoveAndSubsequentLocalLabelChange(): void {
		$fixture = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$moved = DeckFixture::run(DeckFixture::OWNER, 'label-move-probe', $fixture);
		// Keep the native behavior explicit for each pinned major, rather than constructing an invalid link in SQL.
		self::assertSame([$fixture['keep']], $moved['labelsAfterMove'], 'Native move preserves the original board label');
		$arguments = ['cardId' => $fixture['a'], 'add' => [$fixture['foreignLabel']]];
		$plan = $this->data($this->runLabels(DeckFixture::OWNER, $arguments));
		self::assertSame([], $plan['before']);
		self::assertStringNotContainsString('foreignAssigned', json_encode($plan));
		$out = $this->runLabels(DeckFixture::OWNER, $arguments + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
		self::assertTrue($this->data($out)['confirmed']);
		self::assertSame([$fixture['foreignLabel']], array_column($this->data($out)['labels'], 'id'));
		$expected = [$fixture['keep'], $fixture['foreignLabel']];
		sort($expected);
		self::assertSame($expected, $out['nativeLabels'], 'Only the requested destination-board label changed');
	}
}
