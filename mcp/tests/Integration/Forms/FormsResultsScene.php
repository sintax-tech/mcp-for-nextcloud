<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

use OCA\Mcp\Tests\Integration\Support\{Http,LoginFactory};
use OCP\Server;

/** Synthetic FO2 setup through native APIs; no product writes or database fixtures masquerading as OCS. */
final class FormsResultsScene {
	public const CONTENT = 'FO2 synthetic upload bytes, never real user data.';
	/** Reads only identifiers of this cell's synthetic scene.
	 * @return array Scene identifiers without tokens.
	 */
	public static function read(): array { return json_decode(file_get_contents('/tmp/mcp-it-forms-results.json'),true,512,JSON_THROW_ON_ERROR); }
	/** Creates identified, historical, anonymous and upload cases through Forms OCS before read-only snapshots.
	 * @return void
	 */
	public function seed(): void {
		$apps=Server::get(\OCP\App\IAppManager::class);$apps->loadApp('forms');$policy=Server::get(\OCA\Mcp\Service\GrantPolicy::class);
		foreach([FormsScene::OWNER,FormsScene::READER,FormsScene::EDITOR,FormsScene::OUTSIDE]as$uid){$policy->setGrant($uid,'forms','results',true);}
		$api=new FormsApiScene();$id=$api->form('FO2 synthetic results',['submitMultiple'=>true]);
		$share=$api->share($id,0,FormsScene::READER,['submit']);$outside=$api->share($id,0,FormsScene::OUTSIDE,['submit']);$editor=$api->share($id,0,FormsScene::EDITOR,['submit','results']);
		$q=$api->api(FormsScene::OWNER,'POST',"forms/$id/questions",['type'=>'short','text'=>'Active synthetic question']);
		$old=$api->api(FormsScene::OWNER,'POST',"forms/$id/questions",['type'=>'short','text'=>'Later removed question']);
		$file=$api->api(FormsScene::OWNER,'POST',"forms/$id/questions",['type'=>'file','text'=>'Synthetic file']);
		$uploaded=$this->upload($id,(int)$file['id']);
		$api->api(FormsScene::OWNER,'POST',"forms/$id/submissions",['answers'=>[$q['id']=>['001'], $old['id']=>['INACTIVE MUST NOT ESCAPE'], $file['id']=>[$uploaded]]]);
		foreach([FormsScene::READER,FormsScene::OUTSIDE]as$uid){$api->api($uid,'POST',"forms/$id/submissions",['answers'=>[$q['id']=>['synthetic answer'], $old['id']=>['INACTIVE MUST NOT ESCAPE']]]);}
		$api->api(FormsScene::OWNER,'DELETE',"forms/$id/questions/{$old['id']}");
		$api->api(FormsScene::OWNER,'DELETE',"forms/$id/shares/{$outside['id']}");
		$anonymous=$api->form('FO2 anonymous synthetic',['isAnonymous'=>true]);$api->share($anonymous,0,FormsScene::READER,['submit']);$aq=$api->api(FormsScene::OWNER,'POST',"forms/$anonymous/questions",['type'=>'short','text'=>'Anonymous input']);
		$api->api(FormsScene::READER,'POST',"forms/$anonymous/submissions",['answers'=>[$aq['id']=>['self-written name is not anonymized']]]);
		$submissions=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->findByForm($id,null,null,50,0);$ids=[];
		foreach($submissions as $submission){$ids[$submission->getUserId()]=(int)$submission->getId();}
		$fileId=null;foreach(Server::get(\OCA\Forms\Db\AnswerMapper::class)->findBySubmission($ids[FormsScene::OWNER])as$answer){if($answer->getFileId()!==null){$fileId=(int)$answer->getFileId();}}
		if($fileId===null){throw new \RuntimeException('Native upload answer missing');}
		file_put_contents('/tmp/mcp-it-forms-results.json',json_encode(['form'=>$id,'activeQuestion'=>(int)$q['id'],'removedQuestion'=>(int)$old['id'],'fileQuestion'=>(int)$file['id'],'fileId'=>$fileId,'submissions'=>$ids,'editorShare'=>$editor['id'],'anonymousForm'=>$anonymous],JSON_THROW_ON_ERROR));
		echo "FO2 scene created through native OCS; inactive question removed after real submissions; no credentials logged\n";
	}
	/** Uploads actual synthetic multipart bytes through the native endpoint and keeps its token only in fixture memory.
	 * @param int $formId Synthetic form.
	 * @param int $questionId Native file question.
	 * @return array Native temporary upload metadata for the immediately following submission.
	 */
	private function upload(int $formId,int $questionId): array {
		$login=LoginFactory::basic(FormsScene::OWNER);$boundary='fo2'.bin2hex(random_bytes(8));
		$body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"synthetic.txt\"\r\nContent-Type: text/plain\r\n\r\n".self::CONTENT."\r\n--".$boundary."--\r\n";
		$reply=Http::request('POST',Http::BASE.'/ocs/v2.php/apps/forms/api/v3/forms/'.$formId.'/submissions/files/'.$questionId,['Authorization: '.$login->authorization(),'OCS-APIRequest: true','Accept: application/json','Content-Type: multipart/form-data; boundary='.$boundary],$body);
		if(!in_array($reply['status'],[200,201],true)){throw new \RuntimeException('Synthetic native upload refused, status='.$reply['status']);}
		$data=json_decode($reply['body'],true,512,JSON_THROW_ON_ERROR)['ocs']['data'];if(!isset($data[0]['uploadToken'])){throw new \RuntimeException('Native upload metadata missing');}return $data[0];
	}
}
