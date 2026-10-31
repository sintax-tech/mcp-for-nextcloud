<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms\Creation;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Forms\{FormsApiScene,FormsScene,FormsState};
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient};
use OCP\Server;

/** Exercises aggregate creation through real authenticated MCP and installed pinned Forms, not unit doubles. */
final class McpFormsCreationTest extends IntegrationTestCase {
	/** Selects only the official opt-in Forms cell.
	 * @return void
	 */
	protected function setUp(): void { parent::setUp(); if (getenv('MCP_IT_WITH_FORMS') !== '1') { self::markTestSkipped('Requires pinned Forms cell.'); } }
	/** Proves default deny, inert previews and both authenticated creation transports against native readback.
	 * @return void
	 */
	public function testBasicBearerPlanConfirmNativeStructureAndNoExtraPermissions(): void {
		$uid = FormsScene::OWNER; $policy = Server::get(GrantPolicy::class); $api = new FormsApiScene();
		$policy->setGrant($uid, 'forms', 'create', false);
		$before = FormsState::database(); $files = FormsState::files();
		foreach (['basic', 'bearer'] as $kind) {
			$client = new McpClient($kind === 'basic' ? LoginFactory::basic($uid) : LoginFactory::bearer($uid));
			self::assertFalse($client->tool('forms_create_form', ['title' => 'Denied'])->ok());
		}
		self::assertSame($before, FormsState::database()); self::assertSame($files, FormsState::files());
		$policy->setGrant($uid, 'forms', 'create', true);
		$policy->setGrant($uid, 'forms', 'read', false); $policy->setGrant($uid, 'forms', 'results', false);
		try {
			foreach (['basic', 'bearer'] as $kind) {
				$client = new McpClient($kind === 'basic' ? LoginFactory::basic($uid) : LoginFactory::bearer($uid));
				$args = ['title' => 'Registration ' . $kind, 'description' => '<b>Untrusted description</b>', 'questions' => [
					['type' => 'short', 'text' => 'Name', 'required' => true], ['type' => 'short', 'text' => 'Email', 'validation' => 'email'],
					['type' => 'date', 'text' => 'Preferred date'], ['type' => 'dropdown', 'text' => 'Choice', 'options' => ['A', 'A', 'B']],
					['type' => 'grid', 'subtype' => 'checkbox', 'text' => 'Grid', 'rows' => ['Row'], 'columns' => ['Col']],
				]];
				$before = FormsState::database(); $preview = $client->tool('forms_create_form', $args);
				self::assertTrue($preview->ok(), $preview->describe()); $plan = $preview->structured();
				self::assertArrayHasKey('plan_state', $plan);
				$installed = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('forms');
				self::assertStringContainsString($installed, $preview->text());
				self::assertSame($before, FormsState::database()); self::assertSame($files, FormsState::files());
				$changed = $client->tool('forms_create_form', ['title' => 'Changed'] + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
				self::assertTrue($changed->ok(), $changed->describe()); self::assertArrayHasKey('plan_state', $changed->structured());
				self::assertSame($before, FormsState::database());
				$reply = $client->tool('forms_create_form', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
				self::assertTrue($reply->ok(), $reply->describe()); $receipt = $reply->json(); self::assertSame('complete', $receipt['status'], $reply->text());
				$id = $receipt['applied']['formId']; $native = $api->api($uid, 'GET', 'forms/' . $id);
				self::assertSame($args['title'], $native['title']); self::assertSame($args['description'], $native['description']);
				self::assertSame(['permitAllUsers' => false, 'showToAllUsers' => false], $native['access']); self::assertSame(0, $native['state']);
				self::assertCount(5, $native['questions']); self::assertSame(['Name', 'Email', 'Preferred date', 'Choice', 'Grid'], array_column($native['questions'], 'text'));
				self::assertTrue($native['questions'][0]['isRequired']); self::assertSame('email', $native['questions'][1]['extraSettings']['validationType']);
				self::assertSame(['A', 'A', 'B'], array_column($native['questions'][3]['options'], 'text'));
				self::assertSame(['row', 'column'], array_column($native['questions'][4]['options'], 'optionType'));
				self::assertStringNotContainsString('"ownerId"', $reply->text()); self::assertStringNotContainsString('"hash"', $reply->text());
				self::assertStringContainsString('/edit', $receipt['applied']['editorUrl']);
				self::assertFalse($policy->granted($uid, 'forms', 'results')); self::assertFalse($policy->granted($uid, 'forms', 'edit'));
				self::assertSame($files, FormsState::files(), 'Normal creation must not create an upload directory');
			}
		} finally {
			$policy->setGrant($uid, 'forms', 'create', false); $policy->setGrant($uid, 'forms', 'read', true); $policy->setGrant($uid, 'forms', 'results', true);
		}
	}
	/** Proves Forms-scoped configuration admission instead of an accidentally MCP-scoped value.
	 * @return void
	 */
	public function testNativeRestrictCreationAndScopedConfigDenyBeforeS0(): void {
		$uid = FormsScene::OWNER; $policy = Server::get(GrantPolicy::class); $policy->setGrant($uid, 'forms', 'create', true);
		$app = Server::get(\OCP\IAppConfig::class); $saved = [];
		foreach (['forms', 'mcp'] as $name) { foreach (['restrictCreation', 'creationAllowedGroups'] as $key) { $saved[$name][$key] = ['had' => $app->hasKey($name, $key), 'value' => $key === 'restrictCreation' ? $app->getValueBool($name, $key, false) : $app->getValueArray($name, $key, [])]; } }
		try {
			$app->setValueBool('forms', 'restrictCreation', true); $app->setValueArray('forms', 'creationAllowedGroups', []);
			$app->setValueBool('mcp', 'restrictCreation', false); $app->setValueArray('mcp', 'creationAllowedGroups', []);
			$before = FormsState::database();
			foreach (['basic', 'bearer'] as $kind) {
				$client = new McpClient($kind === 'basic' ? LoginFactory::basic($uid) : LoginFactory::bearer($uid));
				self::assertTrue($client->tool('forms_create_form', ['title' => 'Must refuse'])->isError());
			}
			self::assertSame($before, FormsState::database());
			foreach (['basic', 'bearer'] as $kind) {
				$app->setValueBool('forms', 'restrictCreation', false);
				$client = new McpClient($kind === 'basic' ? LoginFactory::basic($uid) : LoginFactory::bearer($uid));
				$args = ['title' => 'Configuration changes after preview'];
				$preview = $client->tool('forms_create_form', $args); self::assertTrue($preview->ok(), $preview->describe());
				$app->setValueBool('forms', 'restrictCreation', true);
				$refused = $client->tool('forms_create_form', $args + ['confirm' => true, 'plan_state' => $preview->structured()['plan_state']]);
				self::assertTrue($refused->isError(), $refused->describe()); self::assertSame($before, FormsState::database());
			}
		} finally {
			foreach ($saved as $name => $keys) { foreach ($keys as $key => $prior) {
				if (!$prior['had']) { $app->deleteKey($name, $key); }
				elseif ($key === 'restrictCreation') { $app->setValueBool($name, $key, $prior['value']); }
				else { $app->setValueArray($name, $key, $prior['value']); }
			} }
			$policy->setGrant($uid, 'forms', 'create', false);
		}
	}
	/** Runs native cold/warm/captured-null/other/lazy graphs in isolated PHP processes, using only disposable actors.
	 * @return void
	 */
	public function testFreshNativeCapturedActorsAndConfigGraphs(): void {
		$policy = Server::get(GrantPolicy::class); $policy->setGrant(FormsScene::READER, 'forms', 'create', true);
		try {
			$cases = ['cold', 'warm', 'controller-null', 'controller-other', 'config-null', 'config-other', 'forms-null', 'forms-other', 'collision', 'external-revoke', 'title-race', 'native-edit'];
			if (PHP_VERSION_ID >= 80400 && property_exists(\OC\AppFramework\Utility\SimpleContainer::class, 'useLazyObjects')) { $cases[] = 'lazy'; }
			foreach ($cases as $case) {
				$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/ci/forms/creation-context.php') . ' ' . escapeshellarg($case) . ' 2>&1';
				$lines = []; exec($command, $lines, $exit);
				self::assertSame(0, $exit, implode("\n", $lines)); $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
				self::assertSame(!str_starts_with($case, 'forms-'), $result['accepted']);
				file_put_contents('/tmp/mcp-forms-creation-context.jsonl', json_encode($result, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
			}
		} finally { $policy->setGrant(FormsScene::READER, 'forms', 'create', false); }
	}
}
