<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use PHPUnit\Framework\TestCase;

/** Contractual call order is observed explicitly; native default-zero behavior is preserved. */
final class PollsCreateNormalizationTest extends TestCase {
	/** @return void inversion can be caught without inventing a missing-parent exception upstream never throws */
	public function testSentinelPrecedesConversionInBothLayouts(): void {
		$r=new PollsCreateRig($this);$request=['type'=>'textPoll','title'=>'Order','options'=>[['text'=>'&amp;']]];
		foreach([true,false]as$nine){
			NativeCreateScene::$normalizationCalls=[];$value=$r->normalizer->predict($request,$nine);
			self::assertSame([[$nine?'setPoll':'setPollId',0],['convert',null]],NativeCreateScene::$normalizationCalls);
			self::assertSame('&',$value[0]['text']);
		}
	}
	/** @return void the native option starts at parent0 and can convert without an explicit setter */
	public function testNativeZeroDefaultAndCanonicalHashWithoutArtificialPrecondition(): void {
		$r=new PollsCreateRig($this);$option=new \OCA\Polls\Db\Option();
		self::assertSame(0,$option->getPollId());
		$option->setFromSimpleOption($r->normalizer->simple(['text'=>'&amp;'],'textPoll'),'textPoll');
		self::assertSame('&',$option->getPollOptionText());self::assertSame(md5('0&'),$option->getPollOptionHash());
	}
}
