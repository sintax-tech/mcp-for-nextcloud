<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Discovery;

use OCA\Mcp\Modules\Tables\Reading\TablesPage;
use PHPUnit\Framework\TestCase;

/** A page says how many there are, whether more remain and where the next page starts. */
final class TablesPageTest extends TestCase {
	/**
	 * First, middle, last and past-the-end pages.
	 *
	 * @return void
	 */
	public function testPages(): void {
		$items = [1, 2, 3, 4, 5];
		$this->assertSame(['items' => [1, 2], 'total' => 5, 'hasMore' => true, 'nextOffset' => 2], TablesPage::of($items, 2, 0));
		$this->assertSame(['items' => [3, 4], 'total' => 5, 'hasMore' => true, 'nextOffset' => 4], TablesPage::of($items, 2, 2));
		$this->assertSame(['items' => [5], 'total' => 5, 'hasMore' => false, 'nextOffset' => null], TablesPage::of($items, 2, 4));
		$this->assertSame(['items' => [], 'total' => 5, 'hasMore' => false, 'nextOffset' => null], TablesPage::of($items, 2, 9));
		$this->assertSame(['items' => [], 'total' => 0, 'hasMore' => false, 'nextOffset' => null], TablesPage::of([], 50, 0));
	}
}
