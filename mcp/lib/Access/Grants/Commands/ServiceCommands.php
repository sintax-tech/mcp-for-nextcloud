<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\Commands\GrantMutations;
use OCA\Mcp\Db\PolicyEventsMapper;

final class ServiceCommands {
    public function __construct(private PolicyTransaction $transaction, private GrantMutations $mutations) {}

    public function setGlobalEnabled(bool $enabled): void {
        $this->transaction->runInternal([], static fn () => null,
            fn ($snapshot, PolicyAttempt $attempt): bool => $this->mutations->write($attempt, null, GrantPolicy::SERVICE_KEY, $enabled ? '1' : '0') !== null);
    }

    public function adminSetGlobalEnabled(string $expectedRevision, string $actor, bool $enabled): array {
        if (!PolicyEventPayload::validUid($actor)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $value = $enabled ? '1' : '0';
        $result = $this->transaction->runAdmin($expectedRevision, [], fn () => null,
            function ($snapshot, PolicyAttempt $attempt) use ($actor, $value): bool {
                $current = $attempt->state();
                $outbox = $attempt->outbox();
                $change = $this->mutations->write($attempt, null, GrantPolicy::SERVICE_KEY, $value);
                if ($change === null) {
                    return false;
                }
                $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_SERVICE,
                    $current['revision'], DecimalCounter::increment($current['revision']), null, null, [$change]));
                return true;
            });
        return ['revision' => $result['revision'], 'changed' => $result['changed']];
    }
}
