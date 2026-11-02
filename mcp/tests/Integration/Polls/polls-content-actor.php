<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/PollsContentHttpEnvironment.php';
PollsContentHttpEnvironment::prepare();
require dirname(__DIR__) . '/bootstrap.php';

/** Separate real endpoint-like actor for PO-2 native comparison and synthetic scene preparation. */
final class PollsContentActorProcess {
	/**
	 * Runs only within the disposable fixture users; no console bypass or HTTP recipient lookup.
	 * @param string $uid actor
	 * @param string $action prepare, native, observe or configure
	 * @param array<string,mixed> $in fixture request
	 * @return array<string,mixed> observations
	 */
	public static function run(string $uid, string $action, array $in): array {
		if (!in_array($uid, \OCA\Mcp\Tests\Integration\Polls\PollsScene::USERS, true)) {
			throw new RuntimeException('Not a fixture actor');
		}
		$session = \OCP\Server::get(\OCP\IUserSession::class);
		$users = \OCP\Server::get(\OCP\IUserManager::class);
		$session->setUser($users->get($uid));
		\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('polls');
		$native = \OCP\Server::get(\OCA\Polls\UserSession::class);
		$native->cleanSession();
		if ($action === 'prepare') {
			return \OCA\Mcp\Tests\Integration\Polls\PollsContentSeed::run();
		}
		if ($action === 'configure') {
			\OCP\Server::get(\OCA\Polls\Service\PollService::class)->update($in['poll_id'], $in['config']);
			$native->cleanSession();
			return ['configured' => true];
		}
		if ($action === 'observe') {
			// Independently poison native cached and persisted context, then the handler must clean both sources.
			$session->setUser($users->get($in['warm'] ?? $uid));
			$native->getCurrentUser();
			$native->getCurrentUserId();
			$session->setUser(!empty($in['noLogin']) ? null : $users->get($in['session'] ?? $uid));
			\OCP\Server::get(\OCP\ISession::class)->set('ncPollsPublicToken', 'synthetic-po2-old-token');
			$out = [];
			try {
				$result = \OCP\Server::get(\OCA\Mcp\Modules\Polls\PollsModule::class)->call($in['tool'], $in['arguments'], $uid);
				$out['answer'] = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
			} catch (\OCA\Mcp\Modules\Polls\PollsRefusal $error) {
				$out['error'] = $error->getMessage();
			}
			$store = \OCP\Server::get(\OCP\ISession::class);
			$out['residue'] = array_map(static fn ($key) => $store->get($key), ['ncPollsPublicToken', 'ncPollsUserId', 'ncPollsShareType']);
			return $out;
		}
		if ($action !== 'native') {
			throw new RuntimeException('Unknown fixture action');
		}
		try {
			$guard = \OCP\Server::get(\OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard::class);
			$guard->native($native, $uid);
			$poll = \OCP\Server::get(\OCA\Polls\Service\PollService::class)->get($in['poll_id']);
			$all = $poll->getIsAllowed(\OCA\Polls\Db\Poll::PERMISSION_POLL_RESULTS_VIEW);
			$edit = $poll->getIsAllowed(\OCA\Polls\Db\Poll::PERMISSION_POLL_EDIT);
			$votes = [];
			foreach (\OCP\Server::get(\OCA\Polls\Service\VoteService::class)->list($in['poll_id']) as $vote) {
				$guard->native($native, $uid);
				$row = $vote->jsonSerialize();
				if ($row['user'] instanceof JsonSerializable) {
					$row['user'] = $row['user']->jsonSerialize();
				}
				$votes[] = ['id' => $row['id'], 'optionId' => $row['optionId'], 'answer' => $row['answer'],
					'user' => array_intersect_key($row['user'], array_flip(['id', 'type', 'displayName']))];
			}
			$options = [];
			if ($all) {
				foreach (\OCP\Server::get(\OCA\Mcp\Modules\Polls\Integration\PollsServices::class)->options($native)->list($in['poll_id']) as $option) {
					$row = $option->jsonSerialize();
					$options[] = ['id' => $row['id'], 'votes' => array_intersect_key($row['votes'], array_flip(['yes', 'no', 'maybe', 'count'])), 'flag' => $option->getShowResults()];
				}
			}
			$links = [];
			if ($edit) {
				foreach (\OCP\Server::get(\OCA\Polls\Service\ShareService::class)->list($in['poll_id'], 'poll') as $share) {
					if ($share->getPollId() === $in['poll_id'] && !$share->getDeleted()
						&& !in_array($share->getType(), ['circle', 'contactGroup'], true) && $share->getURL() !== '') {
						$links[] = ['id' => $share->getId(), 'type' => $share->getType(), 'url' => $share->getURL(), 'locked' => (bool)$share->getLocked()];
					}
				}
			}
			return ['all' => $all, 'edit' => $edit, 'votes' => $votes, 'options' => $options, 'links' => $links];
		} finally {
			$native->cleanSession();
		}
	}
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(PollsContentActorProcess::run($argv[1], $argv[2], $input), JSON_THROW_ON_ERROR);
