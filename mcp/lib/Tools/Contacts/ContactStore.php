<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

/** Read-only CardDAV backend port. All writes must use ContactDav. */
interface ContactStore {
    /** @return list<array{id:int,uri:string,displayName:string,ownerPrincipal:string,readOnly:bool}> */
    public function books(string $principal): array;
    /** @return list<array{uri:string,etag:string,data:string}> */
    public function cards(int $bookId): array;
    /** @return array{uri:string,etag:string,data:string}|null */
    public function card(int $bookId, string $uri): ?array;
}
