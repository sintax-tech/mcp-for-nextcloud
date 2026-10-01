<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\ToolFailure;
use RuntimeException;

/** Native CardDAV writes. Deletion is permanent in core; callers must verify a backup first. */
interface ContactDav {
    /**
     * Creates a contact through native CardDAV without overwriting an existing object.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $vcard complete serialized vCard
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function put(string $userId, string $bookUri, string $uri, string $vcard): DavResult;

    /**
     * Updates a contact through native CardDAV using its current ETag.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $etag current object ETag required by If-Match
     * @param string $vcard complete serialized vCard
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function update(string $userId, string $bookUri, string $uri, string $etag, string $vcard): DavResult;

    /**
     * Permanently deletes a contact through native CardDAV after the caller verifies its backup.
     *
     * @param string $userId authenticated user UID
     * @param string $bookUri address-book URI in the acting user home
     * @param string $uri contact object URI within the address book
     * @param string $etag current object ETag required by If-Match
     * @return DavResult
     * @throws ToolFailure when native DAV rejects the path, ACL, data or precondition
     * @throws RuntimeException when the authenticated session or native response is unexpected
     */
    public function delete(string $userId, string $bookUri, string $uri, string $etag): DavResult;
}
