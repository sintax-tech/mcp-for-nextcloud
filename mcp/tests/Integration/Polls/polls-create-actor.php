<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/PollsContentHttpEnvironment.php';
PollsContentHttpEnvironment::prepare();
require dirname(__DIR__) . '/bootstrap.php';

/** Separate endpoint-like real-native creation probes; no OC_CONSOLE or persistent listener modifications. */
final class PollsCreateActorProcess {
	/**
	 * Binds a real fixture user, then observes native readbacks or the actual handler with poisoned context/events.
	 * @param string $uid fixture actor
	 * @param string $action native/observe/event
	 * @param array<string,mixed> $in scoped test input
	 * @return array<string,mixed> observations, never credentials
	 */
	public static function run(string $uid,string $action,array $in): array {
		if(!in_array($uid,\OCA\Mcp\Tests\Integration\Polls\PollsScene::USERS,true)){throw new RuntimeException('Not a fixture actor');}
		$session=\OCP\Server::get(\OCP\IUserSession::class);$users=\OCP\Server::get(\OCP\IUserManager::class);
		$session->setUser($users->get($in['warm']??$uid));\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('polls');
		$native=\OCP\Server::get(\OCA\Polls\UserSession::class);$native->getCurrentUser();$native->getCurrentUserId();
		$session->setUser(!empty($in['noLogin'])?null:$users->get($in['session']??$uid));
		if($action==='native'){
			$native->cleanSession();\OCP\Server::get(\OCA\Mcp\Tools\Polls\PollsSessionGuard::class)->native($native,$uid);
			$services=\OCP\Server::get(\OCA\Mcp\Tools\Polls\Create\CreateNative::class);
			$poll=$services->polls($native)->get($in['poll_id']);
			if($poll->getOwner()!==$uid||!$poll->getIsAllowed(\OCA\Polls\Db\Poll::PERMISSION_POLL_EDIT)){throw new RuntimeException('Not fixture owner');}
			$nine=(new ReflectionMethod(\OCA\Polls\Service\PollService::class,'add'))->getNumberOfParameters()===4;
			$options=[];foreach($services->options($native)->list($in['poll_id'])as$option){$options[]=['id'=>$option->getId(),'text'=>$option->getPollOptionText(),'timestamp'=>$option->getTimestamp(),'duration'=>$option->getDuration()];}
			$db=\OCP\Server::get(\OCP\IDBConnection::class);$qb=$db->getQueryBuilder();
			$qb->select('*')->from('polls_polls')->where($qb->expr()->eq('id',$qb->createNamedParameter($in['poll_id'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
			$result=$qb->executeQuery();$raw=$result->fetch();$result->closeCursor();
			$out=['owner'=>$poll->getOwner(),'type'=>$poll->getType(),'title'=>$poll->getTitle(),'observable'=>\OCA\Mcp\Tools\Polls\Create\CreateDefaults::read($poll,$nine),
				'raw'=>array_intersect_key($raw,array_flip(['description','allow_maybe','allow_comment','hide_booked_up','use_no','misc_settings','timezone_name'])), 'options'=>$options];
			$native->cleanSession();return $out;
		}
		$store=\OCP\Server::get(\OCP\ISession::class);$store->set('ncPollsPublicToken','synthetic-po3-old-token');
		if(defined(\OCA\Polls\UserSession::class.'::SESSION_KEY_USER_ID')){$store->set('ncPollsUserId',\OCA\Mcp\Tests\Integration\Polls\PollsScene::OUTSIDER);$store->set('ncPollsShareType','public');}
		$module=\OCP\Server::get(\OCA\Mcp\Tools\Polls\PollsModule::class);$out=[];
		if(in_array($action,['event','drift'],true)){
			$native->cleanSession();$plan=$module->preview('polls_create_poll',$in['arguments'],$uid);
			$eventClass=($in['event']??'poll')==='poll'?\OCA\Polls\Event\PollCreatedEvent::class:\OCA\Polls\Event\OptionCreatedEvent::class;
			$observed=[];\OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener($eventClass,static function($event)use(&$observed,$action,$native):void {
				$observed[]=$event->getPollId();
				if($action==='drift'){\OCP\Server::get(\OCA\Mcp\Tools\Polls\Create\CreateNative::class)->polls($native)->update($event->getPollId(),['allowMaybe'=>0]);}
				else{throw new RuntimeException('Synthetic post-insert listener');}
			});
			$in['arguments']+=['confirm'=>true,'plan_state'=>$plan['plan_state']];
		}
		try{
			if(!empty($in['preview'])){$out['answer']=$module->preview('polls_create_poll',$in['arguments'],$uid);}
			else{$result=$module->call('polls_create_poll',$in['arguments'],$uid);$out['answer']=json_decode($result['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);}
		}catch(\Throwable $e){$out['error_class']=$e::class;}
		if(isset($observed)){$out['eventPollIds']=$observed;}
		$out['residue']=array_map(static fn($key)=>$store->get($key),['ncPollsPublicToken','ncPollsUserId','ncPollsShareType']);
		return $out;
	}
}
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
echo json_encode(PollsCreateActorProcess::run($argv[1],$argv[2],$input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
