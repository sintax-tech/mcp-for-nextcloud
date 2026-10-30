<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\Polls\PollsText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plain user text is preserved; controls/invalid encoding are cleaned independently of output budgets. */
final class PollsTextTest extends TestCase {
	/** @return iterable<string,array{string}> legitimate text which a tag stripper would erase or reinterpret */
	public static function literalText(): iterable {
		foreach (['<3 pessoas','vagas <=10','a<b','<b>x</b>'] as $text) {yield $text=>[$text];}
	}

	/**
	 * Literal comparators and markup are inert data, not tags to remove.
	 * @param string $value plain user text
	 * @return void
	 */
	#[DataProvider('literalText')]
	public function testLiteralTextIsNotTreatedAsHtml(string $value): void {
		$text=new PollsText();
		$this->assertSame($value,$text->field($value,'test'));
		$this->assertSame(['truncated'=>false,'totalChars'=>mb_strlen($value,'UTF-8'),'truncatedFields'=>[]],$text->metadata());
	}

	/** @return void valid UTF-8 C1 is a control character, independently of malformed raw bytes */
	public function testValidUtf8C1IsRemoved(): void {
		$value="a\u{0085}b\u{009F}c";
		$this->assertTrue(mb_check_encoding($value,'UTF-8'));
		$this->assertSame('abc',(new PollsText())->field($value,'test'));
	}

	/** @return void direction marks and ASCII controls go while tab, LF and CR keep their ordinary meaning */
	public function testDirectionMarksAndControlsPreserveTabsAndLineBreaks(): void {
		$value="A\u{200E}\u{200F}\x01\x7F\tB\u{202A}\u{202E}\nC\u{2066}\u{2069}\rD";
		$this->assertTrue(mb_check_encoding($value,'UTF-8'));
		$this->assertSame("A\tB\nC\rD",(new PollsText())->field($value,'test'));
	}

	/** @return void malformed UTF-8 is repaired, with its replacement counted instead of refusing all authorized polls */
	public function testMalformedUtf8DoesNotDropTheListPage(): void {
		$old=mb_substitute_character();mb_substitute_character(0xFFFD);
		try {
			$rig=new PollsTestRig($this);$bad="bad\x85text";
			$this->assertFalse(mb_check_encoding($bad,'UTF-8'));
			$rig->poll(1,'alice',['title'=>$bad]);$rig->poll(2);
			$page=$rig->call();
			$this->assertSame([2,1],array_column($page['polls'],'id'));
			$this->assertSame("bad\u{FFFD}text",$page['polls'][1]['title']);
			$this->assertTrue($page['untrustedPollsData']);
			$this->assertFalse($page['truncated']);
		} finally {mb_substitute_character($old);}
	}

	/** @return void both tools preserve all four examples inside the untrusted envelope, with no silent truncation */
	public function testHandlersKeepLiteralPlainTextInTheEnvelope(): void {
		$rig=new PollsTestRig($this);$rig->poll(1,'alice',['title'=>'<3 pessoas','description'=>'vagas <=10']);
		$rig->option(1,1,['text'=>'a<b']);$rig->option(2,1,['text'=>'<b>x</b>']);
		$page=$rig->call('polls_read_poll',['poll_id'=>1]);
		$this->assertTrue($page['untrustedPollsData']);$this->assertFalse($page['truncated']);
		$this->assertSame('<3 pessoas',$page['poll']['title']);$this->assertSame('vagas <=10',$page['poll']['description']);
		$this->assertSame(['a<b','<b>x</b>'],array_column($page['options'],'text'));
		$list=$rig->call();$this->assertTrue($list['untrustedPollsData']);
		$this->assertSame('<3 pessoas',$list['polls'][0]['title']);$this->assertSame('vagas <=10',$list['polls'][0]['description']);
	}

	/** @return void extraction before digesting leaves every valid UTF-8 byte, including controls and markup, unchanged */
	public function testValidUtf8RemainsByteIdenticalBeforeDigest(): void {
		$rig=new PollsTestRig($this);$rig->poll(1);$value="<b>x</b>\u{0085}\u{200E}\t\n";
		$rig->option(1,1,['text'=>$value]);
		$rows=(new \OCA\Mcp\Tools\Polls\PollsShapes())->options(
			[new \OCA\Polls\Db\Option(NativeScene::$options[1][0])],1,static function (): void {});
		$this->assertSame($value,$rows[0]['text']);
	}

	/** @return void malformed option text is repaired before the JSON digest and paginates through the actual handler */
	public function testMalformedOptionTextAndCursorRoundTrip(): void {
		$old=mb_substitute_character();mb_substitute_character(0xFFFD);
		try {
			$rig=new PollsTestRig($this);$rig->poll(1);$rig->option(1,1,['text'=>"bad\x85text"]);
			$rig->option(2,1,['text'=>"a<b\u{0085}\u{200E}"]);
			$first=$rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1]);
			$this->assertSame("bad\u{FFFD}text",$first['options'][0]['text']);
			$this->assertTrue($first['hasMore']);$this->assertIsString($first['nextCursor']);
			$next=$rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1,'options_cursor'=>$first['nextCursor']]);
			$this->assertSame('a<b',$next['options'][0]['text']);$this->assertFalse($next['hasMore']);
			$this->assertTrue($next['untrustedPollsData']);$this->assertSame(1,$next['poll']['id']);
			$this->assertSame('https://cloud.example/sub/apps/polls/vote/1',$next['poll']['url']);
		} finally {mb_substitute_character($old);}
	}

	/** @return void valid control text still participates in the digest even if output cleaning removes it */
	public function testValidControlChangeStillInvalidatesCursor(): void {
		$rig=new PollsTestRig($this);$rig->poll(1);$rig->option(1);$rig->option(2,1,['text'=>"a\u{0085}b"]);
		$first=$rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1]);
		NativeScene::$options[1][1]['text']="a\u{009F}b";
		$this->expectException(\OCA\Mcp\Tools\Polls\PollsRefusal::class);
		$this->expectExceptionMessage('changed');
		$rig->call('polls_read_poll',['poll_id'=>1,'options_limit'=>1,'options_cursor'=>$first['nextCursor']]);
	}
}
