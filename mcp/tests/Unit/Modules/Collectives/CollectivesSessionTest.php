<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every native read of the page tools (C01-C03) that opens a Teams session inside Collectives and fails before its
 * own `stopSession` leaves no current user and no bypass behind: {@see \OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway}
 * runs each one in `TeamsSession::closingAfter()`.
 */
final class CollectivesSessionTest extends TestCase {
	/** @return iterable<string, array{0:string, 1:string, 2:array<string, mixed>, 3:bool}> read that fails, tool, arguments, whether a plan */
	public static function reads(): iterable {
		yield 'listing collectives' => ['collectives', 'collectives_list', [], false];
		yield 'reading the collective' => ['collective', 'collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'New'], true];
		yield 'the edit check' => ['verify', 'collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'New'], true];
		yield 'the collective folder' => ['folder', 'collectives_pages', ['collective_id' => 7], false];
		yield 'one page' => ['find', 'collectives_pages', ['collective_id' => 7], false];
		yield 'the page file' => ['pageFile', 'collectives_read', ['collective_id' => 7, 'page_id' => -1], false];
		yield 'the content search' => ['search', 'collectives_search', ['collective_id' => 7, 'query' => 'Guide', 'scope' => 'content'], false];
	}

	/**
	 * @param string $read the native read that fails inside its session
	 * @param string $tool the tool that reaches it
	 * @param array<string, mixed> $arguments its arguments
	 * @param bool $plan whether the call is a plan
	 * @return void the read was reached, and the session is clean right after the call
	 */
	#[DataProvider('reads')]
	public function testAReadThatFailsInsideItsSessionLeavesItClosed(string $read, string $tool, array $arguments, bool $plan): void {
		$rig = new CollectivesRig($this);
		$module = $rig->module();
		if ($read === 'pageFile') {
			$arguments['page_id'] = CollectivesScene::$ids['Guide.md'];
		}
		TeamsScene::$failReads = [$read];
		try {
			$plan ? $module->preview($tool, $arguments, 'alice') : $module->call($tool, $arguments, 'alice');
		} catch (ToolFailure) {
			// The refusal is the module's answer to the native failure; what matters is the session after it.
		}
		$this->assertContains('failed:' . $read, TeamsScene::$calls, "the $read read was reached");
		$this->assertNull(TeamsScene::$currentUser, "$read: no Teams user is left in the request");
		$this->assertFalse(TeamsScene::$bypass, "$read: no bypass is left in the request");
	}
}
