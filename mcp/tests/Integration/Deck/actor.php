<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Deck\DeckFixture as F;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\AttachmentService;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Files\IRootFolder;

require dirname(__DIR__) . '/bootstrap.php';
[$script, $uid, $action] = $argv;
if (!in_array($uid, [F::OWNER, F::READER, F::EDITOR, F::OUTSIDER], true)) {
	throw new RuntimeException('Not a disposable Deck fixture actor');
}
Server::get(OCP\IUserSession::class)->setUser(Server::get(OCP\IUserManager::class)->get($uid));
Server::get(OCP\ISession::class)->set('user_id', $uid);
OC_Util::setupFS($uid);
$apps = Server::get(OCP\App\IAppManager::class);
if (!$apps->isEnabledForAnyone('deck')) {
	throw new RuntimeException('Deck must be provisioned; this scenario never skips');
}
$apps->loadApp('deck');
$boards = Server::get(BoardService::class);
$boards->setUserId($uid);
$cards = Server::get(CardService::class);
$stacks = Server::get(StackService::class);
$labels = Server::get(LabelService::class);
$attachments = Server::get(AttachmentService::class);
$root = Server::get(IRootFolder::class);
$shares = Server::get(IManager::class);
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
function attempt(Closure $fn): array {
	try { $fn(); return ['accepted' => true, 'exception' => null]; }
	catch (OCA\Deck\NoPermissionException|OCA\Deck\StatusException|OCP\Share\Exceptions\GenericShareException $e) {
		return ['accepted' => false, 'exception' => $e::class];
	}
}
function labelIds(object $card): array {
	$ids = array_map(fn ($l) => (int)$l->getId(), $card->getLabels()); sort($ids); return $ids;
}
function dependentIds(object $card): array {
	$ids = $card->getDependentCards() ?? []; sort($ids); return $ids;
}
function cardReadable(CardService $cards, int $id): bool {
	try { $cards->find($id); return true; }
	catch (OCA\Deck\NoPermissionException $e) { return false; }
}
if (str_starts_with($action, 'date-')) {
	echo json_encode(OCA\Mcp\Tests\Integration\Deck\CardDueDateFixture::run($uid, $action, $in), JSON_THROW_ON_ERROR);
	exit;
}
if (str_starts_with($action, 'link-')) {
	try {
		echo json_encode(OCA\Mcp\Tests\Integration\Deck\CardFileLinkFixture::run($uid, $action, $in), JSON_THROW_ON_ERROR);
	} catch (Throwable $failure) {
		fwrite(STDERR, (string)$failure);
		exit(1);
	}
	exit;
}
if (str_starts_with($action, 'recipient-')) {
	try {
		echo json_encode(OCA\Mcp\Tests\Integration\Deck\BoardRecipientFixture::run($uid, $action, $in), JSON_THROW_ON_ERROR);
	} catch (Throwable $failure) {
		fwrite(STDERR, (string)$failure);
		exit(1);
	}
	exit;
}
if (str_starts_with($action, 'guest-')) {
	echo json_encode(OCA\Mcp\Tests\Integration\Guests\GuestDeckParityFixture::run($uid, $action, $in), JSON_THROW_ON_ERROR);
	exit;
}

