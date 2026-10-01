<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchOrder;

/**
 * Ordering criterion for file search queries (e.g. mtime desc).
 */
final class SearchOrder implements ISearchOrder {
    public function __construct(
        private string $field,
        private string $direction = self::DIRECTION_DESCENDING,
        private string $extra = '',
    ) {}

    /** {@inheritDoc} */
    public function getDirection(): string {
        return $this->direction;
    }

    /** {@inheritDoc} */
    public function getField(): string {
        return $this->field;
    }

    /** {@inheritDoc} */
    public function getExtra(): string {
        return $this->extra;
    }

    /** {@inheritDoc} */
    public function sortFileInfo(FileInfo $a, FileInfo $b): int {
        if ($this->field === 'mtime') {
            $cmp = $a->getMTime() <=> $b->getMTime();
            return $this->direction === self::DIRECTION_DESCENDING ? -$cmp : $cmp;
        }
        if ($this->field === 'name') {
            $cmp = strcasecmp($a->getName(), $b->getName());
            return $this->direction === self::DIRECTION_DESCENDING ? -$cmp : $cmp;
        }
        return 0;
    }
}
