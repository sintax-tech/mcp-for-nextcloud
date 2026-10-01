<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\DAV\CardDAV\CardDavBackend;
use Psr\Container\ContainerInterface;

/** Resolves the Nextcloud 33 CardDAV backend only when a contact tool is used. */
class DavContactStore implements ContactStore {
    public function __construct(private ContainerInterface $container) {}
    private function backend(): CardDavBackend { return $this->container->get(CardDavBackend::class); }
    public function books(string $principal): array {
        return array_values(array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'], 'uri'=>(string)$row['uri'],
            'displayName'=>(string)($row['{DAV:}displayname'] ?? $row['uri']),
            'ownerPrincipal'=>(string)($row['{http://owncloud.org/ns}owner-principal'] ?? $row['principaluri']),
            'readOnly'=>(bool)($row['{http://owncloud.org/ns}read-only'] ?? false),
        ], $this->backend()->getAddressBooksForUser($principal)));
    }
    public function cards(int $bookId): array { return array_values(array_map([$this,'row'], $this->backend()->getCards($bookId))); }
    public function card(int $bookId, string $uri): ?array {
        $row=$this->backend()->getCard($bookId,$uri);
        return $row ? $this->row($row) : null;
    }
    private function row(array $row): array { return ['uri'=>(string)$row['uri'],'etag'=>(string)$row['etag'],'data'=>(string)$row['carddata']]; }
}
