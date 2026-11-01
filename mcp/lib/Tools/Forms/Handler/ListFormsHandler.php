<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Handler;

use OCA\Mcp\Tools\Forms\FormsServices;
use OCA\Mcp\Tools\Forms\FormsCalls;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Tools\Forms\Discovery\FormCandidates;
use OCA\Mcp\Tools\Forms\Discovery\FormListOrder;
use OCA\Mcp\Tools\Forms\Discovery\FormListCursor;
use OCA\Mcp\Tools\Forms\Structure\FormFieldWhitelist;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** Lists one MAC-authenticated page of the currently reauthorized native candidate list. */
class ListFormsHandler {
	/** Receives read-only collaborators; no native app service is eagerly resolved.
	 * @param FormsServices $forms Enabled and identity-proven services.
	 * @param FormCandidates $candidates Native owned/shared discovery.
	 * @param FormFieldWhitelist $shape Unique list/read whitelist.
	 * @param PlanState $mac Stateless instance-secret MAC.
	 */
	public function __construct(private FormsServices $forms, private FormCandidates $candidates, private FormFieldWhitelist $shape, private PlanState $mac) {
	}

	/** Discovers and reauthorizes every page, rejecting a cursor after membership or timestamp changes.
	 * @param array<string,mixed> $arguments Validated limit and optional cursor.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> MCP JSON result; no submission counts.
	 */
	public function handle(array $arguments, string $uid): array {
		return FormsCalls::run(function () use ($arguments, $uid): array {
			$context = $this->forms->open($uid);
			$items = FormListOrder::sorted($this->candidates->all($context['mapper'], $context['service'], $context['user']));
			$limit = (int)($arguments['limit'] ?? 50);
			$cursor = new FormListCursor($this->mac, 'forms_list_forms', $uid, $this->maxOffset(),
				['version' => $context['version'], 'limit' => $limit, 'state' => FormListOrder::digest($items)]);
			$offset = $cursor->offset($arguments['cursor'] ?? null);
			$page = array_slice($items, $offset, $limit);
			$next = $offset + count($page);
			$more = $next < count($items);
			$reachable = $more && $next <= $this->maxOffset();
			return ToolResult::json(\OCA\Mcp\Tools\Forms\Structure\FormsResponseBudget::envelope(
				['forms' => array_map(fn ($form): array => $this->shape->of($form, $context['service'], false), $page)],
				['hasMore' => $more, 'nextCursor' => $reachable ? $cursor->encode($next) : null, 'offsetLimitReached' => $more && !$reachable],
				FormsMessages::listResponseLimit(),
			));
		});
	}

	/** Returns the same offset ceiling for cursor decoding and continuation, overridable in focused boundary tests.
	 * @return int Largest reachable page offset.
	 */
	protected function maxOffset(): int {
		return FormListCursor::MAX_OFFSET;
	}
}
