<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use OCA\Mcp\Access\Policy\Events\PolicyOutbox;

/** Transaction-local capability; invalidated before commit or rollback and never owns either. */
final class PolicyAttempt {
    private bool $active = true;
    private bool $cut = false;

    /** @param array{revision:string, lock_seq:string, auth_epoch:string} $state */
    public function __construct(private array $state, private string $commandId, private PolicyOutbox $outbox) {}

    public function assertActive(): void {
        if (!$this->active) {
            throw new \LogicException('Policy attempt is no longer active');
        }
    }

    /** @return array{revision:string, lock_seq:string, auth_epoch:string} */
    public function state(): array {
        $this->assertActive();
        return $this->state;
    }

    public function commandId(): string {
        $this->assertActive();
        return $this->commandId;
    }

    public function outbox(): PolicyOutbox {
        $this->assertActive();
        return $this->outbox;
    }

    public function markCut(): void {
        $this->assertActive();
        $this->cut = true;
    }

    public function hasCut(): bool {
        $this->assertActive();
        return $this->cut;
    }

    public function setAuthEpoch(string $epoch): void {
        $this->assertActive();
        $this->state['auth_epoch'] = \OCA\Mcp\Access\Policy\DecimalCounter::validate($epoch, \OCA\Mcp\Access\Policy\DecimalCounter::MAX);
    }

    /** Called only by the transaction owner when leaving the application callback. */
    public function close(): void {
        $this->active = false;
    }
}
