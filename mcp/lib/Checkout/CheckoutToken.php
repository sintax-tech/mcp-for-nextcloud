<?php
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
    /** What a create token stores as ETag and file id do not exist yet: a non-empty marker, since the column is NOT NULL. */
    public const NO_ETAG = '-';

    /**
     * @param int $id row id, the handle every conditional update uses
     * @param string $kind KIND_DOWNLOAD, KIND_UPLOAD or KIND_CREATE
     * @param string $userId owner of the checkout; the upload always runs in this user's folder
     * @param int $fileId numeric id of the file at checkout time, 0 for a create token
     * @param string $path normalized user-relative path of that file, or of the file to create
     * @param string $etag ETag the file had at checkout time, NO_ETAG for a create token
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