switch ($action) {
	case 'invalid-provisioning':
		$commands = [
			'manifest' => [PHP_BINARY, dirname(__DIR__) . '/ci/check-deck-manifest.php',
				getenv('NEXTCLOUD_ROOT') . '/custom_apps/deck/appinfo/info.xml', getenv('MCP_IT_NC'), '0.0.0'],
			// In-container pin_releases guard only; this is not the host run-local provisioning flow.
			'major' => ['sh', dirname(__DIR__) . '/ci/harness.sh', 'provision'],
		];
		foreach ($commands as $name => $command) {
			$env = array_merge(getenv(), ['MCP_IT_LOCAL' => '1', 'MCP_IT_NC' => '99',
				'RUNNER_TEMP' => '/tmp/mcp-it-invalid-pin-' . bin2hex(random_bytes(6))]);
			$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
			if (!is_resource($process)) { throw new RuntimeException('Cannot start negative provisioning probe'); }
			fclose($pipes[0]);
			$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]); fclose($pipes[2]);
			$out[$name] = ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
		}
		break;
	case 'seed':
	case 'private-seed':
		$suffix = bin2hex(random_bytes(6));
		$board = $boards->create('Integration Deck ' . $suffix, $uid, '008800');
		$stack = $stacks->create('To do', $board->getId(), 0);
		$card = $cards->create('Design ' . $suffix, $stack->getId(), 'plain', 0, $uid);
		$out = ['board' => $board->getId(), 'stack' => $stack->getId(), 'a' => $card->getId()];
		if ($action === 'private-seed') { break; }
		$out['b'] = $cards->create('Development', $stack->getId(), 'plain', 1, $uid)->getId();
		$private = $boards->create('Private ' . $suffix, $uid, '880000');
		$privateStack = $stacks->create('Private', $private->getId(), 0);
		$out['privateBoard'] = $private->getId();
		$out['privateCard'] = $cards->create('Private reference', $privateStack->getId(), 'plain', 0, $uid)->getId();
		$out['foreignLabel'] = $labels->create('Other board', '880000', $private->getId())->getId();
		$out['keep'] = $labels->create('Keep', '008800', $board->getId())->getId();
		$out['toggle'] = $labels->create('Toggle', '880000', $board->getId())->getId();
		$boards->addAcl($board->getId(), 0, F::READER, false, false, false);
		$boards->addAcl($board->getId(), 0, F::EDITOR, true, false, false);
		$out['fileName'] = 'deck-fixture-' . $suffix . '.txt';
		$file = $root->getUserFolder($uid)->newFile($out['fileName'], 'Native Deck fixture bytes ' . $suffix);
		$out['fileId'] = $file->getId();
		$out['hash'] = hash('sha256', $file->getContent());
		if ($in['collaboration'] ?? false) {
			$groupBoard = $boards->create('Group ' . $suffix, $uid, '008800');
			$groupStack = $stacks->create('Group', $groupBoard->getId(), 0);
			$out['groupCard'] = $cards->create('Group card', $groupStack->getId(), 'plain', 0, $uid)->getId();
			$boards->addAcl($groupBoard->getId(), 1, F::GROUP, false, false, false);
			if (!$apps->isEnabledForAnyone('circles')) { throw new RuntimeException('Circles fixture app is missing'); }
			$apps->loadApp('circles');
			$circles = Server::get(OCA\Circles\CirclesManager::class);
			$circles->startSession($circles->getLocalFederatedUser($uid), true);
			$circle = $circles->createCircle('Deck fixture ' . $suffix, null, false, true, false);
			$circles->addMember($circle->getSingleId(), $circles->getLocalFederatedUser(F::EDITOR));
			$out['circle'] = $circle->getSingleId();
			$circleBoard = $boards->create('Circle ' . $suffix, $uid, '008800');
			$circleStack = $stacks->create('Circle', $circleBoard->getId(), 0);
			$out['circleCard'] = $cards->create('Circle card', $circleStack->getId(), 'plain', 0, $uid)->getId();
			$boards->addAcl($circleBoard->getId(), 7, $out['circle'], false, false, false);
		}
		break;
	case 'observe':
		$out = ['source' => cardReadable($cards, $in['a']), 'private' => cardReadable($cards, $in['privateCard'])];
		foreach (['groupCard', 'circleCard'] as $key) {
			if (isset($in[$key])) { $out[$key] = cardReadable($cards, $in[$key]); }
		}
		if ($out['source'] && method_exists($cards, 'assignDependentCard')) {
			$out['dependentCards'] = dependentIds($cards->find($in['a']));
		}
		$out['files'] = array_map(fn ($n) => ['id' => $n->getId(), 'hash' => hash('sha256', $n->getContent())],
			$root->getUserFolder($uid)->getById($in['fileId']));
		break;
	case 'empty-label-board':
		$empty = $boards->create('Empty labels ' . bin2hex(random_bytes(4)), $uid, '008800');
		foreach ($boards->find($empty->getId())->getLabels() as $label) { $labels->delete($label->getId()); }
		$boards->addAcl($empty->getId(), 0, F::READER, false, false, false);
		$boards->addAcl($empty->getId(), 0, F::EDITOR, false, false, false);
		$out = ['emptyBoard' => $empty->getId()];
		break;
	case 'manage-source':
		foreach ($boards->find($in['board'])->getAcl() as $acl) {
			if (in_array($acl->getParticipant(), [F::READER, F::EDITOR], true)) {
				$boards->updateAcl($acl->getId(), $acl->getParticipant() === F::EDITOR, false, true);
			}
		}
		$out = ['managed' => true];
		break;
	case 'trash-reference':
		$cards->delete($in['privateCard']);
		$out = ['trashed' => true];
		break;
	case 'mcp-observe':
		$gateway = new OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway(OC::$server,
			Server::get(OCP\AppFramework\Utility\ITimeFactory::class), null, Server::get(OCP\ISession::class));
		$module = new OCA\Mcp\Tools\Deck\DeckToolModule(OC::$server, $apps, Server::get(Psr\Log\LoggerInterface::class),
			Server::get(OCP\IURLGenerator::class), Server::get(OCP\AppFramework\Utility\ITimeFactory::class),
			Server::get(OCP\IUserManager::class), Server::get(OCP\IConfig::class), Server::get(OCP\ISession::class));
		$source = $gateway->findCard($uid, $in['a']);
		$out = ['privateReadable' => cardReadable($cards, $in['privateCard']), 'nativeReferences' =>
			method_exists($cards, 'assignDependentCard') ? dependentIds($cards->find($in['a'])) : []];
		$out['references'] = [];
		$record = function (string $name, $card) use (&$out, $cards): void {
			$out['references'][$name] = method_exists($cards, 'assignDependentCard') ? dependentIds($card) : [];
		};
		$record('read', $source);
		foreach ($gateway->listCards($uid, $in['stack'], 50, 0)['items'] as $card) {
			if ($card->getId() === $in['a']) { $record('list', $card); }
		}
		foreach ($gateway->listStacks($uid, $in['board']) as $stack) {
			foreach ($stack->getCards() ?? [] as $card) {
				if ($card->getId() === $in['a']) { $record('stack', $card); }
			}
		}
		foreach ($gateway->followupCards($uid, 'all', $in['board'], null, null, 50)['items'] as $entry) {
			if ($entry['card']->getId() === $in['a']) { $record('followup', $entry['card']); }
		}
		$calls = ['deck_read_card' => ['cardId' => $in['a']], 'deck_list_cards' => ['stackId' => $in['stack']],
			'deck_list_stacks' => ['boardId' => $in['board']], 'deck_followup_cards' => ['status' => 'all', 'boardId' => $in['board']],
			'deck_list_labels' => ['boardId' => $in['board'], 'limit' => 1, 'offset' => 0]];
		foreach ($calls as $name => $args) { $out['tools'][$name] = $module->call($name, $args, $uid); }
		$out['emptyBoardLabels'] = $module->call('deck_list_labels', ['boardId' => $in['emptyBoard']], $uid);
		$out['emptyLabels'] = $module->call('deck_list_labels', ['boardId' => $in['board'], 'limit' => 1, 'offset' => 999], $uid);
		$out['deniedLabels'] = $module->call('deck_list_labels', ['boardId' => $in['privateBoard']], $uid);
		$out['missingLabels'] = $module->call('deck_list_labels', ['boardId' => 2147483647], $uid);
		if ($uid !== F::READER) {
			$out['preview'] = $module->preview('deck_edit_card', ['cardId' => $in['a'], 'description' => 'Revised'], $uid);
			$record('update', $gateway->updateCard($uid, $source, $source->getTitle(), 'Revised', null));
			$record('move', $gateway->moveCard($uid, $in['a'], $in['stack'], 0));
			$record('state', $gateway->cardState($uid, $in['a']));
			$out['write'] = $module->call('deck_edit_card', ['cardId' => $in['a'], 'description' => 'Revised again', 'confirm_shared' => true], $uid);
		}
		break;
	case 'cost-seed':
		$out = ['targets' => 40];
		if (!method_exists($cards, 'assignDependentCard')) { throw new RuntimeException('Native dependencies required for cost proof'); }
		for ($i = 0; $i < 8; $i++) { $boards->create('Cost background ' . bin2hex(random_bytes(4)), $uid, '008800'); }
		for ($i = 0; $i < $out['targets']; $i++) {
			$target = $cards->create('Cost target ' . $i, $in['stack'], 'plain', $i + 2, $uid);
			$cards->assignDependentCard($in['a'], $target->getId());
		}
		$out['boards'] = count($boards->findAll(-2, false, true));
		break;
	case 'cost-read':
		$gateway = new OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway(OC::$server,
			Server::get(OCP\AppFramework\Utility\ITimeFactory::class), null, Server::get(OCP\ISession::class));
		$log = OCA\Mcp\Tests\Integration\Support\PostgresQueryLog::fromEnvironment();
		$connection = Server::get(OCP\IDBConnection::class);
		$pid = $log?->backendPid($connection);
		$begin = $log?->mark($connection, 'deck-cost-begin');
		$started = hrtime(true);
		$card = $gateway->findCard($uid, $in['a']);
		$elapsed = (hrtime(true) - $started) / 1_000_000;
		$end = $log?->mark($connection, 'deck-cost-end');
		$out = ['elapsed_ms' => round($elapsed, 3), 'peak_php_bytes' => memory_get_peak_usage(true),
			'references' => count($card->getDependentCards() ?? []),
			'statements' => $log === null ? null : count($log->statementsBetween($pid, $begin, $end))];
		break;
	case 'dependency-seed':
		if (method_exists($cards, 'assignDependentCard')) {
			$cards->assignDependentCard($in['a'], $in['b']);
			$cards->assignDependentCard($in['a'], $in['privateCard']);
		}
		$out = ['nativeAvailable' => method_exists($cards, 'assignDependentCard')];
		break;
	case 'dependency-private-access':
		foreach ([F::READER, F::EDITOR] as $participant) {
			if ($in['allow']) {
				$boards->addAcl($in['privateBoard'], 0, $participant, false, false, false);
			} else {
				foreach ($boards->find($in['privateBoard'])->getAcl() as $acl) {
					if ($acl->getParticipant() === $participant) { $boards->deleteAcl($acl->getId()); }
				}
			}
		}
		$out = ['updated' => true];
		break;
	case 'dependency-hide-remove':
		$cards->removeDependentCard($in['a'], $in['privateCard']);
		$out = ['removed' => true];
		break;
	case 'dependency-cycle':
		$cards->assignDependentCard($in['b'], $in['a']);
		$out = ['cycle' => true];
		break;
	case 'dependency-native-link':
		$out = attempt(fn () => ($in['remove'] ?? false)
			? $cards->removeDependentCard($in['origin'], $in['target'])
			: $cards->assignDependentCard($in['origin'], $in['target']));
		break;
	case 'dependency-ui-state':
		$out = ['ids' => dependentIds($cards->find($in['a']))];
		break;
	case 'dependency-extra':
		$out = ['c' => $cards->create('Third readable', $in['stack'], 'plain', 2, $uid)->getId()];
		break;
	case 'files-prepare':
		$suffix = bin2hex(random_bytes(5));
		$ownerFolder = $root->getUserFolder($uid)->newFolder('dk4a-visible-' . $suffix);
		$visible = $ownerFolder->newFile('visible.txt', 'DK4a original bytes ' . $suffix);
		$secret = $root->getUserFolder($uid)->newFile('dk4a-secret-' . $suffix . '.txt', 'DK4a excluded bytes');
		$tag = Server::get(OCP\SystemTag\ISystemTagManager::class)->createTag('dk4a-excluded-' . $suffix, true, true);
		Server::get(OCP\IConfig::class)->setAppValue('mcp', 'hidden_system_tags', json_encode([(string)$tag->getId()], JSON_THROW_ON_ERROR));
		Server::get(OCP\SystemTag\ISystemTagObjectMapper::class)->assignTags((string)$secret->getId(), 'files', [(string)$tag->getId()]);
		$out = ['visibleFileId' => $visible->getId(), 'visibleHash' => hash('sha256', $visible->getContent()),
			'secretFileId' => $secret->getId(), 'secretName' => $secret->getName(), 'excludeTag' => $tag->getId(),
			'visibleFolderId' => $ownerFolder->getId()];
		foreach (['visibleShare' => [$visible, $in['a']], 'secondShare' => [$visible, $in['b']],
			'hiddenShare' => [$secret, $in['a']], 'folderShare' => [$ownerFolder, $in['a']]] as $key => [$node, $cardId]) {
			$share = $shares->newShare()->setNode($node)->setShareType(IShare::TYPE_DECK)
				->setSharedWith((string)$cardId)->setPermissions(1)->setSharedBy($uid);
			$out[$key] = (int)$shares->createShare($share)->getId();
		}
		$share = $shares->newShare()->setNode($ownerFolder)->setShareType(IShare::TYPE_USER)
			->setSharedWith(F::EDITOR)->setPermissions(1)->setSharedBy($uid);
		$out['independentShare'] = $shares->createShare($share)->getFullId();
		$direct = $shares->createShare($shares->newShare()->setNode($visible)->setShareType(IShare::TYPE_USER)
			->setSharedWith(F::EDITOR)->setPermissions(1)->setSharedBy($uid));
		$out['independentFileShare'] = ['fullId' => $direct->getFullId(), 'id' => $direct->getId(),
			'fileId' => (int)$direct->getNodeId(), 'type' => $direct->getShareType(),
			'permissions' => $direct->getPermissions(), 'recipient' => $direct->getSharedWith()];
		break;
	case 'files-inspect':
		$file = $root->getUserFolder($uid)->getById($in['visibleFileId'])[0];
		$out = ['fileId' => $file->getId(), 'hash' => hash('sha256', $file->getContent())];
		// Same native source used by the Deck UI, in a fresh process; no visual-browser claim.
		foreach (['a', 'b'] as $key) {
			$out[$key] = array_values(array_map(static fn ($attachment): int => (int)$attachment->getId(),
				array_filter($attachments->findAll($in[$key]), static fn ($attachment): bool => $attachment->getType() === 'file')));
			sort($out[$key]);
		}
		try { $out['independentExists'] = $shares->getShareById($in['independentShare'])->getShareType() === IShare::TYPE_USER; }
		catch (OCP\Share\Exceptions\ShareNotFound) { $out['independentExists'] = false; }
		try {
			$direct = $shares->getShareById($in['independentFileShare']['fullId']);
			$out['independentFileShare'] = ['fullId' => $direct->getFullId(), 'id' => $direct->getId(),
				'fileId' => (int)$direct->getNodeId(), 'type' => $direct->getShareType(),
				'permissions' => $direct->getPermissions(), 'recipient' => $direct->getSharedWith()];
		} catch (OCP\Share\Exceptions\ShareNotFound) {
			$out['independentFileShare'] = null;
		}
		break;
	case 'files-tag-visible':
		Server::get(OCP\SystemTag\ISystemTagObjectMapper::class)->assignTags((string)$in['visibleFileId'], 'files', [(string)$in['excludeTag']]);
		$out = ['tagged' => true];
		break;
	case 'files-scope':
		$writer = Server::get(OCA\Mcp\Access\Policy\PolicyWriter::class);
		$state = Server::get(OCA\Mcp\Db\PolicyStateMapper::class)->state();
		$writer->setResourceScope($state['revision'], 'admin', $in['actor'], 'files', $in['items']);
		$out = ['changed' => true];
		break;
	case 'mcp-card-files':
		$module = new OCA\Mcp\Tools\Deck\DeckToolModule(OC::$server, $apps, Server::get(Psr\Log\LoggerInterface::class),
			Server::get(OCP\IURLGenerator::class), Server::get(OCP\AppFramework\Utility\ITimeFactory::class),
			Server::get(OCP\IUserManager::class), Server::get(OCP\IConfig::class), Server::get(OCP\ISession::class));
		$policy = Server::get(OCA\Mcp\Access\Grants\GrantPolicy::class);
		$policy->setGrant($uid, 'deck', 'read', true);
		$policy->setGrant($uid, 'deck', 'edit', $in['deckEdit'] ?? true);
		$policy->setGrant($uid, 'files', 'read', $in['filesRead'] ?? true);
		$policy->setGrant($uid, 'files', 'share', false); // Unlink reduces exposure and must not require this grant.
		$registry = new OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $policy, $apps, Server::get(OCP\IUserManager::class),
			Server::get(Psr\Log\LoggerInterface::class));
		$tool = ($in['unlink'] ?? false) ? 'deck_unlink_card_file' : 'deck_list_card_files';
		try { $out = ['result' => $policy->within($uid, fn () => $registry->call($tool, $in['arguments'], $uid))]; }
		catch (InvalidArgumentException) { $out = ['refused' => true]; }
		break;
	case 'mcp-dependency':
		$module = new OCA\Mcp\Tools\Deck\DeckToolModule(OC::$server, $apps, Server::get(Psr\Log\LoggerInterface::class),
			Server::get(OCP\IURLGenerator::class), Server::get(OCP\AppFramework\Utility\ITimeFactory::class),
			Server::get(OCP\IUserManager::class), Server::get(OCP\IConfig::class), Server::get(OCP\ISession::class));
		$policy = Server::get(OCA\Mcp\Access\Grants\GrantPolicy::class);
		$policy->setGrant($uid, 'deck', 'edit', $in['grant'] ?? true);
		$registry = new OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $policy, $apps, Server::get(OCP\IUserManager::class),
			Server::get(Psr\Log\LoggerInterface::class));
		$out = ['tools' => array_column($registry->list($uid), 'name')];
		$tool = ($in['remove'] ?? false) ? 'deck_remove_card_dependency' : 'deck_add_card_dependency';
		try { $out['result'] = $registry->call($tool, $in['arguments'], $uid); }
		catch (InvalidArgumentException) { $out['refused'] = true; }
		if ((int)getenv('MCP_IT_NC') < 34) {
			try { $module->call($tool, $in['arguments'], $uid); }
			catch (InvalidArgumentException) { $out['directRefused'] = true; }
		}
		break;
	case 'dependency-read':
		$module = new OCA\Mcp\Tools\Deck\DeckToolModule(OC::$server, $apps, Server::get(Psr\Log\LoggerInterface::class),
			Server::get(OCP\IURLGenerator::class), Server::get(OCP\AppFramework\Utility\ITimeFactory::class),
			Server::get(OCP\IUserManager::class), Server::get(OCP\IConfig::class), Server::get(OCP\ISession::class));
		$policy = Server::get(OCA\Mcp\Access\Grants\GrantPolicy::class);
		$policy->setGrant($uid, 'deck', 'read', $in['grant'] ?? true);
		$registry = new OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $policy, $apps, Server::get(OCP\IUserManager::class),
			Server::get(Psr\Log\LoggerInterface::class));
		$out = ['tools' => array_column($registry->list($uid), 'name')];
		try {
			$out['result'] = $registry->call('deck_read_card_dependencies', ['cardId' => $in['cardId']], $uid);
		} catch (InvalidArgumentException $e) { $out['refused'] = true; }
		if ((int)getenv('MCP_IT_NC') < 34) {
			try { $module->call('deck_read_card_dependencies', ['cardId' => $in['cardId']], $uid); }
			catch (InvalidArgumentException $e) { $out['directRefused'] = true; }
		}
		break;
	case 'mcp-labels':
		$module = new OCA\Mcp\Tools\Deck\DeckToolModule(OC::$server, $apps, Server::get(Psr\Log\LoggerInterface::class),
			Server::get(OCP\IURLGenerator::class), Server::get(OCP\AppFramework\Utility\ITimeFactory::class),
			Server::get(OCP\IUserManager::class), Server::get(OCP\IConfig::class), Server::get(OCP\ISession::class));
		try {
			if (OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($in['arguments'])) {
				$result = $module->call('deck_edit_card_labels', $in['arguments'], $uid);
			} else {
				$plan = $module->preview('deck_edit_card_labels', $in['arguments'], $uid);
				$result = OCA\Mcp\Tools\Deck\DeckResult::ok(OCA\Mcp\Mcp\Confirmation\WriteGate::envelope(
					['name' => 'deck_edit_card_labels'], $plan));
			}
			$out = ['result'=>$result];
		} catch (OCA\Mcp\Mcp\Confirmation\PlanChanged $e) { $out = ['changedPlan'=>$e->plan]; }
		catch (OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) { $out = ['result'=>OCA\Mcp\Tools\Deck\DeckResult::error($e->getMessage())]; }
		// Read through the same service that populates the native Deck UI, in this fresh actor request.
		try { $out['nativeLabels'] = labelIds($cards->find($in['arguments']['cardId'])); }
		catch (OCA\Deck\NoPermissionException) { $out['nativeLabels'] = null; }
		break;
	case 'label-third':
		$out['third'] = $labels->create('Preserve third', '111111', $in['board'])->getId();
		$cards->assignLabel($in['a'], $out['third']);
		break;
	case 'label-concurrent':
		$cards->assignLabel($in['a'], $in['keep']); $out=['changed'=>true]; break;
	case 'label-archive':
		if (($in['target'] ?? 'card') === 'card') { $cards->archive($in['a']); }
		else { $board=$boards->find($in['board']); $boards->update($in['board'],$board->getTitle(),$board->getColor(),true); }
		$out=['archived'=>true]; break;
	case 'label-revoke':
		foreach ($boards->find($in['board'])->getAcl() as $acl) {
			if ($acl->getParticipant() === F::EDITOR) { $boards->deleteAcl($acl->getId()); }
		}
		$out=['revoked'=>true]; break;
	case 'label-move-probe':
		$cards->assignLabel($in['a'], $in['keep']);
		$private = $cards->find($in['privateCard']);
		$cards->reorder($in['a'], $private->getStackId(), 1);
		$out = ['labelsAfterMove' => labelIds($cards->find($in['a']))];
		break;
	case 'label-move':
		$private = $cards->find($in['privateCard']);
		$cards->reorder($in['a'], $private->getStackId(), 1);$out=['moved'=>true]; break;
	case 'labels':
		$out['available'] = array_map(fn ($l) => (int)$l->getId(), $boards->find($in['board'])->getLabels());
		$cards->assignLabel($in['a'], $in['keep']); $cards->assignLabel($in['a'], $in['toggle']);
		$out['both'] = labelIds($cards->find($in['a']));
		$cards->removeLabel($in['a'], $in['toggle']);
		$out['after'] = labelIds($cards->find($in['a']));
		$out['foreign'] = attempt(fn () => $cards->assignLabel($in['a'], $in['foreignLabel']));
		break;
	case 'label-write':
		$out = attempt(fn () => $cards->assignLabel($in['a'], $in['toggle']));
		break;
	case 'dependencies':
		$out['available'] = method_exists($cards, 'assignDependentCard');
		if (!$out['available']) { break; }
		$cards->assignDependentCard($in['a'], $in['b']);
		$out['forward'] = dependentIds($cards->find($in['a']));
		$out['reverse'] = dependentIds($cards->find($in['b']));
		$cards->assignDependentCard($in['a'], $in['b']);
		$out['afterDuplicate'] = dependentIds($cards->find($in['a']));
		$cards->assignDependentCard($in['b'], $in['a']);
		$out['cycle'] = dependentIds($cards->find($in['b']));
		$cards->assignDependentCard($in['a'], $in['a']);
		$cards->assignDependentCard($in['a'], $in['privateCard']);
		$out['selfAndCrossBoard'] = dependentIds($cards->find($in['a']));
		$out['hidden'] = attempt(fn () => $cards->assignDependentCard($in['a'], $in['hiddenCard']));
		$cards->removeDependentCard($in['a'], $in['b']);
		$out['afterRemoval'] = dependentIds($cards->find($in['a']));
		break;
	case 'dependency-write':
		$out = attempt(fn () => $cards->assignDependentCard($in['a'], $in['b']));
		break;
	case 'share':
		$node = $root->getUserFolder($uid)->get($in['fileName']);
		$share = $shares->newShare()->setNode($node)->setShareType(IShare::TYPE_DECK)
			->setSharedWith((string)$in['a'])->setPermissions(1)->setSharedBy($uid);
		$share = $shares->createShare($share);
		$out = ['shareId' => (int)$share->getId(), 'fileId' => $share->getNodeId(), 'type' => $share->getShareType(),
			'permissions' => $share->getPermissions(), 'count' => count($attachments->findAll($in['a']))];
		break;
	case 'unlink':
		$attachments->delete($in['a'], $in['shareId'], 'file');
		$out = ['count' => count($attachments->findAll($in['a']))];
		break;
	default:
		throw new RuntimeException('Unknown fixture action');
}
echo json_encode($out, JSON_THROW_ON_ERROR);
