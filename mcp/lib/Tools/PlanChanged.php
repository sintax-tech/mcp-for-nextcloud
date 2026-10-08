<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Thrown by a confirmed write whose {@see PlanState} no longer matches: the state changed since the plan the person
 * approved, so nothing was written. The registry answers with {@see self::$plan} exactly as it answers a call without
 * `confirm`, envelope and readable text included, so the person sees the new plan and is asked again.
 *
 * It is neither a {@see ToolFailure} nor an argument error on purpose: the answer is a plan, not an error.
 */
final class PlanChanged extends \RuntimeException {
    /**
     * @param array<string, mixed> $plan the plan of the state found now, as the module's preview would return it,
     *   with its new {@see PlanState::ARGUMENT} and a warning that it changed
     */
    public function __construct(public readonly array $plan) {
        parent::__construct('The state changed since the plan');
    }
}
