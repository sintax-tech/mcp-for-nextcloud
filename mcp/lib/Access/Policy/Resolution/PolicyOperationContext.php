<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Resolution;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Resolution\EffectiveResult;
use OCA\Mcp\Access\Policy\Resolution\EligibilityResolution;
use OCA\Mcp\Access\Policy\Resolution\GrantResolution;
use OCA\Mcp\Access\ResourceScope\ScopeResolution;
use OCA\Mcp\Access\ResourceScope\ScopeGuard;

/** Explicitly owned by one operation; close in finally. Never register this snapshot as a shared service. */
final class PolicyOperationContext {
    private bool $closed = false;

    /**
     * @param array<string,array{present:bool,value?:mixed}> $raw
     * @param list<array{groupId:string,grants:array,trusted:bool,state:?string,gen:?string}> $candidates
     * @param array<string,bool> $apps
     */
    public function __construct(
        public readonly string $revision,
        private PolicyTransaction $writer,
        private array $raw,
        private array $candidates,
        private bool $accountEnabled,
        private bool $serviceEnabled,
        private array $apps,
        private bool $logsAvailable,
        private bool $logsPermitted,
        private ?ScopeGuard $scope,
        private bool $grantsOnly = false,
    ) {}

    public function close(): void {
        $this->closed = true;
        $this->raw = $this->candidates = $this->apps = [];
        $this->scope = null;
    }

    /** The resource scope read with this snapshot; consumers ask it about each resource after the grant. */
    public function scope(): ScopeGuard {
        $this->check();
        return $this->scope ?? throw new PolicyUnavailable('Policy operation is closed');
    }

    /** Candidates retain untrusted/non-active policies for diagnosis, independently of granting sources. */
    public function candidates(): array {
        $this->check();
        return $this->candidates;
    }

    /** @return bool whether the user switched the personal connection on (raw '1'); anything else is off */
    public function connected(): bool {
        $this->check();
        return ($this->raw[GrantPolicy::CONNECTED_KEY]['value'] ?? null) === '1';
    }

    /** @return bool whether the administrator switched the service on */
    public function serviceEnabled(): bool {
        $this->check();
        return $this->serviceEnabled;
    }

    /**
     * The lifecycle gens of granting groups, as this snapshot read them: what a writer command compares again under
     * its mutex before acting on a decision those groups made (a pending_delete does not move the revision).
     *
     * @param list<string> $groupIds groups a resolution named as its sources
     * @return list<array{groupId:string, gen:string}> the groups among them that grant in this snapshot, with their
     *     gen (a list, so a numeric-looking id never becomes an integer key)
     */
    public function lifecycles(array $groupIds): array {
        $this->check();
        $gens = [];
        foreach ($this->candidates as $candidate) {
            if (in_array($candidate['groupId'], $groupIds, true) && $candidate['trusted']
                && $candidate['state'] === GroupLifeMapper::ACTIVE && is_string($candidate['gen'])) {
                $gens[] = ['groupId' => $candidate['groupId'], 'gen' => $candidate['gen']];
            }
        }
        return $gens;
    }

    public function eligible(): EligibilityResolution {
        $result = $this->resolve(GrantPolicy::ELIGIBLE_KEY, '_system', 'eligible', false);
        return new EligibilityResolution($result->allowed, $result->source, $result->groupIds, $result->rawState);
    }

    /**
     * The eligibility the user would have without an individual value: what a reset of an individual Allow, or an undo
     * that restores absence, leaves (contracts §3.1, §4.1). Group sources and unknown candidates as in eligible().
     */
    public function inheritedEligibility(): EligibilityResolution {
        $result = $this->resolve(GrantPolicy::ELIGIBLE_KEY, '_system', 'eligible', false, true);
        return new EligibilityResolution($result->allowed, $result->source, $result->groupIds, $result->rawState);
    }

    public function granted(string $module, string $operation): GrantResolution {
        self::validate($module, $operation);
        return $this->resolve('grant_' . $module . '_' . $operation, $module, $operation,
            GrantPolicy::defaultGranted($module, $operation));
    }

