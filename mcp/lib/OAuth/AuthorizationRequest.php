<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/** A validated authorization request, kept in the session between the consent page and the consent POST. */
class AuthorizationRequest {
    public function __construct(
        public readonly ClientMetadata $client,
        public readonly string $redirectUri,
        public readonly string $state,
        public readonly string $codeChallenge,
        public readonly string $resource,
        public readonly string $scope,
    ) {}

    /** @return array<string,mixed> session-safe representation */
    public function toArray(): array {
        return [
            'client_id' => $this->client->clientId,
            'client_name' => $this->client->name,
            'redirect_uris' => $this->client->redirectUris,
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'resource' => $this->resource,
            'scope' => $this->scope,
        ];
    }

    /**
     * @param array<string,mixed> $data output of toArray()
     * @return self the restored request
     */
    public static function fromArray(array $data): self {
        return new self(
            new ClientMetadata((string)$data['client_id'], (string)$data['client_name'], (array)$data['redirect_uris']),
            (string)$data['redirect_uri'],
            (string)$data['state'],
            (string)$data['code_challenge'],
            (string)$data['resource'],
            (string)$data['scope'],
        );
    }

    /**
     * @param array<string,string> $params code or error parameters
     * @param string $issuer authorization server issuer, appended as `iss` (RFC 9207)
     * @return string redirect_uri with the parameters, `iss` and the original state appended
     */
    public function redirectWith(array $params, string $issuer): string {
        return self::buildRedirect($this->redirectUri, $params, $this->state, $issuer);
    }

    /**
     * The single place that builds a redirect back to a client: appends `iss` exactly once, then `state` if any.
     *
     * @param string $redirectUri verified redirect_uri (may already carry a query)
     * @param array<string,string> $params code or error parameters
     * @param string $state client state, preserved when not empty
     * @param string $issuer authorization server issuer
     * @return string the redirect URL
     */
    public static function buildRedirect(string $redirectUri, array $params, string $state, string $issuer): string {
        $params['iss'] = $issuer;
        if ($state !== '') {
            $params['state'] = $state;
        }
        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params);
    }
}
