<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\Tools\Calendar\DavResult;

/** Native CardDAV writes. Deletion is permanent in core; callers must verify a backup first. */
interface ContactDav {
    public function put(string $userId, string $bookUri, string $uri, string $vcard): DavResult;
    public function update(string $userId, string $bookUri, string $uri, string $etag, string $vcard): DavResult;
    public function delete(string $userId, string $bookUri, string $uri, string $etag): DavResult;
}
