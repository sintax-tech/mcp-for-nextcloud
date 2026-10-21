<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Execution;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;
use Psr\Log\LoggerInterface;

class VerifiedWrite {
    public function __construct(private LoggerInterface $logger) {
    }

    private const RAISED_BY_THE_APP = [
        DeckRefusalException::class,
        DeckSessionException::class,
        DeckConflictException::class,
        DeckUnconfirmedException::class,
        ArgumentValidationException::class,
    ];
    /**
     * Runs one Deck write and, when the write call itself throws, answers with what Deck really holds.
     *
     * A refusal of this app ({@see self::RAISED_BY_THE_APP}) is rethrown as it is. Any other exception, Deck's own
     * `NoPermissionException` and `BadRequestException` included, is followed by `$verify`, which reads the state
     * again by identifier: what it returns is the saved result, and the call goes on as a success with
     * {@see \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed()} in `$warnings`; null means nothing was saved and the original exception
     * goes on to become the usual error; an exception of `$verify` means nobody can tell, which becomes
     * {@see DeckUnconfirmedException}. Only exception classes are logged.
     *
     * @template T
     * @param callable(): T $write The Deck write.
     * @param callable(): mixed $verify Read-back: the saved result, or null when the write did not happen.
     * @param list<string> $warnings Receives the warning when the write was saved and Deck failed afterwards.
     * @return mixed What the write returned, or what `$verify` found.
     * @throws DeckUnconfirmedException When the read-back fails too.
     * @throws \Throwable The original exception, when nothing was saved.
     */
    public function afterWrite(
        string $tool,
        string $readWith,
        callable $write,
        callable $verify,
        array &$warnings,
    ): mixed {
        $probable = false;
        return $this->guardedWrite($tool, $readWith, $write, $verify, $warnings, false, $probable);
    }

    /**
     * Like {@see self::afterWrite()}, for a creation whose read-back only finds the item by likeness (owner, title and
     * moment; a title that was not in the snapshot) and so cannot prove that this call made it.
     *
     * What `$verify` finds is only probably the item: `$probable` becomes true and `$warnings` gets
     * {@see \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probablyCreated()} in place of the "saved" warning. The caller must not write anything
     * else on that item (no assignment, no list or card inside it).
     *
     * @template T
     * @param callable(): T $write The Deck write.
     * @param callable(): mixed $verify Read-back by likeness: the item found, or null when there is none.
     * @param list<string> $warnings Receives the warning.
     * @param bool $probable Set to true when the result came from the read-back by likeness.
     * @return mixed What the write returned, or what `$verify` found.
     * @throws DeckUnconfirmedException When the read-back fails too.
     * @throws \Throwable The original exception, when nothing was found.
     */
    public function afterApproximateWrite(
        string $tool,
        string $readWith,
        callable $write,
        callable $verify,
        array &$warnings,
        bool &$probable,
    ): mixed {
        return $this->guardedWrite($tool, $readWith, $write, $verify, $warnings, true, $probable);
    }

    /**
     * Shared body of {@see self::afterWrite()} and {@see self::afterApproximateWrite()}.
     *
     * @param callable(): mixed $write The Deck write.
     * @param callable(): mixed $verify Read-back.
     * @param list<string> $warnings Receives the warning.
     * @param bool $approximate Whether the read-back only finds the item by likeness.
     * @param bool $probable Set to true when `$approximate` and the read-back found something.
     * @return mixed What the write returned, or what `$verify` found.
     */
    private function guardedWrite(
        string $tool,
        string $readWith,
        callable $write,
        callable $verify,
        array &$warnings,
        bool $approximate,
        bool &$probable,
    ): mixed {
        try {
            return $write();
        } catch (\Throwable $e) {
            foreach (self::RAISED_BY_THE_APP as $class) {
                if (is_a($e, $class)) {
                    throw $e;
                }
            }
            try {
                $saved = $verify();
            } catch (\Throwable) {
                throw new DeckUnconfirmedException($readWith, $e);
            }
            if ($saved === null) {
                throw $e;
            }
            $this->logger->warning(
                'MCP Deck write saved, then Deck failed: {tool} ({exception})',
                ['tool' => $tool, 'exception' => $e::class],
            );
            $warning = $approximate ? WriteMessages::probablyCreated() : WriteMessages::writtenThenFailed();
            $probable = $approximate;
            if (!in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
            return $saved;
        }
    }
}
