<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Polls\PollsScene;

require dirname(__DIR__,2).'/bootstrap.php';

/** Creates only synthetic fixture polls through the native services under their real owner. */
final class PollsSeed {
	/**
	 * Seeds the scene, including native shares/vote used solely to test reading ACL/filtering.
	 * @param string $out private fixture JSON path
	 * @return void
	 */
	public static function run(string $out): void {
		$users=\OCP\Server::get(\OCP\IUserManager::class);
		$session=\OCP\Server::get(\OCP\IUserSession::class);
		$session->setUser($users->get(PollsScene::OWNER));
		\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('polls');
		$context=\OCP\Server::get(\OCA\Polls\UserSession::class);$context->cleanSession();
		$service=\OCP\Server::get(\OCA\Polls\Service\PollService::class);
		$options=\OCP\Server::get(\OCA\Polls\Service\OptionService::class);
		$shares=\OCP\Server::get(\OCA\Polls\Service\ShareService::class);
		$ids=[];
		foreach (['private','shared','open','hidden-options','long-text'] as $name) {
			$poll=$service->add(\OCA\Polls\Db\Poll::TYPE_TEXT,'PO1 '.$name);
			$id=$poll->getId();$ids[$name]=$id;
			$service->update($id,['description'=>$name==='long-text'?str_repeat('é',26000):'<b>PO1 description</b>',
				'showResults'=>'never','hideBookedUp'=>false,'access'=>$name==='open'?'open':'private']);
			for ($i=1;$i<=3;$i++) {
				$option=$options->add($id,\OCA\Polls\Model\SimpleOption::fromArray(['text'=>$name==='long-text'?str_repeat('文',200).$i:'Option '.$i]),false);
				$ids['options'][$name][]=$option->getId();
			}
			if (in_array($name,['shared','hidden-options','long-text'],true)) {
				$shares->add($id,\OCA\Polls\Model\UserBase::TYPE_USER,PollsScene::VIEWER);
			}
			if ($name==='shared') {
				$shares->add($id,\OCA\Polls\Model\UserBase::TYPE_ADMIN,PollsScene::DELEGATE);
				$shares->add($id,\OCA\Polls\Model\UserBase::TYPE_PUBLIC);
				$removed=$options->add($id,\OCA\Polls\Model\SimpleOption::fromArray(['text'=>'Removed option']),false);
				$options->delete($removed->getId());$ids['removed']=$removed->getId();
			}
			if ($name==='hidden-options') {
				$service->update($id,['hideBookedUp'=>true,'maxVotesPerOption'=>1]);
				\OCP\Server::get(\OCA\Polls\Service\VoteService::class)->set($ids['options'][$name][0],\OCA\Polls\Db\Vote::VOTE_YES);
			}
		}
		\OCA\Mcp\Tests\Integration\Support\ScenePolicySetup::actors(
			\OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class), PollsScene::USERS, 'polls',
		);
		file_put_contents($out,json_encode($ids,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
		echo 'Synthetic Polls scene created through native services: '.count($ids['options']).' polls'.PHP_EOL;
		$context->cleanSession();$session->setUser(null);
	}
}
try {
	PollsSeed::run($argv[1]);
} catch (\Throwable $error) {
	fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
	exit(1);
}
