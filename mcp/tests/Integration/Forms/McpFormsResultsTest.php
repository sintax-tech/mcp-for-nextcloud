<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{McpClient,LoginFactory,Http};
use OCA\Mcp\Service\GrantPolicy;
use OCP\Server;

/** FO2 runtime proofs use real authenticated MCP/Forms/Files, never substituted source fixtures. */
final class McpFormsResultsTest extends IntegrationTestCase {
	/** Requires the opt-in pinned Forms cell only.
	 * @return void
	 */
	protected function setUp(): void { parent::setUp();if(getenv('MCP_IT_WITH_FORMS')!=='1'){self::markTestSkipped('Requires pinned Forms cell.');} }
	/** Compares native own/all modes, active-question omission and identity projection in both auth transports.
	 * @return void
	 */
	public function testNativeOwnAllInactiveOmissionAndAnonymousBasicBearer(): void {
		$s=FormsResultsScene::read();$api=new FormsApiScene();
		foreach([FormsScene::OWNER,FormsScene::READER,FormsScene::OUTSIDE,FormsScene::EDITOR]as$uid){
			$native=$api->api($uid,'GET',"forms/{$s['form']}/submissions");$expected=array_column($native['submissions'],'id');sort($expected);
			foreach(['basic','bearer']as$kind){
				$client=new McpClient($kind==='basic'?LoginFactory::basic($uid):LoginFactory::bearer($uid));$reply=$client->tool('forms_list_submissions',['form_id'=>$s['form']]);self::assertTrue($reply->ok(),$reply->describe());$data=$reply->json()['untrusted'];$ids=array_column($data['submissions'],'id');sort($ids);self::assertSame($expected,$ids);self::assertSame(in_array($uid,[FormsScene::OWNER,FormsScene::EDITOR],true)?'all':'own',$data['accessMode']);
				self::assertStringNotContainsString('INACTIVE MUST NOT ESCAPE',$reply->text());self::assertStringNotContainsString('userId',$reply->text());self::assertStringNotContainsString('userDisplayName',$reply->text());
				foreach($data['submissions']as$row){foreach($row['answers']as$answer){self::assertNotSame($s['removedQuestion'],$answer['questionId']);}$single=$client->tool('forms_read_submission',['form_id'=>$s['form'],'submission_id'=>$row['id']]);self::assertTrue($single->ok(),$single->describe());self::assertSame($row,$single->json()['untrusted']['submission']);}
			}
		}
		$owner=new McpClient(LoginFactory::basic(FormsScene::OWNER));$anonymous=$owner->tool('forms_list_submissions',['form_id'=>$s['anonymousForm']]);self::assertTrue($anonymous->ok(),$anonymous->describe());self::assertTrue($anonymous->json()['untrusted']['submissions'][0]['anonymous']);self::assertStringNotContainsString('anon-user-',$anonymous->text());
		self::assertTrue((new McpClient(LoginFactory::basic(FormsScene::READER)))->tool('forms_list_submissions',['form_id'=>$s['anonymousForm']])->isError());
	}
	/** Shows only authorized native Files metadata and proves the original upload bytes by test-only OCP reads.
	 * @return void
	 */
	public function testUploadsNeedIndependentFilesGrantAndActorNode(): void {
		$s=FormsResultsScene::read();$policy=Server::get(GrantPolicy::class);$args=['form_id'=>$s['form'],'submission_id'=>$s['submissions'][FormsScene::OWNER]];
		$owner=new McpClient(LoginFactory::basic(FormsScene::OWNER));$reply=$owner->tool('forms_read_submission',$args);self::assertTrue($reply->ok(),$reply->describe());$files=array_values(array_filter($reply->json()['untrusted']['submission']['answers'],static fn(array $a):bool=>isset($a['attachment'])));self::assertCount(1,$files);$f=$files[0]['attachment'];self::assertSame($s['fileId'],$f['fileId']);self::assertSame(['fileId','name','size','mimeType','url'],array_keys($f));self::assertStringContainsString((string)$s['fileId'],$f['url']);
		$node=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder(FormsScene::OWNER)->getById($s['fileId'])[0];self::assertSame(hash('sha256',FormsResultsScene::CONTENT),hash('sha256',$node->getContent()));
		$policy->setGrant(FormsScene::OWNER,'files','read',false);
		try{$hidden=(new McpClient(LoginFactory::basic(FormsScene::OWNER)))->tool('forms_read_submission',$args);self::assertTrue($hidden->ok(),$hidden->describe());self::assertStringNotContainsString('"attachment"',$hidden->text());self::assertStringNotContainsString('synthetic.txt',$hidden->text());self::assertSame(1,count($hidden->json()['untrusted']['submission']['answers']));}
		finally{$policy->setGrant(FormsScene::OWNER,'files','read',true);}
	}
	/** Keeps exact results independent of structure read and invalidates cursors after native RESULTS revocation.
	 * @return void
	 */
	public function testExactGrantCursorAndNativeRevocation(): void {
		$s=FormsResultsScene::read();$policy=Server::get(GrantPolicy::class);$api=new FormsApiScene();$uid=FormsScene::EDITOR;
		$policy->setGrant($uid,'forms','read',false);
		try{$client=new McpClient(LoginFactory::basic($uid));$first=$client->tool('forms_list_submissions',['form_id'=>$s['form'],'limit'=>1]);self::assertTrue($first->ok(),$first->describe());$cursor=$first->json()['nextCursor'];self::assertIsString($cursor);
			self::assertTrue($client->tool('forms_list_submissions',['form_id'=>$s['form'],'limit'=>2,'cursor'=>$cursor])->isError());
			$last=$client->tool('forms_list_submissions',['form_id'=>$s['form'],'limit'=>1,'cursor'=>$cursor]);self::assertTrue($last->ok(),$last->describe());
			$api->api(FormsScene::OWNER,'DELETE',"forms/{$s['form']}/shares/{$s['editorShare']}");
			try{self::assertTrue($client->tool('forms_list_submissions',['form_id'=>$s['form'],'limit'=>1,'cursor'=>$cursor])->isError());}
			finally{$share=$api->share($s['form'],0,$uid,['submit','results']);$s['editorShare']=$share['id'];file_put_contents('/tmp/mcp-it-forms-results.json',json_encode($s,JSON_THROW_ON_ERROR));}
		}finally{$policy->setGrant($uid,'forms','read',true);}
		$policy->setGrant(FormsScene::READER,'forms','results',false);
		try{self::assertFalse((new McpClient(LoginFactory::basic(FormsScene::READER)))->tool('forms_list_submissions',['form_id'=>$s['form']])->ok());}
		finally{$policy->setGrant(FormsScene::READER,'forms','results',true);}
	}
	/** Exercises the actual FO2 native access pipeline against cached/null/other/lazy service contexts in fresh processes.
	 * @return void
	 */
	public function testRealResultsContextsAndReadOnlyData(): void {
		$scenarios=['cold','warm','null','other'];if(PHP_VERSION_ID>=80400&&property_exists(\OC\AppFramework\Utility\SimpleContainer::class,'useLazyObjects')){$scenarios[]='lazy';}
		$before=FormsState::database();$files=FormsState::files();
		foreach($scenarios as$scenario){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/ci/forms/results-context.php').' '.escapeshellarg($scenario).' 2>&1';$lines=[];exec($cmd,$lines,$exit);self::assertSame(0,$exit,implode("\n",$lines));$result=json_decode(implode("\n",$lines),true);self::assertSame(!in_array($scenario,['null','other'],true),$result['accepted']);file_put_contents('/tmp/mcp-forms-results-context.jsonl',json_encode($result,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);}
		$s=FormsResultsScene::read();$client=new McpClient(LoginFactory::bearer(FormsScene::OWNER));self::assertTrue($client->tool('forms_list_submissions',['form_id'=>$s['form']])->ok());
		self::assertSame($before,FormsState::database());self::assertSame($files,FormsState::files());file_put_contents('/tmp/mcp-forms-results-state.json',json_encode(['database'=>$before,'filesSha256'=>$files,'unchanged'=>true],JSON_THROW_ON_ERROR));
	}
	/** Real scope/tag gates omit the same association uniformly; native viewer links open without fetching contents.
	 * @return void
	 */
	public function testNativeViewerScopeAndHiddenTagOmission(): void {
		$s=FormsResultsScene::read();$uid=FormsScene::OWNER;$args=['form_id'=>$s['form'],'submission_id'=>$s['submissions'][$uid]];$login=LoginFactory::basic($uid);$client=new McpClient($login);
		$visible=$client->tool('forms_read_submission',$args);self::assertTrue($visible->ok(),$visible->describe());$answers=$visible->json()['untrusted']['submission']['answers'];$file=array_values(array_filter($answers,static fn(array$a):bool=>isset($a['attachment'])))[0]['attachment'];
		$viewer=Http::request('GET',$file['url'],['Authorization: '.$login->authorization()]);self::assertContains($viewer['status'],[200,302,303]);self::assertStringNotContainsString('/login',$viewer['headers']);
		$writer=Server::get(\OCA\Mcp\Service\Policy\PolicyWriter::class);$states=Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
		$writer->setResourceScope($states->state()['revision'],'admin',$uid,'files',[]);
		try{$scoped=$client->tool('forms_read_submission',$args);self::assertTrue($scoped->ok(),$scoped->describe());self::assertCount(count($answers)-1,$scoped->json()['untrusted']['submission']['answers']);self::assertStringNotContainsString('"attachment"',$scoped->text());}
		finally{$writer->setResourceScope($states->state()['revision'],'admin',$uid,'files',null);}
		$guard=Server::get(\OCA\Mcp\Service\Scope\FilesAccessGuard::class);$before=$guard->getHiddenTagIds();$tags=Server::get(\OCP\SystemTag\ISystemTagManager::class);$mapper=Server::get(\OCP\SystemTag\ISystemTagObjectMapper::class);$tag=$tags->createTag('fo2-hidden-'.bin2hex(random_bytes(4)),true,true);
		try{$mapper->assignTags((string)$s['fileId'],'files',[$tag->getId()]);$guard->setHiddenTagIds([...$before,$tag->getId()]);usleep(4000000);
			$hidden=$client->tool('forms_read_submission',$args);self::assertTrue($hidden->ok(),$hidden->describe());self::assertSame($scoped->json(),$hidden->json());
		}finally{$mapper->unassignTags((string)$s['fileId'],'files',[$tag->getId()]);$guard->setHiddenTagIds($before);$tags->deleteTags([$tag->getId()]);usleep(4000000);}
		$restored=$client->tool('forms_read_submission',$args);self::assertTrue($restored->ok(),$restored->describe());self::assertSame($visible->json(),$restored->json());
	}

}
