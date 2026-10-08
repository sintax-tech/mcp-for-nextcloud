<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\DAV\CardDAV\CardDavBackend;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/** Resolves the Nextcloud 33 CardDAV backend only when a contact tool is used. */
class DavContactStore implements ContactStore {
    /**
     * Receives the container without resolving the optional CardDAV backend yet.
     *
     * @param ContainerInterface $container container resolving the core backend lazily
     * @return void
     */
    public function __construct(private ContainerInterface $container) {}

    /**
     * Resolves the native CardDAV backend only when a contact read is requested.
     *
     * @return CardDavBackend
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    private function backend(): CardDavBackend {
        return $this->container->get(CardDavBackend::class);
    }

    /**
     * Lists the address books visible to the authenticated user principal.
     *
     * @param string $principal authenticated user principal URI
     * @return list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool}>
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    public function books(string $principal): array {
        return array_values(
            array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'uri' => (string) $row['uri'],
                    'displayName' => (string) ($row['{DAV:}displayname'] ?? $row['uri']),
                    'ownerPrincipal' => (string) ($row['{http://owncloud.org/ns}owner-principal'] ?? $row['principaluri']),
                    'readOnly' => (bool) ($row['{http://owncloud.org/ns}read-only'] ?? false),
                ],
                $this->backend()->getAddressBooksForUser($principal)
            )
        );
    }

    /**
     * Lists the contacts stored in one authorized address book.
     *
     * @param int $bookId ID of an authorized address book
     * @return list<array{uri:string, etag:string, data:string}>
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    public function cards(int $bookId): array {
        return array_values(array_map([$this, 'row'], $this->backend()->getCards($bookId)));
    }

    /**
     * Reads one contact object, returning null when it no longer exists.
     *
     * @param int $bookId ID of an authorized address book
     * @param string $uri contact object URI within the address book
     * @return array{uri:string, etag:string, data:string}|null
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    public function card(int $bookId, string $uri): ?array {
        $row = $this->backend()->getCard($bookId, $uri);
        return $row ? $this->row($row) : null;
    }

    /**
     * Normalizes a native CardDAV object into the read-only contact port shape.
     *
     * @param array<string, mixed> $row native CardDAV object
     * @return array{uri:string, etag:string, data:string}
     */
    private function row(array $row): array {
        return [
            'uri' => (string) $row['uri'],
            'etag' => (string) $row['etag'],
            'data' => (string) $row['carddata'],
        ];
    }
}
