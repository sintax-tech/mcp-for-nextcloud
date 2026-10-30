<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Tests\Integration\Support\Http;

/**
 * The tools/list of a fixture user through the authenticated HTTP endpoint, for the Mail HTTP tests built on
 * ExclusionTestCase (which provides login()).
 */
trait MailEndpointTools {
	/**
	 * The tools/list of alice through the endpoint.
	 *
	 * @param string $kind basic or bearer
	 * @return list<array<string, mixed>> the listed tools
	 */
	private function toolsList(string $kind): array {
		$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
			'Authorization: ' . $this->login(self::ALICE, $kind)->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
		], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
		self::assertSame(200, $response['status']);
		return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['result']['tools'];
	}
}
