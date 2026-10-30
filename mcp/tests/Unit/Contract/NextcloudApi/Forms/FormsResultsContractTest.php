<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use PHPUnit\Framework\TestCase;

/** Four independently SHA-pinned source contracts distinguish read gates, truthiness and masked-error alternatives. */
final class FormsResultsContractTest extends TestCase {
	/** Checks all selected source bodies and common floor signatures, without calling this runtime proof.
	 * @return void
	 */
	public function testFourPinsHaveExactReadContractsAndNoMapperActorCapture(): void {
		foreach(FormsSourcePins::all()as$version=>$sha){
			$f=json_decode(file_get_contents(__DIR__.'/../../../../fixtures/nextcloud-api/forms/'.$version.'.json'),true,512,JSON_THROW_ON_ERROR);self::assertSame($sha,$f['sha256']);
			foreach($f['sources']as$entry){self::assertSame($entry['sha256'],hash('sha256',$entry['source']));}
			$forms=$f['sources']['Service/FormsService']['source'];$acl=FormsBehavior::body($forms,'canSeeResults');self::assertStringContainsString('countSubmissions($form->getId(),$this->currentUser->getUID())>0',$acl);self::assertStringContainsString('in_array(Constants::PERMISSION_RESULTS,$this->getPermissions($form))',$acl);
			$controller=$f['sources']['Controller/ApiController']['source'];self::assertStringContainsString('getFormIfAllowed($formId,Constants::PERMISSION_RESULTS)',FormsBehavior::body($controller,'getSubmissions'));self::assertStringContainsString("'anon-user-'",FormsBehavior::body($controller,'getSubmission'));
			foreach(['SubmissionMapper','AnswerMapper','QuestionMapper','OptionMapper']as$mapper){$source=$f['sources']['Db/'.$mapper]['source'];self::assertStringNotContainsString('IUserSession',FormsBehavior::body($source,'__construct'));}
			$header=$f['sources']['Db/SubmissionMapper']['source'];self::assertStringContainsString('if($userId)',FormsBehavior::body($header,'findByForm'));self::assertStringContainsString('setMaxResults($limit)',FormsBehavior::body($header,'findByForm'));self::assertSame(['formId','userId?','searchString?','limit?','offset?'],$f['classes']['OCA\\Forms\\Db\\SubmissionMapper']['methods']['findByForm']);
			self::assertStringContainsString('if(!$loadDeleted)',FormsBehavior::body($f['sources']['Db/QuestionMapper']['source'],'findByForm'));
			$answers=$f['sources']['Db/AnswerMapper']['source'];self::assertSame(version_compare($version,'5.4.1','>='),str_contains($answers,'function findBySubmissions('));
			self::assertStringContainsString('finally{return$submissionList;}',FormsBehavior::body($f['sources']['Service/SubmissionService']['source'],'getSubmissions'));
		}
	}
}
