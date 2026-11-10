<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Creation;

use OCA\Mcp\Modules\Forms\FormsVersionProfiles;
use OCA\Mcp\Modules\Forms\Presentation\CreateFormMessages;
use OCA\Mcp\Modules\Forms\Integration\CreateFormReadback;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Integration\FormsCreateNativeFactory;

/** Performs the approved native sequence once and reports only authorized, observed effects. */
class CreateFormWriter {
	/** Receives the existing admission and focused native/readback collaborators.
	 * @param FormsCreateNativeFactory $factory Renews actor, policy, app and native creation admission.
	 * @param CreateFormReadback $readback Authorized mapper readback.
	 * @param UploadFolderPrecheck $folders Non-mutating native helper predicate.
	 * @param CreateNativeThrottle $throttle Native throttle before/after access errors.
	 */
	public function __construct(private FormsCreateNativeFactory $factory, private CreateFormReadback $readback,
		private UploadFolderPrecheck $folders, private CreateNativeThrottle $throttle) {
	}
	/** Executes only fresh identities generated here, without compensation or automatic retry.
	 * @param CreateFormContext $context Exact per-operation native graph.
	 * @param array<string,mixed> $request Fully validated request shown in the plan.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Complete, partial, refused or unconfirmed receipt.
	 */
	public function write(CreateFormContext $context, array $request, string $uid): array {
		$id = null; $expected = null; $receipt = []; $questionIds = []; $optionIds = [];
		$stage = 'form'; $attempted = false; $titleAttempted = false;
		$invoke = function (callable $call) use ($context, $uid, &$id, &$expected, &$attempted): mixed {
			$attempted = false;
			$this->factory->assertAllowed($uid, $context);
			if ($id !== null && $this->readback->of($context, $id, $uid) !== $expected) { throw new \RuntimeException('Creation prefix changed'); }
			return $this->throttle->invoke($call, static function () use (&$attempted): void { $attempted = true; });
		};
		$observe = function () use ($context, $uid, &$id, &$expected, &$receipt, &$questionIds, &$optionIds): array {
			$this->factory->assertAllowed($uid, $context);
			$expected = $this->readback->of($context, $id, $uid);
			$receipt = $this->readback->receipt($expected, $questionIds, $optionIds);
			return $expected;
		};
		try {
			$data = self::data($invoke(static fn () => $context->controller->newForm(null)), 201);
			$id = self::id(is_array($data) ? ($data['id'] ?? null) : null);
			$initial = $observe();
			if ($initial['header']['title'] !== '' || $initial['header']['description'] !== '' || $initial['questions'] !== []
				|| $initial['header']['expires'] !== 0 || $initial['header']['isAnonymous'] !== false
				|| $initial['header']['submitMultiple'] !== false || $initial['header']['allowEditSubmissions'] !== false || $initial['header']['showExpiration'] !== false) { throw new \RuntimeException('Unexpected native creation defaults'); }
			$stage = 'title-check'; $attempted = false;
			$this->factory->assertAllowed($uid, $context);
			$this->folders->assertClear($context, $id, $uid);
			$stage = 'metadata';
			self::data($invoke(function () use ($context, $id, $request, &$titleAttempted): mixed {
				$titleAttempted = true;
				return $context->controller->updateForm($id, ['title' => $request['title'], 'description' => $request['description']]);
			}), 200);
			$metadata = $observe();
			if ($metadata['header']['title'] !== $request['title'] || $metadata['header']['description'] !== $request['description']) { throw new \RuntimeException('Metadata differs'); }
			foreach ($request['questions'] as $index => $q) {
				$stage = 'questions.' . $index . '.create';
				$data = self::data($invoke(static fn () => $context->controller->newQuestion($id, $q['type'], $q['settings']['questionType'] ?? null, $q['text'], null, null)), 201);
				$qid = self::id(is_array($data) ? ($data['id'] ?? null) : null);
				if (in_array($qid, $questionIds, true)) { throw new \RuntimeException('Reused question identity'); }
				$questionIds[] = $qid; $current = $observe();
				$created = self::question($current, $qid);
				if (count($current['questions']) !== count($questionIds) || $created['type'] !== $q['type'] || $created['text'] !== $q['text'] || $created['description'] !== '' || $created['isRequired'] !== false || $created['options'] !== []) { throw new \RuntimeException('Question differs'); }
				$stage = 'questions.' . $index . '.settings';
				self::data($invoke(static fn () => $context->controller->updateQuestion($id, $qid, ['description' => $q['description'], 'isRequired' => $q['required'], 'extraSettings' => $q['settings']])), 200);
				$updated = self::question($observe(), $qid);
				if ($updated['description'] !== $q['description'] || $updated['isRequired'] !== $q['required'] || $updated['extraSettings'] !== $q['settings']) { throw new \RuntimeException('Settings differ'); }
				foreach ($q['options'] as $category => $texts) {
					if ($texts === []) { continue; }
					$stage = 'questions.' . $index . '.options.' . $category;
					$data = self::data($invoke(static fn () => $context->controller->newOption($id, $qid, $texts, $category)), 201);
					if (!is_array($data) || !array_is_list($data)) { throw new \RuntimeException('Unexpected option batch'); }
					$batch = [];
					foreach ($data as $row) {
						$oid = self::id(is_array($row) ? ($row['id'] ?? null) : null);
						if (in_array($oid, $optionIds, true) || in_array($oid, $batch, true)) { throw new \RuntimeException('Reused option identity'); }
						$batch[] = $oid;
					}
					$optionIds = array_merge($optionIds, $batch); $actual = self::question($observe(), $qid);
					$applied = array_values(array_filter($actual['options'], static fn (array $o): bool => in_array($o['id'], $batch, true)));
					if (array_column($applied, 'id') !== $batch || array_column($applied, 'text') !== $texts || array_unique(array_column($applied, 'optionType')) !== [$category]) { throw new \RuntimeException('Option batch incomplete or changed'); }
				}
			}
			return ['status' => 'complete', 'applied' => $receipt, 'notes' => self::notes($context, $titleAttempted)];
		} catch (\Throwable $error) {
			return ['status' => $attempted ? 'unconfirmed' : ($receipt === [] ? 'refused' : 'partial'), 'applied' => $receipt,
				'interruptedStage' => $stage, 'nativeCallAttempted' => $attempted,
				'message' => $error instanceof \OCP\Security\Bruteforce\MaxDelayReached ? CreateFormMessages::throttled() : ($stage === 'title-check' ? CreateFormMessages::precheck() : CreateFormMessages::stopped()),
				'notes' => self::notes($context, $titleAttempted)];
		}
	}
	/** Accepts only the expected native response status, never treating 201 alone as effect proof.
	 * @param mixed $response Native controller response.
	 * @param int $status Expected native status.
	 * @return mixed Native data kept internal until authorized readback.
	 */
	private static function data(mixed $response, int $status): mixed {
		if (!$response instanceof \OCP\AppFramework\Http\DataResponse || $response->getStatus() !== $status) { throw new \RuntimeException('Unexpected native response'); }
		return $response->getData();
	}
	/** Validates a newly returned stable identity without accepting caller IDs.
	 * @param mixed $id Native returned identity.
	 * @return int Positive identity.
	 */
	private static function id(mixed $id): int { if (!is_int($id) || $id < 1) { throw new \RuntimeException('Missing native identity'); } return $id; }
	/** Finds an observed question without falling back to the submitted request.
	 * @param array<string,mixed> $snapshot Authorized readback.
	 * @param int $id Newly returned question ID.
	 * @return array<string,mixed> Observed row.
	 */
	private static function question(array $snapshot, int $id): array {
		foreach ($snapshot['questions'] as $q) { if ($q['id'] === $id) { return $q; } }
		throw new \RuntimeException('Question not observed');
	}
	/** Keeps sequence and native Files observation limits explicit without alleging unobserved mutations.
	 * @param CreateFormContext $context Native version.
	 * @param bool $titleAttempted Whether title synchronization could have run.
	 * @return list<string> Fixed receipt notes.
	 */
	private static function notes(CreateFormContext $context, bool $titleAttempted): array {
		return [CreateFormMessages::sequence(), ...((FormsVersionProfiles::get($context->version)['titleFolderSync'] ?? false) && $titleAttempted ? [CreateFormMessages::warning($context->version)] : [])];
	}
}
