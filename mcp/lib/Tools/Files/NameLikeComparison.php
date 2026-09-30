<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\Files\Search\ISearchComparison;

/**
 * Case-insensitive LIKE on the file name, the same comparison Folder::search(string) builds.
 * OCP only ships the interface; core's SearchComparison is private API.
 */
final class NameLikeComparison implements ISearchComparison {
    /** @var array<string, mixed> */
    private array $hints = [];

    /** @param string $pattern LIKE pattern, already escaped with IDBConnection::escapeLikeParameter */
    public function __construct(private string $pattern) {}

    /** @return string ISearchComparison::COMPARE_LIKE */
    public function getType(): string {
        return ISearchComparison::COMPARE_LIKE;
    }

    /** @return string the file cache field 'name' */
    public function getField(): string {
        return 'name';
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
