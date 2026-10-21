<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCP\IConfig;

/**
 * The administrator's OAuth client settings (allowed CIMD hosts, the native client, the self-hosted clients) as one
 * policy command (contracts §2.1.1, §2.3, §3.1): the page's expected revision is compared and the revision advanced by
 * the PolicyTransaction, and everything below runs under the same global mutex that issuance (consent, exchange, refresh)
 * takes, in one transaction:
 * - the stored clients are read there from the app's own table (OAuthClientsMapper, the only source of admission;
 *   never the AppConfig caches) and the request is validated against them (known ids, withdrawals), so a save made
 *   from an outdated page is a conflict and never rebuilds a list from stale data;
 * - the new values are written and the credentials of every client the change withdraws (turned off, deleted, or
 *   missing a redirect URI it had) are deleted before the commit. A failed delete rolls the whole command back: the
 *   table is never left withdrawn with credentials still valid, nor the other way round;
 * - an issuance that read the old list before this command either runs after it and finds the client withdrawn (it
 *   reads the list again under the mutex) or ran before it, and its rows are deleted here. There is no per-user cut:
 *   the user's other clients keep their connections.
 */
class OAuthClientsService {
    public function __construct(
        private PolicyTransaction $writer,
        private IConfig $config,
        private OAuthClientsMapper $clients,
        private OAuthStore $oauth,
    ) {}

    /**
     * @param string $expectedRevision the policy revision the page was built from
     * @param list<string>|null $hosts validated allowed hosts, null to leave them
     * @param bool|null $native the native client's setting, null to leave it
     * @param mixed $custom the full list of self-hosted clients as sent (see CustomClient::validate()), null to leave it
     * @return bool whether anything changed (and the revision advanced)
     * @throws \InvalidArgumentException for an invalid revision or an invalid list; nothing is written
     * @throws PolicyConflict when the page is outdated; nothing is written
     * @throws PolicyUnavailable when the storage fails or the outcome is uncertain; nothing is withdrawn halfway
     */
    public function update(string $expectedRevision, ?array $hosts, ?bool $native, mixed $custom): bool {
        $result = $this->writer->runAdmin($expectedRevision, [], static fn (): null => null,
            function ($snapshot, PolicyAttempt $attempt) use ($hosts, $native, $custom): bool {
                // Everything is validated, against the list read here, before anything is written.
                $before = $custom === null ? [] : $this->read(fn (): array => CustomClient::clients($this->clients));
                $next = $custom === null ? null : CustomClient::validate($custom, $before);
                $changed = false;
                if ($hosts !== null) {
                    $changed = $this->writeHosts($hosts) || $changed;
                }
                if ($native !== null) {
                    $changed = $this->writeNative($native) || $changed;
                }
                if ($next !== null) {
                    $changed = $this->writeCustom($before, $next) || $changed;
                }
                return $changed;
            });
        return $result['changed'];
    }

    /**
     * @return list<array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen:string}> the stored
     *     self-hosted clients, from the app's table
     * @throws PolicyUnavailable when the table cannot be read or a row does not validate
     */
    public function stored(): array {
        return $this->read(fn (): array => CustomClient::clients($this->clients));
    }

    /**
     * @return bool whether the native client is on, from the app's table
     * @throws PolicyUnavailable when the table cannot be read
     */
    public function nativeEnabled(): bool {
        return $this->read(fn (): bool => ClientResolver::nativeEnabled($this->clients));
    }

    /**
     * @template T
     * @param \Closure(): T $read
     * @return T
     * @throws PolicyUnavailable for a row that does not validate or a failed query; nothing is decided on a guess
     */
    private function read(\Closure $read): mixed {
        try {
            return $read();
        } catch (\UnexpectedValueException|\OCP\DB\Exception $e) {
            throw new PolicyUnavailable('Cannot read the OAuth clients', previous: $e);
        }
    }

    /** @param list<string> $hosts */
    private function writeHosts(array $hosts): bool {
        $default = explode(',', ClientMetadataFetcher::DEFAULT_HOSTS);
        $isDefault = count($hosts) === count($default) && array_diff($hosts, $default) === [];
        $stored = $this->config->getAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY, '');
        $value = $isDefault ? '' : implode(',', $hosts);
        if ($stored === $value) {
            return false;
        }
        if ($isDefault) {
            // The default is not frozen into the config, so a later release can change it.
            $this->config->deleteAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY);
        } else {
            $this->config->setAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY, $value);
        }
        return true;
    }

    private function writeNative(bool $native): bool {
        $row = $this->read(fn (): ?array => $this->clients->find(NativeClient::CLIENT_ID));
        if (($row['enabled'] ?? false) === $native) {
            return false;
        }
        $gen = $row['gen'] ?? '1';
        if (!$native) {
            // A consent page of the native client opened before this is never admitted again (its generation is older).
            try {
                $gen = \OCA\Mcp\Access\Policy\DecimalCounter::increment($gen);
            } catch (\InvalidArgumentException $e) {
                throw new PolicyUnavailable('OAuth client generation exhausted', previous: $e);
            }
        }
        $this->clients->put(['clientId' => NativeClient::CLIENT_ID, 'kind' => OAuthClientsMapper::KIND_NATIVE, 'name' => NativeClient::NAME,
            'enabled' => $native, 'redirectUris' => [], 'gen' => $gen, 'position' => 0]);
        if (!$native) {
            $this->oauth->deleteForClient(NativeClient::CLIENT_ID);
        }
        return true;
    }

    /**
     * @param list<array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen:string}> $before the stored list
     * @param list<array{id:string, name:string, enabled:bool, redirectUris:list<string>}> $next the validated list
     */
    private function writeCustom(array $before, array $next): bool {
        $withdrawn = CustomClient::withdrawn($before, $next);
        $stored = CustomClient::stamped($next, $before, $withdrawn);
        if ($stored === $before) {
            return false;
        }
        $kept = array_column($stored, 'id');
        foreach ($before as $client) {
            if (!in_array($client['id'], $kept, true)) {
                $this->clients->delete(CustomClient::clientId($client['id']));
            }
        }
        foreach ($stored as $position => $client) {
            $this->clients->put(['clientId' => CustomClient::clientId($client['id']), 'kind' => OAuthClientsMapper::KIND_CUSTOM,
                'name' => $client['name'], 'enabled' => $client['enabled'], 'redirectUris' => $client['redirectUris'],
                'gen' => $client['gen'], 'position' => $position]);
        }
        foreach ($withdrawn as $clientId) {
            $this->oauth->deleteForClient($clientId);
        }
        return true;
    }
}
