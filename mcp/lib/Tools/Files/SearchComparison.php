<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use DateTime;
use OCP\Files\Search\ISearchComparison;

/**
 * Generic search comparison on any file cache column (mimetype, name, mtime, etc.).
 */
class SearchComparison implements ISearchComparison {
    /** @var array<string, mixed> */
    private array $hints = [];

    /**
     * @param string $field file cache column (e.g. 'mimetype', 'name', 'mtime')
     * @param string $type ISearchComparison::COMPARE_*
     * @param string|int|bool|DateTime|array $value target value
     * @param string $extra extra field qualifier
     */
    public function __construct(
        private string $field,
        private string $type,
        private string|int|bool|DateTime|array $value,
        private string $extra = '',
    ) {}

    /** {@inheritDoc} */
    public function getType(): string {
        return $this->type;
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
    public function getValue(): string|int|bool|DateTime|array {
        return $this->value;
    }

    /** {@inheritDoc} */
    public function getQueryHint(string $name, $default) {
        return $this->hints[$name] ?? $default;
    }

    /** {@inheritDoc} */
    public function setQueryHint(string $name, $value): void {
        $this->hints[$name] = $value;
    }
}
