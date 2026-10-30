<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\Polls\PollsRefusal;
use PHPUnit\Framework\Attributes\{RunInSeparateProcess,PreserveGlobalState};
use PHPUnit\Framework\TestCase;

/** Native console grants are never accepted by the authenticated MCP adapter. */
final class PollsConsoleTest extends TestCase {
	/** @return void a separate PHP process cannot leak the god-mode constant into ordinary unit tests */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testConsoleContextIsRefusedBeforeNativeResolution(): void {
		$rig=new PollsTestRig($this);
		define('OC_CONSOLE',true);
		$this->expectException(PollsRefusal::class);
		try {$rig->call();} finally {$this->assertSame(0,NativeScene::session()->cleans);}
	}
}
