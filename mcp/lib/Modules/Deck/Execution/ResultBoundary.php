<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Execution;

use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckErrors;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Execution\DeckResult;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;
use Psr\Log\LoggerInterface;

class ResultBoundary {
    public function __construct(private LoggerInterface $logger) {
    }

    /**
     * Runs a Deck operation and wraps the outcome in an MCP result.
     *
     * `InvalidArgumentException` is rethrown because the registry turns it into a -32602 for the
     * caller; every other exception becomes a generic error result and is logged by class only.
     *
     * @param callable(): mixed $operation Deck operation to run.
     * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
     * @throws InvalidArgumentException When the operation rejects its arguments.
     */
    public function run(
        string $tool,
        string $readWith,
        callable $operation,
    ): array {
        try {
            return DeckResult::ok($operation());
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (DeckUnconfirmedException $e) {
            // Maybe saved: an answer that is not an error and says where to look, never one that invites a retry.
            $this->logger->warning(
                'MCP Deck write not confirmed: {tool} ({exception})',
                ['tool' => $tool, 'exception' => $e->getPrevious() === null ? $e::class : $e->getPrevious()::class],
            );
            return DeckResult::ok(['confirmed' => false, 'message' => WriteMessages::writeUnconfirmed($e->readWith)]);
        } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            $context = ['tool' => $tool, 'exception' => $e::class];
            if ($e instanceof DeckRefusalException && $e->afterWrite()) {
                // The delete stays (the undo failed): partial, with the message that points to the Deck trash.
                $this->logger->warning('MCP Deck tool refused after the write: {tool}', $context);
                return DeckResult::ok(['partial' => true, 'message' => $e->getMessage()]);
            }
            if ($e instanceof DeckConflictException || $e instanceof DeckRefusalException) {
                $this->logger->warning('MCP Deck tool refused or conflicted: {tool}', $context);
            } else {
                $this->logger->error('MCP Deck tool failed: {tool} ({exception})', $context);
            }
            return DeckResult::error(DeckErrors::messageFor($e));
        }
    }
}
