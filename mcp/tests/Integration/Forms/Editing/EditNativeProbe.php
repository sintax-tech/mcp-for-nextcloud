<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Tools\Forms\Editing\{EditNativeFactory,EditContext,EditState,EditPlan,EditRequest,EditWriter};
use OCA\Mcp\Tools\Forms\Creation\{CreateNativeArguments,CreateNativeThrottle};
use OCA\Mcp\Tests\Integration\Forms\FormsScene;
use OCP\Server;

/** Real native graph/readback probes with explicitly fixture-only injected final-write and guard-to-sink barriers. */
final class EditNativeProbe {
	/** Exercises a single isolated capture or native-failure case; returns only synthetic identifiers and observations.
	 * @param string $case Exact probe scenario.
	 * @param int $form Synthetic form previously created by OCS.
	 * @param int $question Synthetic question.
	 * @param int $option Option ID or zero for question editing.
	 * @return array<string,mixed> Observations without user data or authentication material.
	 */
	public function run(string $case,int $form,int $question,int $option):array{
		$apps=Server::get(\OCP\App\IAppManager::class);$apps->loadApp('forms');$session=Server::get(\OCP\IUserSession::class);$users=Server::get(\OCP\IUserManager::class);$actor=$users->get(FormsScene::OWNER);$other=$users->get(FormsScene::OUTSIDE);
		$lazy=$case==='lazy';if($lazy){\OC\AppFramework\Utility\SimpleContainer::$useLazyObjects=true;}
		$negative=str_starts_with($case,'forms-');$capture=str_ends_with($case,'-null')?null:(str_ends_with($case,'-other')?$other:$actor);
		$session->setUser($negative?$capture:($lazy?null:$actor));$service=Server::get(\OCA\Forms\Service\FormsService::class);
		$initialLazy=$lazy&&(new \ReflectionClass($service))->isUninitializedLazyObject($service);
		if(!$lazy){$sentinel=new \OCA\Forms\Db\Form();$sentinel->setId(0);$sentinel->setOwnerId(FormsScene::OWNER);$sentinel->setAccess(['permitAllUsers'=>false,'showToAllUsers'=>false]);$service->getPermissions($sentinel);}
		if(Server::get(\OCA\Forms\Db\ShareMapper::class)->findByForm(0)!==[]){throw new \RuntimeException('Sentinel shares must be absent');}
		$cached=null;$config=null;
		if(str_starts_with($case,'controller-')||$case==='warm'){$session->setUser($capture);$cached=Server::get(\OCA\Forms\Controller\ApiController::class);try{$cached->getForms();}catch(\Throwable $e){if($capture!==null){throw $e;}}}
		if(str_starts_with($case,'config-')){$session->setUser($capture);$config=Server::get(\OCA\Forms\Service\ConfigService::class);$config->canCreateForms();}
		if(str_starts_with($case,'submission-')){$session->setUser($capture);Server::get(\OCA\Forms\Service\SubmissionService::class);}
		$session->setUser($actor);$factory=Server::get(EditNativeFactory::class);
		try{$c=$factory->open(FormsScene::OWNER);}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e){if(!$negative){throw $e;}return ['scenario'=>$case,'accepted'=>false,'sentinelHasNoShares'=>true];}
		if($negative){throw new \RuntimeException('Captured wrong FormsService was accepted');}
		if($c->controller===$cached||$c->config===$config){throw new \RuntimeException('Cached captured graph reused');}
		if($case==='scoped-config'){return EditScopedConfigProbe::run($c);}
		$r=EditRequest::of(['form_id'=>$form,'question_id'=>$question]+($option?['option_id'=>$option]:[])+['changes'=>['text'=>'Probe '.$case]],$option!==0);
		$state=Server::get(EditState::class);$plans=Server::get(EditPlan::class);$before=$state->read($c,$r,FormsScene::OWNER);$effect=$state->effect($c,$r,$before);$plan=$plans->of($r,$c,$before,$effect,FormsScene::OWNER);

