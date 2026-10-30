<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;

/** Disposable scene setup through the official OCS client path, never product code or mapper writes. */
final class FormsApiScene {
	/** @var array<string,object> Synthetic in-memory app passwords, never printed or persisted in scene JSON. */
	private array $logins = [];

	/** Calls one Forms OCS route as its real actor.
	 * @param string $uid Synthetic fixture actor.
	 * @param string $method HTTP verb.
	 * @param string $path Native path after api/v3.
	 * @param array<string,mixed> $body Official JSON arguments.
	 * @return mixed Decoded native data.
	 */
	public function api(string $uid, string $method, string $path, array $body = []): mixed {
		$this->logins[$uid] ??= LoginFactory::basic($uid);
		$reply = Http::request($method, Http::BASE . '/ocs/v2.php/apps/forms/api/v3/' . $path,
			['Authorization: ' . $this->logins[$uid]->authorization(), 'Accept: application/json', 'Content-Type: application/json', 'OCS-APIRequest: true'],
			$method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR));
		if (!in_array($reply['status'], [200,201], true)) { throw new \RuntimeException('Forms fixture route failed: '.$method.' '.$path.' status='.$reply['status'].' '.$reply['body']); }
		return json_decode($reply['body'], true, 512, JSON_THROW_ON_ERROR)['ocs']['data'];
	}

	/** Creates a native form and configures only the fields the fixture needs.
	 * @param string $title Synthetic title.
	 * @param array<string,mixed> $fields Native form settings.
	 * @return int Created native id.
	 */
	public function form(string $title, array $fields = []): int {
		$form = $this->api(FormsScene::OWNER, 'POST', 'forms');
		$id = (int)$form['id'];
		$this->api(FormsScene::OWNER, 'PATCH', "forms/$id", ['keyValuePairs' => ['title' => $title] + $fields]);
		return $id;
	}

	/** Creates native user/group/public shares; callers record only public test ids, not credentials in logs.
	 * @param int $formId Native form.
	 * @param int $type Native share type.
	 * @param string $with Synthetic target or empty for generated link.
	 * @param list<string> $permissions Explicit native permissions.
	 * @return array<string,mixed> Native share, kept inside fixture memory/scene.
	 */
	public function share(int $formId, int $type, string $with, array $permissions): array {
		return $this->api(FormsScene::OWNER,'POST',"forms/$formId/shares", ['shareType'=>$type,'shareWith'=>$with,'permissions'=>$permissions]);
	}
}
