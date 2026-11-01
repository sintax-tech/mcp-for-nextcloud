<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tools\Forms\Attachments\ResultFileAccess;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** One read pipeline shared by the two handlers; no native writes, export, SQL or actor impersonation. */
class ResultsReadFlow {
	/** Receives focused read-only boundaries and an existing policy operation.
	 * @param GrantPolicy $policy Exact results grant and actor context.
	 * @param ResultsAccess $access Native Forms RESULTS access.
	 * @param SubmissionReads $reads Explicit common native mappers.
	 * @param ResultsSchema $schema Active form-relative schema.
	 * @param SubmissionShape $shape Answer/identity whitelist.
	 * @param ResultFileAccess $files Independent actor Files boundary.
	 * @param PlanState $mac Existing instance MAC.
	 */
	public function __construct(private GrantPolicy $policy, private ResultsAccess $access, private SubmissionReads $reads,
		private ResultsSchema $schema, private SubmissionShape $shape, private ResultFileAccess $files, private PlanState $mac) {
	}
	/** Reads one page or one submission, revalidating native access and selected identities before returning.
	 * @param array $arguments Validated arguments.
	 * @param string $uid Authenticated actor.
	 * @param bool $list Page mode.
	 * @return array MCP JSON result.
	 */
	public function run(array $arguments, string $uid, bool $list): array {
		try {
			return $this->policy->within($uid, function () use ($arguments, $uid, $list): array {
				if (!$this->policy->granted($uid, 'forms', 'results')) { throw new ToolFailure(FormsMessages::notFound()); }
				$formId = $arguments['form_id']; $context = $this->access->open($uid, $formId); $limit = $arguments['limit'] ?? 20;
				$meta = []; $headers = [];
				if ($list) {
					$cursor = new SubmissionCursor($this->mac, $uid, ['formId' => $formId, 'mode' => $context['mode'], 'version' => $context['version'], 'limit' => $limit], $this->maxOffset());
					$given = $cursor->decode($arguments['cursor'] ?? null);
					$headers = SubmissionOrder::sorted($this->reads->headers($formId, $uid, $context['mode']));
					$state = SubmissionOrder::digest($this->mac, $uid, $headers);
					if ($given['s'] !== null && !hash_equals($state, $given['s'])) { throw new ToolFailure(ResultsMessages::stale()); }
					$selected = array_slice($headers, $given['o'], $limit); $next = $given['o'] + count($selected); $more = $next < count($headers); $reachable = $more && $next <= $this->maxOffset();
					$meta = ['hasMore' => $more, 'nextCursor' => $reachable ? $cursor->encode($next, $state) : null, 'offsetLimitReached' => $more && !$reachable];
				} else { $selected = [$this->reads->one($arguments['submission_id'], $formId, $uid, $context['mode'])]; }
				$active = $this->schema->active($formId); $filesAllowed = $this->files->begin($uid);
				$projected = []; $answerCount = 0;
				foreach ($selected as $header) {
					$submission = $this->shape->of($header, $active, $uid, $filesAllowed, $list);
					$answerCount += count($submission['answers']);
					if ($answerCount > ResultsBudget::PAGE_ANSWERS) { throw new ToolFailure(ResultsMessages::budget($list)); }
					$projected[] = $submission;
				}
				$current = $this->access->open($uid, $formId);
				if ($context['mode'] !== $current['mode'] || $context['version'] !== $current['version']) { throw new ToolFailure(ResultsMessages::stale()); }
				foreach ($selected as $header) {
					if ($this->reads->one($header['id'], $formId, $uid, $current['mode']) !== $header) { throw new ToolFailure(ResultsMessages::stale()); }
				}
				$data = ['formId' => $formId, 'accessMode' => $current['mode'], 'questions' => array_values($active)];
				$data[$list ? 'submissions' : 'submission'] = $list ? $projected : $projected[0];
				return ToolResult::json(ResultsBudget::envelope($data, $meta, $list));
			});
		} catch (ToolFailure|PolicyUnavailable $error) { throw $error; }
		catch (\OCA\Forms\Exception\NoSuchFormException $error) { throw new ToolFailure(FormsMessages::notFound(), 0, $error); }
		catch (\Throwable $error) { throw new ToolFailure(FormsMessages::unavailable(), 0, $error); }
	}
	/** Keeps the cursor producer and consumer ceiling identical, reducible in boundary tests.
	 * @return int Largest reachable offset.
	 */
	protected function maxOffset(): int { return SubmissionCursor::MAX_OFFSET; }
}
