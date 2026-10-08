<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Checkout;

/**
 * One row of mcp_checkout_tokens: a single-use capability to download or to replace the content of one
 * file of one user, bound to the ETag the file had when the checkout was issued, or to create one new file
 * at a path that must still be free. The token itself is never part of this object; only its keyed hash was
 * stored.
 */
final class CheckoutToken {
    /** Token may be spent once on the download route. */
    public const KIND_DOWNLOAD = 'download';
    /** Token may be spent once on the upload route. */
    public const KIND_UPLOAD = 'upload';
    /** Token may be spent once on the upload route to create a new file at its path, never over an existing one. */
    public const KIND_CREATE = 'create';
    /**
     * What a create token stores as ETag when files_upload declared no size: the file does not exist yet, so the
     * column, which is NOT NULL, holds the declared size in decimal or this marker. Keeping it in an existing column
     * spares a migration of a release that may already be installed.
     */
    public const NO_ETAG = '-';

    /**
     * @param int $id row id, the handle every conditional update uses
     * @param string $kind KIND_DOWNLOAD, KIND_UPLOAD or KIND_CREATE
     * @param string $userId owner of the checkout; the upload always runs in this user's folder
     * @param int $fileId numeric id of the file at checkout time, 0 for a create token
     * @param string $path normalized user-relative path of that file, or of the file to create
     * @param string $etag ETag the file had at checkout time; for a create token, {@see self::declaredSizeMarker()}
     * @param string $scope ownership scope the file (or, for a create token, its folder) was in at checkout time
     * @param bool $sharedConfirmed whether the user confirmed the shared write when checking out
     * @param int $expiresAt unix time after which the token is worthless
     * @param bool $used whether a previous request already spent it
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $userId,
        public readonly int $fileId,
        public readonly string $path,
        public readonly string $etag,
        public readonly string $scope,
        public readonly bool $sharedConfirmed,
        public readonly int $expiresAt,
        public readonly bool $used,
    ) {}

    /**
     * @param int|null $size size files_upload declared for the new file, null when it declared none
     * @return string what the etag column of a create token holds for it
     */
    public static function declaredSizeMarker(?int $size): string {
        return $size === null ? self::NO_ETAG : (string)$size;
    }

    /** @return int|null the size declared for a create token, null for another kind or when none was declared */
    public function declaredSize(): ?int {
        return $this->kind === self::KIND_CREATE && ctype_digit($this->etag) ? (int)$this->etag : null;
    }

    /**
     * @param array<string, mixed> $row raw database row
     * @return self the row as an immutable value
     */
    public static function fromRow(array $row): self {
        return new self(
            (int)$row['id'],
            (string)$row['kind'],
            (string)$row['user_id'],
            (int)$row['file_id'],
            (string)$row['path'],
            (string)$row['etag'],
            (string)$row['scope'],
            (int)($row['shared_confirmed'] ?? 0) === 1,
            (int)$row['expires_at'],
            $row['used_at'] !== null,
        );
    }
}
