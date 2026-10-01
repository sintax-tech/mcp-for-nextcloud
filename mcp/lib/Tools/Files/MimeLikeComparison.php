<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\Files\Search\ISearchComparison;

/**
 * Convenience search comparison for matching file MIME types (e.g. 'image/%').
 */
final class MimeLikeComparison extends SearchComparison {
    /** @param string $pattern LIKE pattern, default 'image/%' */
    public function __construct(string $pattern = 'image/%') {
        parent::__construct('mimetype', ISearchComparison::COMPARE_LIKE, $pattern);
    }
}
