<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use InvalidArgumentException;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchOperator;

/**
 * Binary search operator (AND/OR/NOT) for Nextcloud file searches.
 */
final class SearchBinaryOperator implements ISearchBinaryOperator {
    /** @var array<string, mixed> */
    private array $hints = [];

    /**
     * @param string $type ISearchBinaryOperator::OPERATOR_*
     * @param ISearchOperator[] $arguments
     */
    public function __construct(
        private string $type,
        private array $arguments,
    ) {}

    /** {@inheritDoc} */
    public function getType(): string {
        return $this->type;
    }

    /** @return ISearchOperator[] */
    public function getArguments(): array {
        return $this->arguments;
    }

    /** {@inheritDoc} */
    public function getQueryHint(string $name, $default) {
        return $this->hints[$name] ?? $default;
    }

    /** {@inheritDoc} */
    public function setQueryHint(string $name, $value): void {
        $this->hints[$name] = $value;
    }

    /**
     * Combines multiple operators into a nested binary AND tree.
     */
    public static function and(ISearchOperator ...$operators): ISearchOperator {
        if ($operators === []) {
            throw new InvalidArgumentException('At least one operator required');
        }
        if (count($operators) === 1) {
            return $operators[0];
        }
        $result = array_shift($operators);
        while (!empty($operators)) {
            $next = array_shift($operators);
            $result = new self(self::OPERATOR_AND, [$result, $next]);
        }
        return $result;
    }

    /** Same text core builds; nested operators are implode()d by core, so this must exist. */
    public function __toString(): string {
        if ($this->type === self::OPERATOR_NOT) {
            return 'not ' . $this->arguments[0];
        }
        return '(' . implode(' ' . $this->type . ' ', $this->arguments) . ')';
    }
}
