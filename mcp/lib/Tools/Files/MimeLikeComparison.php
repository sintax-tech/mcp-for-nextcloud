<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\Files\Search\ISearchComparison;

/**
 * Case-insensitive LIKE on the mimetype field, so Folder::search() returns only images (or any other
 * mimetype family). Mirrors NameLikeComparison, since OCP ships only the interface.
 */
final class MimeLikeComparison implements ISearchComparison {
    /** @var array<string, mixed> */
    private array $hints = [];

    /** @param string $pattern LIKE pattern, e.g. 'image/%' */
    public function __construct(private string $pattern) {}

    /** @return string ISearchComparison::COMPARE_LIKE */
    public function getType(): string {
        return ISearchComparison::COMPARE_LIKE;
    }

    /** @return string the file cache field 'mimetype' */
    public function getField(): string {
        return 'mimetype';
    }

    /** @return string no extra field qualifier */
    public function getExtra(): string {
        return '';
    }

    /** @return string the LIKE pattern */
    public function getValue(): string {
        return $this->pattern;
    }

    /**
     * @param string $name hint name
     * @param mixed $default value when the hint is unset
     * @return mixed
     */
    public function getQueryHint(string $name, $default) {
        return $this->hints[$name] ?? $default;
    }

    /**
     * @param string $name hint name
     * @param mixed $value hint value
     */
    public function setQueryHint(string $name, $value): void {
        $this->hints[$name] = $value;
    }
}
