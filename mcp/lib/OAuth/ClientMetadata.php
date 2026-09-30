<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/** A validated Client ID Metadata Document: the client_id URL, its self-asserted name and its redirect URIs. */
class ClientMetadata {
    /**
     * @param string $clientId the HTTPS URL that is both the client_id and the document location
     * @param string $name client_name from the document (self-asserted; show it next to the host)
     * @param string[] $redirectUris registered redirect URIs
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly array $redirectUris,
    ) {}

    /** @return string host of the client_id URL, the trustworthy identity to show on the consent screen */
    public function host(): string {
        return (string)parse_url($this->clientId, PHP_URL_HOST);
    }
}