		if($case==='external-revoke'){
			$policy=Server::get(\OCA\Mcp\Service\GrantPolicy::class);
			try{return $policy->within(FormsScene::OWNER,function()use($policy,$factory,$state,$plans,$c,$r,$before,$effect,$plan):array{
				if(!$policy->granted(FormsScene::OWNER,'forms','edit')){throw new \RuntimeException('Outer operation must start granted');}
				$writer=new EditWriter(new EditRevokingFactory($factory),$state,$plans,Server::get(CreateNativeThrottle::class));
				try{$writer->write($c,$r,$before,$effect,$plan,FormsScene::OWNER);throw new \RuntimeException('Externally revoked grant reached sink');}
				catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){
					if(!$policy->granted(FormsScene::OWNER,'forms','edit')||$state->read($c,$r,FormsScene::OWNER)!==$before){throw new \RuntimeException('Outer snapshot or zero-effect state was not preserved');}
					return ['scenario'=>'external-revoke','accepted'=>false,'outerSnapshotStillGranted'=>true,'renewedBoundaryRefused'=>true,'nativeStateUnchanged'=>true];
				}
			});}finally{$policy->setGrant(FormsScene::OWNER,'forms','edit',true);}
		}
		if(in_array($case,['partial','deleted-race'],true)){
			$container=(new \OCP\AppFramework\App('forms'))->getContainer();$get=static fn(string $type):object=>$container->get($type);
			$native=CreateNativeArguments::submission($c->version,$get,$session,$c->service);$submission=new \OCA\Forms\Service\SubmissionService(...$native);
			$args=CreateNativeArguments::controller($c->version,$get,$session,['service'=>$c->service,'mapper'=>$get(\OCA\Forms\Db\FormMapper::class)],$c->config,$submission,$native);
			if($case==='partial'){$args['formMapper']=new EditFaultFormMapper();}
			else{$args['questionMapper']=new EditBarrierQuestionMapper(Server::get(\OCP\IDBConnection::class),Server::get(\OCA\Forms\Db\OptionMapper::class),$form,FormsScene::OWNER);}
			$c=new EditContext($c->version,new \OCA\Forms\Controller\ApiController(...$args),$c->config,$c->service,$c->questions,$c->options,$c->shares);
		}
		if($case==='no-native-create'){return EditNoCreateProbe::run($c,static fn():array=>(new EditWriter($factory,$state,$plans,Server::get(CreateNativeThrottle::class)))->write($c,$r,$before,$effect,$plan,FormsScene::OWNER));}
		$receipt=(new EditWriter($factory,$state,$plans,Server::get(CreateNativeThrottle::class)))->write($c,$r,$before,$effect,$plan,FormsScene::OWNER);
		$expected=match($case){'partial'=>'partial','deleted-race'=>'uncertain',default=>'applied'};
		if($receipt['status']!==$expected){throw new \RuntimeException('Unexpected receipt '.json_encode($receipt,JSON_THROW_ON_ERROR));}
		if($case==='deleted-race'){
			$nativeRow=Server::get(\OCA\Forms\Db\QuestionMapper::class)->findById($question);
			if($nativeRow->getOrder()!==0||isset($receipt['observed'])){throw new \RuntimeException('Deleted active-readback must not expose old row');}
		}
		if($case==='partial'&&$receipt['lock']['actorLockObserved']){throw new \RuntimeException('Final fault must leave lock unconfirmed');}
		return ['scenario'=>$case,'accepted'=>true,'status'=>$receipt['status'],'actorLockObserved'=>$receipt['lock']['actorLockObserved']??false,'initialLazy'=>$initialLazy,'nativeConfigFresh'=>$c->config!==$config,'nativeControllerFresh'=>$c->controller!==$cached,'fixtureInjected'=>$case==='partial','secondProcessBarrier'=>$case==='deleted-race'];
	}
}
