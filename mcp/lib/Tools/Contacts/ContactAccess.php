<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;

/** Uses the Calendar collection descriptor for the identical ownership/shared guard metadata. */
class ContactAccess {
    /** Only user principals may own visible address books. */
    private const PRINCIPAL_PREFIX = 'principals/users/';

    /** Base of the address-book paths exposed by the tools. */
    private const PATH_PREFIX = '/remote.php/dav/addressbooks/users/';

    /** Directory name the core uses for the system address book inside every user's home. */
    public const SYSTEM_URI = 'z-server-generated--system';

    /**
     * Receives the read-only port used to resolve visible address books.
     *
     * @param ContactStore $store read-only storage port
     * @return void
     */
    public function __construct(private ContactStore $store) {}

    /**
     * DAV path under which the tools expose the system catalog ("Accounts") to one user.
     *
     * @param string $userId authenticated user UID
     * @return string
     */
    public static function systemPath(string $userId): string {
        return self::PATH_PREFIX . rawurlencode($userId) . '/' . self::SYSTEM_URI . '/';
    }

    /**
     * Whether a tool path names the read-only system catalog.
     *
     * @param string $userId authenticated user UID
     * @param string $path address-book path given to a tool
     * @return bool
     */
    public function isSystemPath(string $userId, string $path): bool {
        return rtrim($path, '/') === rtrim(self::systemPath($userId), '/');
    }

    /**
     * Lists personal and shared address books, explicitly excluding system principals.
     *
     * @param string $userId authenticated user UID
     * @return list<Calendar>
     */
    public function visible(string $userId): array {
        $addressbooks = [];
        foreach ($this->store->books(self::PRINCIPAL_PREFIX . $userId) as $row) {
            $owner = $row['ownerPrincipal'];
            if (!str_starts_with($owner, self::PRINCIPAL_PREFIX)) {
                continue;
            }
            $ownerId = substr($owner, strlen(self::PRINCIPAL_PREFIX));
            $addressbooks[] = new Calendar(
                $row['id'],
                $row['uri'],
                $row['displayName'],
                $ownerId,
                $owner,
                !$row['readOnly'],
                self::PATH_PREFIX . rawurlencode($userId) . '/' . rawurlencode($row['uri']) . '/'
            );
        }
        return $addressbooks;
    }

    /**
     * Resolves a visible address-book path and optionally requires write access.
     *
     * @param string $userId authenticated user UID
     * @param string $path address-book DAV path returned by the listing tool
     * @param bool $write whether to require a writable address book
     * @return Calendar
     * @throws ToolFailure when the path is foreign, hidden or not writable
     */
    public function resolve(string $userId, string $path, bool $write = false): Calendar {
        foreach ($this->visible($userId) as $book) {
            if (rtrim($book->path, '/') !== rtrim($path, '/')) {
                continue;
            }
            if ($write && !$book->writable) {
                throw new ToolFailure(CommonMessages::forbidden());
            }
            return $book;
        }
        throw new ToolFailure(CommonMessages::notFound());
    }
}
