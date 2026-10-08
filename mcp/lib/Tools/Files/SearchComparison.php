<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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

    /** Same text core builds; core implodes operators into a string, so this must exist. */
    public function __toString(): string {
        return $this->field . ' ' . $this->type . ' ' . json_encode($this->value);
    }
}
