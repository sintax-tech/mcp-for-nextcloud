<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/** File search with limit, offset and ordering pushed into the file cache query, so large trees are not fully loaded. */
final class NameSearchQuery implements ISearchQuery {
    /**
     * @param ISearchOperator $operation search condition
     * @param int $limit maximum rows fetched by the file cache query
     * @param IUser|null $user user the search runs for
     * @param ISearchOrder[] $order ordering criteria
     */
    public function __construct(
        private ISearchOperator $operation,
        private int $limit,
        private ?IUser $user,
        private array $order = [],
        private int $offset = 0,
    ) {}

    /** @return ISearchOperator */
    public function getSearchOperation() {
        return $this->operation;
    }

    /** @return int */
    public function getLimit() {
        return $this->limit;
    }

    /** @return int always 0: a single page is fetched */
    public function getOffset(): int {
        return $this->offset;
    }

    /** @return ISearchOrder[] */
    public function getOrder() {
        return $this->order;
    }

    /** @return IUser|null */
    public function getUser() {
        return $this->user;
    }

    /** @return bool false: shares and external mounts visible to the user are included */
    public function limitToHome(): bool {
        return false;
    }

    /** @return array{} all default fields */
    public function getSelectFields(): array {
        return [];
    }
}
