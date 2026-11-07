<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

/** An MCP client: the credential is sent on every call and nothing is kept between calls, as a stateless client does. */
final class McpClient {
	/** Tools whose schema has `confirm_shared`: a write outside the personal folder asks for it before it acts. */
	private const SHARED_CONFIRMATION = ['files_mkdir', 'files_copy', 'files_move', 'files_move_batch', 'files_edit', 'files_replace',
		'files_checkout', 'files_version_restore', 'files_upload', 'files_create', 'notes_create', 'notes_edit', 'notes_move', 'notes_delete'];

	private int $id = 0;

	public function __construct(private readonly Credential $credential) {
	}

	public function uid(): string {
		return $this->credential->uid;
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	public function tool(string $name, array $arguments = []): ToolReply {
		$message = ['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => 'tools/call',
			'params' => ['name' => $name, 'arguments' => (object)$arguments]];
		$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
			'Authorization: ' . $this->credential->authorization(),
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
		], json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		$decoded = json_decode($response['body'], true);
		return new ToolReply($response['status'], is_array($decoded) ? $decoded : [], $response['body']);
	}

	/**
	 * A write the way a client confirms it: the same call again with confirm: true (no plan step in between).
	 *
	 * @param array<string, mixed> $arguments
	 */
	public function write(string $name, array $arguments = []): ToolReply {
		$extra = ['confirm' => true];
		if (in_array($name, self::SHARED_CONFIRMATION, true)) {
			$extra['confirm_shared'] = true;
		}
		return $this->tool($name, $arguments + $extra);
	}

	/** @return int HTTP status of tools/list with this credential (200 while the login is valid) */
	public function listStatus(): int {
		return Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
			'Authorization: ' . $this->credential->authorization(),
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
		], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR))['status'];
	}
}
