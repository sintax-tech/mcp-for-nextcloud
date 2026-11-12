<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Activity\Integration\ActivityGateway;
use OCA\Mcp\Modules\Activity\Presentation\ActivitySchema;
use Throwable;

/** Bounded post-filter over the actor's authorized native stream, never over another user's feed. */
final class ListActivityHandler {
	/**
	 * @param ActivityGateway $native lazy native read
	 * @param ActivityInput $input strict filters
	 */
	public function __construct(private ActivityGateway $native, private ActivityInput $input) {}
	/**
	 * @param string $userId authenticated actor
	 * @param array<string,mixed> $arguments explicit filters
	 * @return array<string,mixed> limited metadata and deliberate continuation
	 */
	public function handle(string $userId, array $arguments): array {
		try {
			$filters = $this->input->parse($arguments);
			$since = $filters['since']; $examined = 0; $items = []; $more = true;
			while ($more && $examined < 500 && count($items) < $filters['limit']) {
				$page = $this->native->page($userId, $since, min(50, 500 - $examined));
				$more = $page['has_more'];
				if ($page['data'] === [] && $more) throw new PolicyUnavailable('Activity pagination is unavailable.');
				foreach ($page['data'] as $position => $row) {
					$id = filter_var($row['activity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
					if ($id === false || $id === $since) throw new PolicyUnavailable('Activity pagination is unavailable.');
					$since = $id; $examined++;
					if (!$this->objectFree($row)) continue;
					$timestamp = filter_var($row['timestamp'] ?? null, FILTER_VALIDATE_INT);
					$app = $row['app'] ?? null;
					if ($timestamp === false || !is_string($app) || !preg_match('/^[a-z0-9_]{1,64}$/D', $app)) continue;
					if (($filters['from'] !== null && $timestamp < $filters['from']) || ($filters['to'] !== null && $timestamp >= $filters['to'])
						|| ($filters['app'] !== null && $app !== $filters['app'])) continue;
					$items[] = ['id' => $id, 'app' => $app, 'datetime' => (new DateTimeImmutable('@' . $timestamp))->format(DATE_ATOM)];
					if (count($items) === $filters['limit']) {
						$more = $more || $position < count($page['data']) - 1;
						break;
					}
				}
			}
			return ToolResult::json(['items' => $items, 'partial' => true, 'coverage' => ActivitySchema::LIMITED,
				'truncated' => $more, 'next_since' => $more ? $since : null,
				'continuation' => $more ? array_replace($arguments, ['since' => $since]) : null]);
		} catch (PolicyUnavailable $error) { throw $error;
		} catch (InvalidArgumentException|\OutOfBoundsException) {
			return ToolResult::error(Translator::t('Provide valid Activity filters and an activity ID from your own feed.'));
		} catch (Throwable) {
			return ToolResult::error(Translator::t('The Activity feed is unavailable.'));
		}
	}
	/**
	 * @param array<string,mixed> $row native unformatted row
	 * @return bool only rows provably devoid of references qualify in ACT-1
	 */
	private function objectFree(array $row): bool {
		foreach (['object_type', 'file', 'object_name', 'link'] as $key) if (!in_array($row[$key] ?? null, ['', null], true)) return false;
		if (!in_array($row['object_id'] ?? null, [0, '0', '', null], true) || !in_array($row['objects'] ?? null, [[], null], true)) return false;
		foreach (['subjectparams', 'messageparams'] as $key) if (!in_array($row[$key] ?? '[]', ['[]', '{}', '', [], null], true)) return false;
		return true;
	}
}
