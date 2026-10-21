<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Execution;

use Psr\Log\LoggerInterface;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

class PreviewBoundary {
    public function __construct(private LoggerInterface $logger, private ?UserTimezone $zones = null) {
    }

    public function run(
        string $name,
        string $userId,
        callable $build,
    ): array {
        try {
            $plan = $build();
            $plan['timezone'] = $this->zones?->forUser($userId)->getName();
            return $plan;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('MCP Deck plan failed: {tool} ({exception})', ['tool' => $name, 'exception' => $e::class]);
            throw new ToolFailure(DeckErrors::messageFor($e));
        }
    }
}
