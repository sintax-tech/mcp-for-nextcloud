<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;

use OCA\Mcp\Db\GroupLifeMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Persists observed loss of identity trust without remote I/O under the writer's mutex. */
class GroupTrustObserver {
    public function __construct(
        private IDBConnection $db,
        private PolicyTransaction $writer,
        private GroupLifeMapper $life,
        private LoggerInterface $logger,
    ) {}

    /** @param list<array{groupId:string, gen:string}> $lost observations of active lifecycles */
    public function observe(array $lost): void {
        if ($lost === []) {
            return;
        }
        if ($this->db->inTransaction()) {
            $this->logger->warning('Group trust lost inside a foreign transaction; grants are withheld', ['app' => 'mcp']);
            return;
        }
        $this->writer->runInternal([], static fn () => null, function () use ($lost): bool {
            $changed = false;
            foreach ($lost as $row) {
                $current = $this->life->get($row['groupId']);
                if (($current['state'] ?? null) === GroupLifeMapper::ACTIVE && $current['gen'] === $row['gen']) {
                    $changed = $this->life->transition($row['groupId'], $row['gen'], GroupLifeMapper::NEEDS_CONFIRM,
                        bin2hex(random_bytes(16))) || $changed;
                }
            }
            return $changed;
        });
    }
}