    /**
     * @param string $module catalog module
     * @param string $operation catalog operation
     * @param list<string>|null $resourceIds the resource and, for Files, its ancestors; null evaluates the module
     *        only, where a restriction narrows resources later and never blocks the module itself
     */
    public function resolveEffective(string $module, string $operation, ?array $resourceIds = null): EffectiveResult {
        $grant = $this->granted($module, $operation);
        $eligibility = $this->eligible();
        $scope = $this->scope()->resolution($module);
        $gate = match (true) {
            !$this->accountEnabled => 'account',
            !$this->serviceEnabled => 'service',
            $eligibility->allowed === null => 'eligibility_unknown',
            !$eligibility->allowed => 'eligibility',
            !$this->connected() => 'connection',
            !$this->apps[$module] => 'app',
            $module === 'logs' && !$this->logsAvailable => 'logs_available',
            $module === 'logs' && !$this->logsPermitted => 'logs_role',
            $grant->allowed === null => 'grant_unknown',
            !$grant->allowed => 'grant',
            // The scope only narrows what the grant allows, so a missing grant is always the first block.
            $scope->mode === ScopeResolution::UNAVAILABLE => 'resource_scope_unknown',
            $resourceIds !== null && !$this->scope()->allows($module, $resourceIds) => 'resource_scope',
            default => null,
        };
        return new EffectiveResult($gate === null ? true : (str_ends_with($gate, '_unknown') ? null : false),
            $gate === 'resource_scope' ? 'out_of_scope' : ($gate ?? 'allowed'), $grant, $gate, $scope);
    }

    /** Same resolution without the override: admin counting only, not an access decision. */
    public function inherited(string $module, string $operation): GrantResolution {
        $this->check();
        if (!$this->grantsOnly) {
            throw new \LogicException('Inherited resolution is restricted to admin counting contexts');
        }
        if ($module === '_system' && $operation === 'eligible') {
            return $this->resolve(GrantPolicy::ELIGIBLE_KEY, $module, $operation, false, true);
        }
        self::validate($module, $operation);
        return $this->resolve('grant_' . $module . '_' . $operation, $module, $operation,
            GrantPolicy::defaultGranted($module, $operation), true);
    }

    /** @param bool $inherited ignore the individual value, as if it were absent */
    private function resolve(string $key, string $module, string $operation, bool $default, bool $inherited = false): GrantResolution {
        $this->check();
        $raw = $inherited ? ['present' => false] : ($this->raw[$key] ?? ['present' => false]);
        if ($raw['present']) {
            return match ($raw['value']) {
                '1' => new GrantResolution(true, 'individual_allow', [], $raw),
                '0' => new GrantResolution(false, 'individual_deny', [], $raw),
                default => new GrantResolution(false, 'invalid', [], $raw),
            };
        }
        $sources = $unknown = [];
        foreach ($this->candidates as $candidate) {
            if (!in_array($operation, $candidate['grants'][$module] ?? [], true)) {
                continue;
            }
            if ($candidate['trusted'] && $candidate['state'] === GroupLifeMapper::ACTIVE) {
                $sources[] = $candidate['groupId'];
            } else {
                $unknown[] = $candidate['groupId'];
            }
        }
        sort($sources, SORT_STRING);
        sort($unknown, SORT_STRING);
        if ($sources !== []) {
            return new GrantResolution(true, 'group', $sources, $raw);
        }
        // Group rows only grant. With an allowing default no answer from an indeterminate group can change the
        // outcome, so the result is unknown only when it depended on that group alone (§3.3.5); the group stays
        // visible through candidates() for diagnosis.
        if ($unknown !== [] && !$default) {
            return new GrantResolution(null, 'unknown', $unknown, $raw);
        }
        return new GrantResolution($default, 'default', [], $raw);
    }

    private function check(): void {
        if ($this->closed) {
            throw new PolicyUnavailable('Policy operation is closed');
        }
        $this->writer->assertUsable();
    }

    private static function validate(string $module, string $operation): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
    }
}
