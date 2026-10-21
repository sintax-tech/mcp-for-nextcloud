<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

/** A validated plan, plus the confirmation a shared item needs when the caller has not given it. */
final class MovePlan {
    /**
     * @param array<string, mixed> $plan the plan as the client sees it
     * @param list<array{from:string, to:string}> $planned every item that passed the checks, in the order of the batch,
     *   the shared ones included: they run once the caller has the shared confirmation
     * @param array<string, mixed>|null $confirmation the guard payload for the first non-personal item
     */
    public function __construct(
        public readonly array $plan,
        public readonly array $planned,
        public readonly ?array $confirmation,
    ) {}

    /** @return bool whether every item may run */
    public function isOk(): bool {
        return (bool)$this->plan['ok'];
    }
}
