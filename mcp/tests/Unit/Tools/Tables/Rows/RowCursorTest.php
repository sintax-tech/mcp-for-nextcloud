<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Rows;

use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\Tables\Rows\RowCursor;
use OCA\Mcp\Tools\Tables\Rows\RowsRefusal;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The cursor round-trips its offset; changing only the offset, the format, the user, the query or the state, keeping the
 * MAC, is refused; malformed, negative, too large or too long cursors are refused; no cursor is made that would be refused.
 */
final class RowCursorTest extends TestCase {
	/** What a test cursor binds. */
	private const BOUND = ['tables' => '2.3.2', 'target' => ['view', 31], 'table' => 1, 'limit' => 25, 'query' => [null, null, []], 'state' => 'digest'];

	/**
	 * The offset comes back.
	 *
	 * @return void
	 */
	public function testRoundTrip(): void {
		$cursor = $this->cursor();
		$this->assertSame(0, $cursor->offset(null));
		$this->assertSame(25, $cursor->offset($cursor->encode(25)));
		$this->assertStringStartsWith('v1.', $cursor->encode(25));
	}

	/**
	 * The largest offset is made and read back; one past it, or a negative one, is never made.
	 *
	 * @return void
	 */
	public function testTheBoundaryIsTheSameBothWays(): void {
		$cursor = $this->cursor();
		$this->assertSame(RowCursor::MAX_OFFSET, $cursor->offset($cursor->encode(RowCursor::MAX_OFFSET)));
		foreach ([RowCursor::MAX_OFFSET + 1, -1] as $offset) {
			try {
				$cursor->encode($offset);
				$this->fail("made a cursor for $offset");
			} catch (\LogicException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Only the offset changed, within its range, with the old MAC: refused, as a change of format, user, query or state.
	 *
	 * @return void
	 */
	public function testTamperingIsRefused(): void {
		$token = $this->cursor()->encode(25);
		[$format, , $mac] = explode('.', $token);
		$moved = $format . '.' . rtrim(strtr(base64_encode('{"o":50}'), '+/', '-_'), '=') . '.' . $mac;
		$refused = [
			[$this->cursor(), $moved],
			[$this->cursor(), 'v2.' . substr($token, 3)],
			[$this->cursor('bob'), $token],
			[$this->cursor('alice', ['query' => [[[['column_id' => 11]]], null, []]]), $token],
			[$this->cursor('alice', ['state' => 'other digest']), $token],
			[$this->cursor(), 'v1.' . rtrim(strtr(base64_encode('{"o":-1}'), '+/', '-_'), '=') . '.' . $mac],
			[$this->cursor(), 'v1.' . rtrim(strtr(base64_encode('{"o":100001}'), '+/', '-_'), '=') . '.' . $mac],
			[$this->cursor(), 'v1.' . rtrim(strtr(base64_encode('{"o":25,"x":1}'), '+/', '-_'), '=') . '.' . $mac],
			[$this->cursor(), 'not a cursor'],
			[$this->cursor(), $token . str_repeat('a', 600)],
		];
		foreach ($refused as $i => [$cursor, $given]) {
			try {
				$cursor->offset($given);
				$this->fail("cursor case $i accepted");
			} catch (RowsRefusal) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * A cursor for a user and bound fields.
	 *
	 * @param string $user the user
	 * @param array<string, mixed> $change fields that differ from the default binding
	 * @return RowCursor the cursor
	 */
	private function cursor(string $user = 'alice', array $change = []): RowCursor {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		return new RowCursor(new PlanState($config), 'tables_list_rows', $user, RowCursor::MAX_OFFSET, $change + self::BOUND);
	}
}
