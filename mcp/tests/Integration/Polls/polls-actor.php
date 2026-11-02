<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Polls\{PollsScene,PollsCountingService,PollsProbeContainer};
use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsModule;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Presentation\PollsShapes;
use OCA\Mcp\Modules\Polls\Reading\ListPollsHandler;
use OCA\Mcp\Modules\Polls\Reading\PollsContext;
use OCA\Mcp\Modules\Polls\Reading\PollsCursor;
use OCA\Mcp\Modules\Polls\Reading\PollsListing;
use OCA\Mcp\Modules\Polls\Reading\PollsReading;
use OCA\Mcp\Modules\Polls\Reading\ReadPollHandler;
use OCA\Mcp\Modules\Polls\Tools\ListPolls;
use OCA\Mcp\Modules\Polls\Tools\ReadPoll;
use OCP\Server;

require dirname(__DIR__).'/bootstrap.php';

/** Native fixture probes; mutations exist solely to set up a read test, outside production MCP tools. */
final class PollsNativeProbe {
	/**
	 * Exercises native context and instrumented production handlers without defining OC_CONSOLE.
	 * @param string $uid actor belonging to this disposable scene
	 * @param string $action observe/native/configure/repeat
	 * @param array<string,mixed> $in scoped arguments
	 * @return array<string,mixed> safe observations
	 */
	public static function run(string $uid,string $action,array $in): array {
		if (!in_array($uid,PollsScene::USERS,true)) {throw new RuntimeException('Not a Polls fixture user');}
		$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);
		$start=$in['warm']??$uid;
		if (!in_array($start,PollsScene::USERS,true)) {throw new RuntimeException('Invalid warm actor');}
		$session->setUser(!empty($in['warmNull'])?null:$users->get($start));Server::get(\OCP\App\IAppManager::class)->loadApp('polls');
		$native=Server::get(\OCA\Polls\UserSession::class);$native->getCurrentUser();$native->getCurrentUserId();
		$actual=$in['session']??$uid;
		if (!in_array($actual,PollsScene::USERS,true)) {throw new RuntimeException('Invalid session actor');}
		$session->setUser($users->get($actual));
		if (!empty($in['stale'])) {
			$store=Server::get(\OCP\ISession::class);$store->set('ncPollsPublicToken','synthetic-old-public-token');
			if (defined(\OCA\Polls\UserSession::class.'::SESSION_KEY_USER_ID')) {
				$store->set('ncPollsUserId',PollsScene::OUTSIDER);$store->set('ncPollsShareType','public');
			}
		}
		if ($action==='configure') {
			$ids=PollsScene::ids();
			if ($uid!==PollsScene::OWNER || !in_array($in['poll_id'],array_values(array_filter($ids,'is_int')),true)) {throw new RuntimeException('Not a scoped owner fixture');}
			$native->cleanSession();$service=Server::get(\OCA\Polls\Service\PollService::class);
			$before=$service->get($in['poll_id'])->getConfigurationArray()['hideBookedUp'];
			$service->update($in['poll_id'],$in['config']);
			$after=$service->get($in['poll_id'])->getConfigurationArray()['hideBookedUp'];$native->cleanSession();
			return ['configured'=>true,'before'=>$before,'after'=>$after];
		}
		$guard=new PollsSessionGuard($session);
		$counter=new PollsCountingService(Server::get(\OCA\Polls\Service\PollService::class));
		$services=new PollsServices(Server::get(\OCP\App\IAppManager::class),$users,new PollsProbeContainer($counter),
			Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class),new PollsSurface());
		$cursor=new PollsCursor(Server::get(\OCA\Mcp\Mcp\Confirmation\PlanState::class));$shapes=new PollsShapes();$context=new PollsContext($services,$guard,Server::get(\Psr\Log\LoggerInterface::class));
		// The two instrumented reads; the other tools come from the app container, as the module resolves them there.
		$module=new PollsModule(new class([
			ListPolls::class=>new ListPolls(new ListPollsHandler($context,new PollsListing($services,$guard,$shapes,$cursor))),
			ReadPoll::class=>new ReadPoll(new ReadPollHandler($context,new PollsReading($services,$guard,$shapes,$cursor))),
		]) implements \Psr\Container\ContainerInterface {
			public function __construct(private array $tools) {}
			public function get(string $id): mixed {return $this->tools[$id] ?? Server::get($id);}
			public function has(string $id): bool {return true;}
		});
		if ($action==='native') {
			$native->cleanSession();$guard->native($native,$uid);
			$polls=Server::get(\OCA\Polls\Service\PollService::class)->listPolls();$ids=[];
			foreach ($polls as $poll) {if ($poll->getIsAllowed(\OCA\Polls\Db\Poll::PERMISSION_POLL_ACCESS)) {$ids[]=$poll->getId();}}
			$out=['polls'=>$ids];
			if (isset($in['poll_id'])) {$out['options']=array_map(static fn ($o)=>$o->getId(),$services->options($native)->list($in['poll_id']));}
			$native->cleanSession();return $out;
		}
		if ($action==='repeat') {
			$out=[];
			foreach ([PollsScene::OWNER,PollsScene::VIEWER] as $actor) {
				$session->setUser($users->get($actor));
				$result=$module->call('polls_read_poll',['poll_id'=>$in['poll_id']],$actor);
				$out[$actor]=json_decode($result['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
			}
			return $out;
		}
		if ($action!=='observe') {throw new RuntimeException('Unknown fixture action');}
		$out=[];
		try {
			$result=$module->call($in['tool']??'polls_list_polls',$in['arguments']??[],$uid);
			$out['answer']=json_decode($result['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
		} catch (PollsRefusal $error) {$out['error']=$error->getMessage();}
		$store=Server::get(\OCP\ISession::class);
		$out['residue']=array_map(static fn ($key)=>$store->get($key),['ncPollsPublicToken','ncPollsUserId','ncPollsShareType']);
		$out['nativeGets']=$counter->gets;
		return $out;
	}
}
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
echo json_encode(PollsNativeProbe::run($argv[1],$argv[2],$input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
