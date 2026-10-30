<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Server;

/** P1–P9 on real pinned Forms services/entities and authenticated MCP, never a mock runtime proof. */
final class McpFormsTest extends IntegrationTestCase {
	/** Requires the explicitly provisioned Forms cell; unsupported ordinary cells do not invent fixture state.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_FORMS') !== '1') { self::markTestSkipped('Requires the explicit Forms cell.'); }
	}

	/** Checks native context cold/warm, captured null/other, and lazy when the core/PHP support it.
	 * @return void
	 */
	public function testRealCapturedContextsAndSentinelPremise(): void {
		foreach (['cold','warm','null','other', ...((PHP_VERSION_ID >= 80400 && property_exists(\OC\AppFramework\Utility\SimpleContainer::class,'useLazyObjects')) ? ['lazy'] : [])] as $scenario) {
			$result=$this->script('context-probe.php',$scenario);
			self::assertSame(!in_array($scenario,['null','other'],true),$result['accepted']);
			self::assertTrue($result['sameNativeInstance']); self::assertTrue($result['sentinelWithoutShares']);
			if($scenario==='lazy'){self::assertTrue($result['initiallyUninitialized']);}
			file_put_contents('/tmp/mcp-forms-context-results.jsonl',json_encode($result,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
		}
	}

	/** Compares discovery with the native OCS owner list plus reauthorized shared(false) for the same user in both auth modes.
	 * @return void
	 */
	public function testBasicBearerDiscoveryAndReadByIdBoundaries(): void {
		$scene=FormsScene::read();
		foreach([FormsScene::OWNER,FormsScene::READER] as $uid){
			$expected=$this->script('native-expected.php',$uid);
			foreach(['basic','bearer'] as $kind){
				$client=new McpClient($kind==='basic'?LoginFactory::basic($uid):LoginFactory::bearer($uid));
				$reply=$client->tool('forms_list_forms',['limit'=>200]);self::assertTrue($reply->ok(),$reply->describe());
				$forms=$reply->json()['untrusted']['forms'];$ids=array_column($forms,'id');$sorted=$ids;sort($sorted);self::assertSame($expected['ids'],$sorted);
				self::assertContains($scene['permit'],$ids);self::assertFalse($reply->json()['hasMore']);
				if($uid===FormsScene::READER){
					self::assertContains($scene['group'],$ids);self::assertContains($scene['direct'],$ids);self::assertNotContains($scene['private'],$ids);self::assertNotContains($scene['expired'],$ids);self::assertNotContains($scene['closed'],$ids);
					foreach(['expired','closed'] as $key){$read=$client->tool('forms_read_form',['form_id'=>$scene[$key]]);self::assertTrue($read->ok(),$read->describe());}
					$denied=$client->tool('forms_read_form',['form_id'=>$scene['private']]);$missing=$client->tool('forms_read_form',['form_id'=>2147483647]);
					self::assertTrue($denied->isError());self::assertTrue($missing->isError());self::assertSame($denied->text(),$missing->text());
				}
				self::assertStringNotContainsString('submissionCount',$reply->text());self::assertStringNotContainsString('ownerId',$reply->text());
				// Ordered by the same complete pair, including id tie breaking whenever native timestamps tie.
				$pairs=array_map(static fn(array $form):array=>[$form['lastUpdated'],$form['id']],$forms);$ordered=$pairs;rsort($ordered);self::assertSame($ordered,$pairs);
			}
		}
	}

	/** Proves whitelist and links; the editor-without-submit is an explicitly artificial existing-share mapper fixture.
	 * @return void
	 */
	public function testStructureAndPublicLinksUseNativeEditorOnly(): void {
		$scene=FormsScene::read();
		$artificial = Server::get(\OCA\Forms\Db\ShareMapper::class)->findById($scene['artificialEditorShare']);
		self::assertSame(['edit', 'results'], $artificial->getPermissions(), 'Artificial existing share; OCS creation itself requires submit');
		foreach([FormsScene::OWNER,FormsScene::READER,FormsScene::EDITOR] as $uid){
			$client=new McpClient(LoginFactory::basic($uid));$reply=$client->tool('forms_read_form',['form_id'=>$scene['direct']]);self::assertTrue($reply->ok(),$reply->describe());
			$form=$reply->json()['untrusted']['form'];self::assertArrayHasKey('notice',$reply->json()['untrusted']);
			self::assertCount(1,$form['questions']);self::assertCount(2,$form['questions'][0]['options']);self::assertStringContainsString('<img',$form['submissionMessage']);
			foreach(['ownerId','hash','fileId','filePath','fileFormat','shares','submissionCount','ownSubmissionCount','confirmationEmailQuestionId'] as $field){self::assertArrayNotHasKey($field,$form);}
			if($uid===FormsScene::READER){
				self::assertNotContains('results',$form['permissions']);self::assertArrayHasKey('results',$form['links']['direct']);self::assertArrayNotHasKey('public',$form['links']);self::assertArrayNotHasKey('maxSubmissions',$form);
			}else{
				self::assertArrayHasKey('maxSubmissions',$form);self::assertCount(2,$form['links']['public']);
				self::assertSame(1,count(array_filter($form['links']['public'],static fn(array $link):bool=>isset($link['embedUrl']))));
				foreach($form['links']['public'] as $link){self::assertStringContainsString('/apps/forms/s/',$link['url']);self::assertArrayNotHasKey('shareWith',$link);}
				if($uid===FormsScene::EDITOR){self::assertNotContains('submit',$form['permissions']);}
			}
		}
		$archived=(new McpClient(LoginFactory::bearer(FormsScene::EDITOR)))->tool('forms_read_form',['form_id'=>$scene['archived']]);self::assertTrue($archived->ok(),$archived->describe());
		$form=$archived->json()['untrusted']['form'];self::assertSame(2,$form['state']);self::assertArrayHasKey('maxSubmissions',$form);self::assertCount(1,$form['links']['public']);self::assertArrayHasKey('embedUrl',$form['links']['public'][0]);
	}

	/** Checks cursor tampering, actor/page binding and a real native share revocation between pages.
	 * @return void
	 */
	public function testCursorAndNativeRevocation(): void {
		$scene=FormsScene::read();$api=new FormsApiScene();$client=new McpClient(LoginFactory::basic(FormsScene::READER));
		$first=$client->tool('forms_list_forms',['limit'=>1]);self::assertTrue($first->ok(),$first->describe());$cursor=$first->json()['nextCursor'];self::assertIsString($cursor);
		foreach([['limit'=>1,'cursor'=>$cursor.'x'],['limit'=>2,'cursor'=>$cursor]] as $args){self::assertTrue($client->tool('forms_list_forms',$args)->isError());}
		self::assertTrue((new McpClient(LoginFactory::basic(FormsScene::OWNER)))->tool('forms_list_forms',['limit'=>1,'cursor'=>$cursor])->isError());
		$api->api(FormsScene::OWNER,'DELETE',"forms/{$scene['direct']}/shares/{$scene['directShare']}");
		try{self::assertTrue($client->tool('forms_list_forms',['limit'=>1,'cursor'=>$cursor])->isError());self::assertTrue($client->tool('forms_read_form',['form_id'=>$scene['direct']])->isError());}
		finally{$share=$api->share($scene['direct'],0,FormsScene::READER,['submit']);$scene['directShare']=$share['id'];file_put_contents('/tmp/mcp-it-forms-scene.json',json_encode($scene,JSON_THROW_ON_ERROR));}
	}

	/** Checks grant and native per-user app availability on the actual tools/list and tools/call transport.
	 * @return void
	 */
	public function testGrantAndAppAvailability(): void {
		$login=LoginFactory::basic(FormsScene::OWNER);$policy=Server::get(GrantPolicy::class);$apps=Server::get(\OCP\App\IAppManager::class);
		$policy->setGrant(FormsScene::OWNER,'forms','read',false);
		try{self::assertNotContains('forms_read_form',$this->tools($login));self::assertFalse((new McpClient($login))->tool('forms_list_forms')->ok());}
		finally{$policy->setGrant(FormsScene::OWNER,'forms','read',true);}
		// Forms explicitly prevents group restriction: runtime proves global off, while the per-user false gate is unit tested.
		$config = Server::get(\OCP\IConfig::class);
		$before = $config->getAppValue('forms', 'enabled');
		self::assertSame('yes', $before, 'This isolated cell starts with globally enabled Forms');
		$apps->disableApp('forms');
		// NC35 AppConfig caches across web/CLI for LOCAL_CACHE_TTL=3 seconds; observe the settled native state.
		usleep(4000000);
		try {
			self::assertNotContains('forms_list_forms', $this->tools($login));
			$hidden = (new McpClient($login))->tool('forms_read_form', ['form_id' => FormsScene::read()['direct']]);
			self::assertSame(-32602, $hidden->body['error']['code'] ?? null, $hidden->describe());
			self::assertSame('unknown tool; use one listed by tools/list', $hidden->body['error']['data']['rule'] ?? null);
		} finally { $apps->enableApp('forms'); usleep(4000000); }
		self::assertSame($before, $config->getAppValue('forms', 'enabled'));
		self::assertContains('forms_list_forms', $this->tools($login));
	}

	/** Proves that successful/denied authenticated reads and native-context probes leave all Forms rows and owner files intact.
	 * @return void
	 */
	public function testReadOnlyDatabaseAndFileIntegrity(): void {
		$scene=FormsScene::read();$files=FormsState::files();$before=FormsState::database();self::assertNotEmpty($before);
		$client=new McpClient(LoginFactory::bearer(FormsScene::READER));
		foreach(['forms_list_forms','forms_read_form'] as $tool){$reply=$client->tool($tool,$tool==='forms_read_form'?['form_id'=>$scene['direct']]:[]);self::assertTrue($reply->ok(),$reply->describe());}
		self::assertTrue($client->tool('forms_read_form',['form_id'=>$scene['private']])->isError());
		self::assertSame($before,FormsState::database());self::assertSame($files,FormsState::files());
		file_put_contents('/tmp/mcp-forms-state-proof.json',json_encode(['tables'=>$before,'filesSha256'=>$files,'unchanged'=>true],JSON_THROW_ON_ERROR));
	}

	/** Runs a fresh process in the existing pinned Nextcloud container, never host PHP or modified DI.
	 * @param string $script Focused fixture script basename.
	 * @param string $argument Scenario or synthetic actor.
	 * @return array<string,mixed> Machine-readable proof result.
	 */
	private function script(string $script,string $argument):array{
		$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/ci/forms/'.$script).' '.escapeshellarg($argument).' 2>&1';$lines=[];exec($command,$lines,$exit);
		self::assertSame(0,$exit,implode("\n",$lines));$decoded=json_decode(implode("\n",$lines),true);self::assertIsArray($decoded);return $decoded;
	}

	/** Reads the actual MCP tool registry through authentication, without calling a product handler directly.
	 * @param object $login Synthetic credential.
	 * @return list<string> Tool names exposed to this actor.
	 */
	private function tools(object $login):array{
		$reply=Http::request('POST',Http::BASE.'/index.php/apps/mcp/',['Authorization: '.$login->authorization(),'Content-Type: application/json','Accept: application/json, text/event-stream'],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],JSON_THROW_ON_ERROR));
		self::assertSame(200,$reply['status']);return array_column(json_decode($reply['body'],true)['result']['tools'],'name');
	}
}
