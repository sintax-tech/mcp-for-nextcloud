<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Execution;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Psr\Log\LoggerInterface;

class PreviewBoundary
{
    public function __construct(private TalkErrors $errors, private LoggerInterface $logger) {}
    public function run(string $name, \Closure $operation): array
    {
        try {
            return $operation();
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Talk tool failed', [
                'app' => 'mcp',
                'tool' => $name,
                'exception' => $e::class,
                'exception_message' => TalkErrors::loggable($e),
            ]);
            throw new ToolFailure($this->errors->messageFor($e));
        }
    }
}
