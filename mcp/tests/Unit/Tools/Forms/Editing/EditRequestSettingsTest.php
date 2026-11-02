<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Editing;

use OCA\Mcp\Tools\Forms\Editing\{EditRequest,EditSettings,EditValues};
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use PHPUnit\Framework\TestCase;

/** Exercises closed input, exact Unicode/byte boundaries and disjoint explicit settings removal. */
final class EditRequestSettingsTest extends TestCase {
	/** Covers native-length boundaries using four-byte code points and refuses invalid bytes or implicit deletions.
	 * @return void
	 */
	public function testTextBoundariesAndClosedFields(): void {
		foreach ([[false,2048],[true,1024]] as [$option,$max]) {
			$args=['form_id'=>1,'question_id'=>2,'changes'=>['text'=>str_repeat('😀',$max)]]+($option?['option_id'=>3]:[]);
			self::assertSame($args['changes'],EditRequest::of($args,$option)['changes']);
			$bad=$args;$bad['changes']['text'].='😀';$this->refused(static fn()=>EditRequest::of($bad,$option));
			foreach ([''," \t","\u{00a0}","\u{3000}",null,"bad\xff","a\0b"] as $text) { $bad=$args;$bad['changes']=['text'=>$text];$this->refused(static fn()=>EditRequest::of($bad,$option)); }
		}
		$args=['form_id'=>1,'question_id'=>2,'changes'=>['description'=>str_repeat('😀',4096)]];
		self::assertSame($args['changes'],EditRequest::of($args,false)['changes']);$args['changes']['description'].='a';$this->refused(static fn()=>EditRequest::of($args,false));
		foreach (['type','order','id','formId','questionId','category','name','options'] as $key) { $this->refused(static fn()=>EditRequest::of(['form_id'=>1,'question_id'=>2,'changes'=>[$key=>'x']],false)); }
		foreach (['1',0,null,1.0] as $id) { $this->refused(static fn()=>EditRequest::of(['form_id'=>$id,'question_id'=>2,'changes'=>['text'=>'x']],false)); }
	}
	/** Distinguishes omission, native null, explicit removal, zero bounds and unsupported resets.
	 * @return void
	 */
	public function testExplicitResetAndNativeNullAreDifferent(): void {
		self::assertSame(['optionsLimitMin'=>2],EditSettings::merge('multiple',['optionsLimitMin'=>2,'optionsLimitMax'=>5],['unset_settings'=>['optionsLimitMax']]));
		$this->refused(static fn()=>EditSettings::merge('multiple',['optionsLimitMin'=>2,'optionsLimitMax'=>5],['settings'=>['optionsLimitMax'=>0]]));
		self::assertSame(['dateMin'=>null],EditSettings::merge('date',['dateMin'=>42],['settings'=>['dateMin'=>null]]));
		self::assertSame([],EditSettings::merge('date',['dateMin'=>42],['unset_settings'=>['dateMin']]));
		self::assertSame([],EditSettings::merge('short',['validationType'=>'email'],['unset_settings'=>['validationType']]));
		$this->refused(static fn()=>EditSettings::merge('short',['validationType'=>'regex','validationRegex'=>'/x/'],['unset_settings'=>['validationRegex']]));
		$this->refused(static fn()=>EditSettings::merge('file',['maxAllowedFilesCount'=>2],['unset_settings'=>['maxAllowedFilesCount']]));
		$this->refused(static fn()=>EditSettings::merge('date',[],['settings'=>['dateMin'=>'2026-10-27']]));
		self::assertSame([],EditSettings::stored(['shuffleOptions'=>false])); self::assertSame(['dateRange'=>null],EditSettings::stored(['dateRange'=>null]));
		foreach ([['settings'=>['validationType'=>null]],['settings'=>['validationType'=>'text']],['settings'=>['future'=>1]],['settings'=>['allowedFileTypes'=>['text/html']]]] as $changes) { $this->refused(static fn()=>EditSettings::merge('short',[],$changes)); }
		foreach ([['settings'=>['dateMin'=>1],'unset_settings'=>['dateMin']],['unset_settings'=>['dateMin','dateMin']],['settings'=>[]],['unset_settings'=>[]]] as $changes) { $this->refused(static fn()=>EditRequest::of(['form_id'=>1,'question_id'=>2,'changes'=>$changes],false)); }
	}
	/** Keeps fields/types explicitly bounded rather than using installed Constants additions.
	 * @return void
	 */
	public function testSettingsDomainsAndArrayBounds(): void {
		foreach (['long','color','datetime','grid'] as $type) { $this->refused(static fn()=>EditSettings::merge($type,[],['settings'=>['shuffleOptions'=>true]])); }
		self::assertSame(['optionsHighest'=>10,'optionsLowest'=>0],EditSettings::merge('linearscale',[],['settings'=>['optionsLowest'=>0,'optionsHighest'=>10]]));
		foreach ([['optionsLowest'=>2],['optionsHighest'=>11]] as $map) { $this->refused(static fn()=>EditSettings::merge('linearscale',[],['settings'=>$map])); }
		$this->refused(static fn()=>EditSettings::merge('file',[],['settings'=>['allowedFileExtensions'=>array_fill(0,33,'png')]]));
		$this->refused(static fn()=>EditSettings::merge('file',[],['settings'=>['allowedFileExtensions'=>['../x']]]));
		self::assertSame(['maxFileSize'=>0],EditSettings::merge('file',[],['settings'=>['maxFileSize'=>0]]));
	}
	/** Verifies the published consumer schema actually admits supported typed values and rejects malformed nested input.
	 * @return void
	 */
	public function testPublishedSchemaMatchesSupportedSettingValues(): void {
		$schema=\OCA\Mcp\Modules\Forms\Presentation\EditTools::definitions()[0]['inputSchema'];
		foreach ([['validationType'=>'email'],['dateMin'=>null],['dateRange'=>false],['allowedFileExtensions'=>['png']]] as $settings) {
			$args=['form_id'=>1,'question_id'=>2,'changes'=>['settings'=>$settings]];
			$valid=\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema,$args);
			self::assertSame($settings,$valid['changes']['settings']);
		}
		$this->refused(static fn()=>\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema,['form_id'=>1,'question_id'=>2,'changes'=>['settings'=>['dateMin'=>'today']]]));
		$this->refused(static fn()=>EditSettings::merge('date',[],['settings'=>['dateMin'=>2,'dateMax'=>1]]));
		$this->refused(static fn()=>EditSettings::merge('time',[],['settings'=>['timeMin'=>'10:00','timeMax'=>'09:59']]));
	}

	/** Requires a meaningful argument assertion rather than accepting crashes as negative proof.
	 * @param callable():mixed $call Invalid operation.
	 * @return void
	 */
	private function refused(callable $call): void { try { $call();self::fail('Invalid editing value admitted'); } catch (ArgumentValidationException $error) { self::assertStringContainsString('Invalid argument',$error->getMessage()); } }
}
