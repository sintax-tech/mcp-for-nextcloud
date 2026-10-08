<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\MimeLikeComparison;
use OCA\Mcp\Tools\Files\NameLikeComparison;
use OCA\Mcp\Tools\Files\SearchBinaryOperator;
use OCA\Mcp\Tools\Files\SearchComparison;
use OCP\Files\Search\ISearchComparison;
use PHPUnit\Framework\TestCase;

/**
 * Core (OC\Files\Search\SearchBinaryOperator::__toString) does implode(' and ', $arguments), so every operator we
 * hand it must be convertible to string (files_image_search broke with 1.0.1 on NC 33).
 */
final class SearchOperatorsStringableTest extends TestCase {
    public function testSearchComparisonUsesCoreFormat(): void {
        $c = new SearchComparison('mtime', ISearchComparison::COMPARE_GREATER_THAN_EQUAL, 10);
        $this->assertSame('mtime gte 10', (string)$c);
    }

    public function testMimeLikeComparisonIsStringable(): void {
        $this->assertSame('mimetype like "image\\/%"', (string)new MimeLikeComparison());
    }

    public function testNameLikeComparisonIsStringable(): void {
        $this->assertSame('name like "%a%"', (string)new NameLikeComparison('%a%'));
    }

    public function testBinaryOperatorImplodedLikeCore(): void {
        $op = SearchBinaryOperator::and(
            new MimeLikeComparison(),
            new SearchComparison('name', ISearchComparison::COMPARE_LIKE, '%x%'),
            new SearchComparison('mtime', ISearchComparison::COMPARE_LESS_THAN_EQUAL, 5),
        );
        $text = implode(' and ', [$op]);
        $this->assertSame('((mimetype like "image\\/%" and name like "%x%") and mtime lte 5)', $text);
    }
}
