<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Checkout;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;

/** CheckoutTokenStore double keeping rows in an array, with the same single-use semantics as the SQL store. */
final class InMemoryCheckoutTokenStore extends CheckoutTokenStore {
    /** @var array<int, array<string, mixed>> rows keyed by id, as the table would hold them */
    public array $rows = [];
    /** @var list<string> operations in order, so a test can assert what ran before what */
    public array $ops = [];
    private int $nextId = 1;

    public function __construct() {}

    /** @param array<string, mixed> $row token without its id */
    public function insert(array $row, int $now): void {
        $this->ops[] = 'insert';
        // The real store purges what expired before it stores the new row.
        $this->rows = array_filter($this->rows, static fn (array $stored): bool => $stored['expires_at'] >= $now);
        $this->rows[$this->nextId] = ['used_at' => null] + $row + ['id' => $this->nextId++];
    }

    public function find(string $tokenHash): ?CheckoutToken {
        $this->ops[] = 'find';
        foreach ($this->rows as $row) {
            if ($row['token_hash'] === $tokenHash) {
                return CheckoutToken::fromRow($row);
            }
        }
        return null;
    }

    public function consume(CheckoutToken $token, int $now): bool {
        $this->ops[] = 'consume';
        $row = $this->rows[$token->id] ?? null;
        if ($row === null || $row['used_at'] !== null || $row['expires_at'] <= $now) {
            return false;
        }
        $this->rows[$token->id]['used_at'] = $now;
        return true;
    }

    public function deleteForUser(string $uid): void {
        $this->ops[] = 'deleteForUser';
        $this->rows = array_filter($this->rows, fn (array $row) => $row['user_id'] !== $uid);
    }

    /**
     * @param string $kind CheckoutToken::KIND_DOWNLOAD or KIND_UPLOAD
     * @return CheckoutToken|null the stored row of that kind, for assertions
     */
    public function ofKind(string $kind): ?CheckoutToken {
        foreach ($this->rows as $row) {
            if ($row['kind'] === $kind) {
                return CheckoutToken::fromRow($row);
            }
        }
        return null;
    }

    /**
     * Changes the ETag the row remembers, standing for an edit made by another client.
     *
     * @param string $etag new ETag
     */
    public function moveEtag(string $etag): void {
        foreach ($this->rows as $id => $row) {
            $this->rows[$id]['etag'] = $etag;
        }
    }
}
