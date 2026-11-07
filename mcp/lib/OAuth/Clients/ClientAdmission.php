<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Clients;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\Authorization\OAuthException;
use Throwable;

/** One local admission decision for consent, code exchange, refresh and Bearer. No remote fetch under the mutex. */
final class ClientAdmission {
	/** @param string $id Client identifier. @return string|null Exact normalized HTTPS host, never a URL substring. */
	public static function host(string $id): ?string {
		$p = parse_url($id);
		return is_array($p) && ($p['scheme'] ?? '') === 'https' && is_string($p['host'] ?? null)
			? strtolower($p['host']) : null;
	}

	/** @param OAuthClientsMapper $clients Official storage. @param string $id CIMD identifier. @return string|null Live generation. */
	public static function generation(OAuthClientsMapper $clients, string $id): ?string {
		try {
			$host = self::host($id);
			$row = $host === null ? null : $clients->cimdHosts()->find($host);
			return $row !== null && $row['enabled'] ? $row['gen'] : null;
		} catch (Throwable $e) {
			throw new PolicyUnavailable('Cannot read CIMD admission', previous: $e);
		}
	}

	/**
	 * @param OAuthClientsMapper $clients Official storage, re-read at every point of use.
	 * @param string $id Client identifier.
	 * @param string|null $generation Consent or credential generation; null only for managed-client historical checks.
	 * @param string|null $redirectUri Redirect of a consent/code, when applicable.
	 * @return bool Whether the client is still admitted in this generation.
	 */
	public static function admits(OAuthClientsMapper $clients, string $id, ?string $generation, ?string $redirectUri = null): bool {
		if ($id === NativeClient::CLIENT_ID) {
			$current = ClientResolver::nativeGeneration($clients);
			return $current !== null && ($generation === null || $generation === $current);
		}
		if (CustomClient::owns($id)) {
			return CustomClient::admits($clients, $id, $generation, $redirectUri);
		}
		$current = self::generation($clients, $id);
		return $current !== null && $generation !== null && $current === $generation;
	}

	/** @param array<string,mixed> $row Stored credential. @return string|null CIMD stamp, managed clients retain their own admission rules. */
	public static function credentialGeneration(array $row): ?string {
		return self::host((string)$row['client_id']) === null ? null : (string)($row['client_gen'] ?? '1');
	}

	/** @param ClientMetadata $client Resolved document. @param OAuthClientsMapper $clients Local state. @return ClientMetadata Stamped identity. */
	public static function metadata(ClientMetadata $client, OAuthClientsMapper $clients): ClientMetadata {
		$generation = self::generation($clients, $client->clientId);
		if ($generation === null) {
			throw new OAuthException('invalid_client', 'Unknown or not allowed client');
		}
		return new ClientMetadata($client->clientId, $client->name, $client->redirectUris, $generation);
	}

	/** @param string $id Identifier. @param string $gen Client generation. @return array<string,string> Only CIMD adds a stored stamp. */
	public static function stamp(string $id, string $gen): array {
		return self::host($id) === null ? [] : ['client_gen' => $gen];
	}
}
