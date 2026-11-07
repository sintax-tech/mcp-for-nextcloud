<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

use OCP\Server;

/** Writes only synthetic fixtures through native services before read-only observations begin. */
final class PollsContentSeed {
	/**
	 * Creates two real anonymous polls and votes by two actors, plus direct public/personal links.
	 * @return array<string,mixed> fixture ids only, no credentials
	 */
	public static function run(): array {
		$session = Server::get(\OCP\IUserSession::class);
		$users = Server::get(\OCP\IUserManager::class);
		$native = Server::get(\OCA\Polls\UserSession::class);
		$polls = Server::get(\OCA\Polls\Service\PollService::class);
		$shares = Server::get(\OCA\Polls\Service\ShareService::class);
		$services = Server::get(\OCA\Mcp\Modules\Polls\Integration\PollsServices::class);
		$ids = [];
		foreach ([1, 2, 3, 4] as $index) {
			$session->setUser($users->get(PollsScene::OWNER));
			$native->cleanSession();
			$poll = $polls->add(\OCA\Polls\Db\Poll::TYPE_TEXT, 'PO2 synthetic ' . $index);
			$id = $poll->getId();
			$polls->update($id, ['anonymous' => $index <= 2 ? -1 : ($index === 3 ? 1 : 0), 'showResults' => 'always', 'access' => 'private']);
			$shares->add($id, 'user', PollsScene::VIEWER);
			$shares->add($id, 'admin', PollsScene::DELEGATE);
			$shares->add($id, 'public');
			$shares->add($id, 'external', 'po2-external-' . $index, 'Synthetic Guest');
			$options = $services->options($native);
			$optionIds = [];
			foreach (['<3 people, a<b, <=10', 'Option 2', 'Zero votes'] as $text) {
				$optionIds[] = $options->add($id, \OCA\Polls\Model\SimpleOption::fromArray(['text' => $text]), false)->getId();
			}
			foreach ([PollsScene::OWNER, PollsScene::VIEWER] as $actor) {
				$session->setUser($users->get($actor));
				$native->cleanSession();
				foreach (array_slice($optionIds, 0, 2) as $optionId) {
					Server::get(\OCA\Polls\Service\VoteService::class)->set($optionId, \OCA\Polls\Db\Vote::VOTE_YES);
				}
			}
			$ids[$index] = ['id' => $id, 'options' => $optionIds];
		}
		$native->cleanSession();
		$session->setUser(null);
		return $ids;
	}
}
