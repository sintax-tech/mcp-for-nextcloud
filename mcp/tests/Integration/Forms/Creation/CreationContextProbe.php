<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms\Creation;

use OCA\Mcp\Tests\Integration\Forms\FormsScene;
use OCA\Mcp\Modules\Forms\Creation\CreateFormFlow;
use OCP\Server;

/** Deliberate fixture-only session transitions discriminate cached native graphs, never repair production identity. */
final class CreationContextProbe {
	/** Creates through the product flow under real native collaborators and verifies the persisted owner/config.
	 * @param string $scenario Exact isolated-process scenario.
	 * @return array<string,mixed> Boolean observations and synthetic IDs, never hashes, credentials or user content.
	 */
	public function run(string $scenario): array {
		if (!in_array($scenario, ['cold', 'warm', 'controller-null', 'controller-other', 'config-null', 'config-other', 'forms-null', 'forms-other', 'lazy', 'collision', 'external-revoke', 'title-race', 'native-edit'], true)) { throw new \InvalidArgumentException('Unknown context scenario'); }
		$apps = Server::get(\OCP\App\IAppManager::class); $apps->loadApp('forms');
		$session = Server::get(\OCP\IUserSession::class); $users = Server::get(\OCP\IUserManager::class);
		$actor = $users->get(FormsScene::READER); $other = $users->get(FormsScene::OUTSIDE);
		$lazy = $scenario === 'lazy';
		if ($lazy) { \OC\AppFramework\Utility\SimpleContainer::$useLazyObjects = true; }
		$negative = str_starts_with($scenario, 'forms-');
		$configCapture = str_starts_with($scenario, 'config-');
		if ($configCapture) {
			$session->setUser($scenario === 'config-null' ? null : $other);
			Server::get(\OCA\Forms\Service\ConfigService::class);
		}
		$session->setUser($negative ? ($scenario === 'forms-null' ? null : $other) : ($lazy ? null : $actor));
		$service = Server::get(\OCA\Forms\Service\FormsService::class);
		$initiallyLazy = $lazy && (new \ReflectionClass($service))->isUninitializedLazyObject($service);
		if (!$lazy) {
			$sentinel = new \OCA\Forms\Db\Form(); $sentinel->setId(0); $sentinel->setOwnerId(FormsScene::READER); $sentinel->setAccess(['permitAllUsers' => false, 'showToAllUsers' => false]);
			$service->getPermissions($sentinel);
		}
		if (Server::get(\OCA\Forms\Db\ShareMapper::class)->findByForm(0) !== []) { throw new \RuntimeException('Sentinel must have no shares in the divergent-actor fixture'); }
		$cached = null;
		if (in_array($scenario, ['controller-null', 'controller-other', 'warm'], true)) {
			$session->setUser($scenario === 'controller-null' ? null : ($scenario === 'controller-other' ? $other : $actor));
			$cached = Server::get(\OCA\Forms\Controller\ApiController::class);
			// Resolving/calling a read forces native lazy constructor capture without changing DI or writing Forms.
			try { $cached->getForms(); } catch (\Throwable) { /* Expected for the deliberately captured-null fixture only. */ }
		}
		$session->setUser($actor);
		$config = Server::get(\OCP\IAppConfig::class); $saved = [];
		foreach (['forms', 'mcp'] as $app) {
			$saved[$app] = ['restrictHad' => $config->hasKey($app, 'restrictCreation'), 'restrict' => $config->getValueBool($app, 'restrictCreation', false),
				'groupsHad' => $config->hasKey($app, 'creationAllowedGroups'), 'groups' => $config->getValueArray($app, 'creationAllowedGroups', [])];
		}
		try {
			$config->setValueBool('forms', 'restrictCreation', true); $config->setValueArray('forms', 'creationAllowedGroups', [FormsScene::GROUP]);
			$config->setValueBool('mcp', 'restrictCreation', true); $config->setValueArray('mcp', 'creationAllowedGroups', []);
			$cachedConfig = Server::get(\OCA\Forms\Service\ConfigService::class);
			$cachedSubmission = Server::get(\OCA\Forms\Service\SubmissionService::class);
			$flow = Server::get(CreateFormFlow::class); $args = ['title' => 'Context ' . $scenario, 'questions' => [['type' => 'short', 'text' => 'Name']]];
			try {
				$graph = Server::get(\OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory::class)->open(FormsScene::READER);
				if ($graph->controller === $cached || $graph->config === $cachedConfig || $graph->submission === $cachedSubmission || $graph->submission === null) { throw new \RuntimeException('Captured collaborators were not constructed fresh'); }
				if ($scenario === 'native-edit') {
					$writer = new \OCA\Mcp\Modules\Forms\Creation\CreateFormWriter(new CreationAdmissionChange(Server::get(\OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory::class), $graph), Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateFormReadback::class), new \OCA\Mcp\Modules\Forms\Creation\UploadFolderPrecheck(), Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle::class));
					$data = $writer->write($graph, \OCA\Mcp\Modules\Forms\Creation\CreateFormRequest::of($args), FormsScene::READER);
					$id = $data['applied']['formId'] ?? 0;
					if ($data['status'] !== 'partial' || $data['nativeCallAttempted'] !== false || $data['interruptedStage'] !== 'questions.0.create'
						|| $graph->questions->findByForm($id) !== [] || $graph->forms->findById($id)->getTitle() !== 'Changed by native edit fixture'
						|| $data['applied']['title'] !== $args['title']) { throw new \RuntimeException('Native concurrent edit proof failed'); }
					return ['scenario' => $scenario, 'accepted' => true, 'status' => 'partial', 'nativeApiEditObserved' => true, 'noQuestionSink' => true, 'receiptOnlyPreviouslyObservedPrefix' => true];
				}
				if ($scenario === 'external-revoke') {
					$policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
					try {
						return $policy->within(FormsScene::READER, function () use ($policy, $graph, $args): array {
							if (!$policy->granted(FormsScene::READER, 'forms', 'create')) { throw new \RuntimeException('Outer snapshot must begin granted'); }
							$writer = new \OCA\Mcp\Modules\Forms\Creation\CreateFormWriter(new CreationAdmissionChange(Server::get(\OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory::class)), Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateFormReadback::class), new \OCA\Mcp\Modules\Forms\Creation\UploadFolderPrecheck(), Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle::class));
							$data = $writer->write($graph, \OCA\Mcp\Modules\Forms\Creation\CreateFormRequest::of($args), FormsScene::READER);
							$id = $data['applied']['formId'] ?? 0;
							if ($data['status'] !== 'partial' || $data['nativeCallAttempted'] !== false || $data['interruptedStage'] !== 'questions.0.create'
								|| $graph->questions->findByForm($id) !== [] || !$policy->granted(FormsScene::READER, 'forms', 'create')) { throw new \RuntimeException('External revocation boundary proof failed'); }
							return ['scenario' => 'external-revoke', 'accepted' => true, 'externalProcessRevocation' => true, 'outerSnapshotStillGranted' => true, 'renewedBoundaryRefused' => true, 'noQuestionSink' => true, 'status' => 'partial'];
						});
					} finally { $policy->setGrant(FormsScene::READER, 'forms', 'create', true); }
				}
				if ($scenario === 'title-race') {
					if (!(\OCA\Mcp\Modules\Forms\FormsVersionProfiles::get($graph->version)['titleFolderSync'] ?? false)) { return ['scenario' => $scenario, 'accepted' => true, 'notApplicableToPin' => true]; }
					$guard = new CreationRacePrecheck();
					$writer = new \OCA\Mcp\Modules\Forms\Creation\CreateFormWriter(Server::get(\OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory::class), Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateFormReadback::class), $guard, Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle::class));
					$data = $writer->write($graph, \OCA\Mcp\Modules\Forms\Creation\CreateFormRequest::of($args), FormsScene::READER);
					$id = $data['applied']['formId'] ?? 0;
					$root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder(FormsScene::READER);
					$file = $root->get('Forms/' . $id . ' - Context title-race/synthetic.txt');
					if ($data['status'] !== 'complete' || $guard->beforeTitle === CreationCollisionPrecheck::snapshot(FormsScene::READER)
						|| $file->getContent() !== 'native rename preserves these synthetic bytes' || count($data['notes']) !== 2) { throw new \RuntimeException('Native residual-race observation failed'); }
					return ['scenario' => $scenario, 'accepted' => true, 'nativeFolderRenameObserved' => true, 'syntheticBytesPreserved' => true, 'receiptWarningPresent' => true, 'deterministicFixtureNotFrequency' => true];
				}
				if ($scenario === 'collision') {
					if (!(\OCA\Mcp\Modules\Forms\FormsVersionProfiles::get($graph->version)['titleFolderSync'] ?? false)) { return ['scenario' => $scenario, 'accepted' => true, 'notApplicableToPin' => true]; }
					$guard = new CreationCollisionPrecheck();
					$writer = new \OCA\Mcp\Modules\Forms\Creation\CreateFormWriter(Server::get(\OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory::class), Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateFormReadback::class), $guard, Server::get(\OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle::class));
					$data = $writer->write($graph, \OCA\Mcp\Modules\Forms\Creation\CreateFormRequest::of($args), FormsScene::READER);
					$id = $data['applied']['formId'] ?? 0; $form = $graph->forms->findById($id);
					if ($data['status'] !== 'partial' || $data['nativeCallAttempted'] !== false || $data['interruptedStage'] !== 'title-check'
						|| $form->getTitle() !== '' || $graph->questions->findByForm($id) !== [] || $guard->before !== CreationCollisionPrecheck::snapshot(FormsScene::READER)
						|| str_contains(json_encode($data), 'occupied') || str_contains(json_encode($data), 'synthetic.txt')) { throw new \RuntimeException('Collision refusal or Files integrity proof failed'); }
					return ['scenario' => $scenario, 'accepted' => true, 'status' => 'partial', 'titleNotApplied' => true, 'questionsNotApplied' => true, 'seededFilesIntact' => true, 'noFilesMetadataInReceipt' => true];
				}
				$plan = $flow->preview($args, FormsScene::READER);
				$result = $flow->handle($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], FormsScene::READER);
				$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
				if ($data['status'] !== 'complete') { throw new \RuntimeException('Real native creation was not complete: ' . json_encode($data)); }
				$id = $data['applied']['formId']; $form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
				if ($form->getOwnerId() !== FormsScene::READER) { throw new \RuntimeException('Wrong persisted owner'); }
				if ($negative) { throw new \RuntimeException('Unproven FormsService identity admitted'); }
				return ['scenario' => $scenario, 'accepted' => true, 'persistedOwnerMatchesActor' => true, 'formId' => $id,
					'initiallyLazy' => $initiallyLazy, 'sameProvenService' => Server::get(\OCA\Forms\Service\FormsService::class) === $service,
					'cachedControllerPrepared' => $cached !== null, 'configCaptureFixture' => $configCapture, 'threeFreshCollaborators' => true, 'formsScopedCreationAllowed' => true, 'mcpScopedCreationWouldDeny' => true];
			} catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $error) {
				if (!$negative) { throw $error; }
				return ['scenario' => $scenario, 'accepted' => false, 'initiallyLazy' => $initiallyLazy, 'sentinelWithoutShares' => true];
			}
		} finally {
			foreach ($saved as $app => $values) {
				if ($values['restrictHad']) { $config->setValueBool($app, 'restrictCreation', $values['restrict']); } else { $config->deleteKey($app, 'restrictCreation'); }
				if ($values['groupsHad']) { $config->setValueArray($app, 'creationAllowedGroups', $values['groups']); } else { $config->deleteKey($app, 'creationAllowedGroups'); }
			}
		}
	}
}
