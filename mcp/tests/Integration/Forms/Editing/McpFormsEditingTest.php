<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Tests\Integration\Support\ServiceSwitchFixture;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Forms\{FormsApiScene, FormsScene, FormsState};
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCP\Server;
/** Exercises point editing through Basic/Bearer MCP against pinned native Forms and real databases. */
final class McpFormsEditingTest extends IntegrationTestCase
{
	/** @var ServiceSwitchFixture|null Per-test service switch, absent when the opt-in skips setup. */
	private ?ServiceSwitchFixture $serviceSwitch = null;

	/** Selects only the official isolated Forms cell.
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		if (getenv('MCP_IT_WITH_FORMS') !== '1') {
			self::markTestSkipped('Requires pinned Forms cell.');
		}

		$this->serviceSwitch = ServiceSwitchFixture::open();
	}

	/** @return void Restore the service switch even when the HTTP test fails. */
	protected function tearDown(): void {
		try {
			$this->serviceSwitch?->restore();
		} finally {
			parent::tearDown();
		}
	}

	/** Proves independent default-denied edit, zero-effect preview, shared confirmation, fidelity and native editor locking.
	 * @return void
	 */
	public function testOwnerEditorBasicBearerNativePointEditsAndLocks(): void
	{
		$encoding = EditEncodingProbe::read();
		file_put_contents('/tmp/mcp-forms-edit-encoding.txt', $encoding . "\n");
		$api = new FormsApiScene();
		$policy = Server::get(GrantPolicy::class);
		try {
			foreach (['basic', 'bearer'] as $transport) {
				$ids = EditScene::create($api, true);
				$uid = FormsScene::EDITOR;
				$client = $this->client($uid, $transport);
				$args = ['form_id' => $ids['form'], 'question_id' => $ids['email'], 'changes' => ['text' => 'Editor label', 'description' => 'Description', 'required' => true]];
				$policy->setGrant($uid, 'forms', 'edit', false);
				$before = FormsState::database();
				self::assertFalse($client->tool('forms_update_question', $args)->ok());
				self::assertSame($before, FormsState::database());
				$policy->setGrant($uid, 'forms', 'edit', true);
				foreach (['read', 'results', 'create'] as $operation) {
					$policy->setGrant($uid, 'forms', $operation, false);
				}
				$policy->setGrant($uid, 'files', 'read', false);
				$before = FormsState::database();
				$files = FormsState::files();
				$plan = $client->tool('forms_update_question', $args);
				self::assertTrue($plan->ok(), $plan->describe());
				self::assertSame($before, FormsState::database());
				self::assertTrue($plan->structured()['shared']);
				self::assertStringContainsString('15 minutes', $plan->text());
				$refuse = $client->tool('forms_update_question', $args + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
				self::assertTrue($refuse->json()['requiresConfirmation']);
				self::assertSame($before, FormsState::database());
				$reply = $client->tool('forms_update_question', $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan->structured()['plan_state']]);
				self::assertTrue($reply->ok(), $reply->describe());
				self::assertSame('applied', $reply->json()['status']);
				self::assertTrue($reply->json()['lock']['actorLockObserved']);
				$native = $api->api($uid, 'GET', "forms/{$ids['form']}");
				$question = $native['questions'][0];
				self::assertSame('Editor label', $question['text']);
				self::assertTrue($question['isRequired']);
				self::assertSame('Description', $question['description']);
				$form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($ids['form']);
				self::assertSame($uid, $form->getLockedBy());
				$lock = $form->getLockedUntil();
				$policy->setGrant(FormsScene::OWNER, 'forms', 'edit', true);
				self::assertTrue($this->client(FormsScene::OWNER, $transport)->tool('forms_update_question', $args)->isError(), 'Second editor must respect native lock');
				$option = ['form_id' => $ids['form'], 'question_id' => $ids['dropdown'], 'option_id' => $ids['option'], 'changes' => ['text' => str_repeat('😀', 1024)]];
				$reply = $this->edit($client, 'forms_update_option', $option, true);
				self::assertSame('applied', $reply['status']);
				self::assertNull($reply['confirmation']);
				$form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($ids['form']);
				self::assertGreaterThanOrEqual($lock, $form->getLockedUntil());
				$native = $api->api($uid, 'GET', "forms/{$ids['form']}");
				self::assertSame($option['changes']['text'], $native['questions'][1]['options'][0]['text']);
				self::assertSame('choice', $native['questions'][1]['options'][0]['optionType']);
				$before = FormsState::database();
				$bad = $option;
				$bad['changes']['text'] .= '😀';
				self::assertFalse($client->tool('forms_update_option', $bad)->ok());
				self::assertSame($before, FormsState::database());
				self::assertSame($files, FormsState::files());
			}
		} finally {
			foreach ([FormsScene::OWNER, FormsScene::EDITOR] as $uid) {
				$policy->setGrant($uid, 'forms', 'edit', false);
				$policy->setGrant($uid, 'forms', 'read', true);
				$policy->setGrant($uid, 'forms', 'results', true);
				$policy->setGrant($uid, 'forms', 'create', false);
				$policy->setGrant($uid, 'files', 'read', true);
			}
		}
	}
	/** Refuses Unicode-whitespace-only labels without changing native tables, while preserving spaces around nonblank text byte for byte.
	 * @return void
	 */
	public function testUnicodeBlankLabelsRefuseWithoutMutation(): void
	{
		$api = new FormsApiScene();
		$policy = Server::get(GrantPolicy::class);
		$uid = FormsScene::OWNER;
		$policy->setGrant($uid, 'forms', 'edit', true);
		try {
			foreach (['basic', 'bearer'] as $transport) {
				$ids = EditScene::create($api);
				$client = $this->client($uid, $transport);
				foreach ([false, true] as $option) {
					foreach (["\u{00a0}", "\u{3000}", " \t\u{00a0}"] as $blank) {
						$args = ['form_id' => $ids['form'], 'question_id' => $option ? $ids['dropdown'] : $ids['email'], 'changes' => ['text' => $blank]] + ($option ? ['option_id' => $ids['option']] : []);
						$before = FormsState::database();
						self::assertFalse($client->tool($option ? 'forms_update_option' : 'forms_update_question', $args)->ok());
						self::assertSame($before, FormsState::database());
					}
				}
				$text = "\u{00a0}Kept\u{3000}";
				$receipt = $this->edit($client, 'forms_update_question', ['form_id' => $ids['form'], 'question_id' => $ids['email'], 'changes' => ['text' => $text]]);
				self::assertSame('applied', $receipt['status']);
				self::assertSame($text, $api->api($uid, 'GET', "forms/{$ids['form']}")['questions'][0]['text']);
			}
		} finally {
			$policy->setGrant($uid, 'forms', 'edit', false);
		}
	}
	/** Observes active/inactive/inconsistent B1, re-selection outside MCP and unchanged historical submissions.
	 * @return void
	 */
	public function testConfirmationSelectionEffectsAndHistoricalAnswersRemainNative(): void
	{
		$api = new FormsApiScene();
		$policy = Server::get(GrantPolicy::class);
		$uid = FormsScene::OWNER;
		$policy->setGrant($uid, 'forms', 'edit', true);
		$settings = new EditAppSettings();
		$settings->set('forms', 'allowConfirmationEmail', false);
		$settings->set('mcp', 'allowConfirmationEmail', true);
		try {
			foreach ([true, false] as $enabled) {
				$ids = EditScene::create($api);
				$id = $ids['form'];
				$api->api($uid, 'PATCH', "forms/{$id}", ['keyValuePairs' => ['confirmationEmailEnabled' => $enabled, 'confirmationEmailQuestionId' => $ids['email']]]);
				$client = $this->client($uid, $enabled ? 'basic' : 'bearer');
				$args = ['form_id' => $id, 'question_id' => $ids['email'], 'changes' => ['settings' => ['validationType' => 'phone']]];
				$before = FormsState::database();
				$plan = $client->tool('forms_update_question', $args);
				self::assertTrue($plan->ok(), $plan->describe());
				self::assertTrue($plan->structured()['confirmation']['selection_clear_predicted']);
				self::assertSame($enabled, $plan->structured()['confirmation']['confirmation_enabled_on_form']);
				self::assertFalse($plan->structured()['confirmation']['confirmation_allowed_by_app']);
				self::assertSame($before, FormsState::database());
				if (!$enabled) {
					self::assertStringContainsString('already disabled', $plan->text());
				}
				$reply = $client->tool('forms_update_question', $args + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
				self::assertSame('applied', $reply->json()['status'], $reply->describe());
				self::assertTrue($reply->json()['confirmation']['selection_empty_observed']);
				$form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
				self::assertNull($form->getConfirmationEmailQuestionId());
				self::assertSame($enabled, $form->getConfirmationEmailEnabled());
				$reply = $this->edit($client, 'forms_update_question', ['form_id' => $id, 'question_id' => $ids['email'], 'changes' => ['settings' => ['validationType' => 'email']]]);
				self::assertTrue($reply['confirmation']['selection_empty_observed']);
				$api->api($uid, 'PATCH', "forms/{$id}", ['keyValuePairs' => ['confirmationEmailQuestionId' => $ids['email']]]);
				$form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
				self::assertSame($ids['email'], $form->getConfirmationEmailQuestionId());
				// Artificial native-mapper legacy selector: OCS refuses this state; this is not a normal UI-created case.
				EditInconsistentSelection::set($id, $ids['dropdown']);
				$reply = $this->edit($client, 'forms_update_question', ['form_id' => $id, 'question_id' => $ids['dropdown'], 'changes' => ['text' => 'Choice renamed']]);
				self::assertTrue($reply['confirmation']['selection_empty_observed']);
			}
			$ids = EditScene::create($api, true);
			$id = $ids['form'];
			$api->api(FormsScene::EDITOR, 'POST', "forms/{$id}/submissions", ['answers' => [$ids['email'] => ['synthetic@example.invalid'], $ids['dropdown'] => [(string) $ids['option']]]]);
			$before = $this->answers();
			$client = $this->client($uid, 'basic');
			$this->edit($client, 'forms_update_question', ['form_id' => $id, 'question_id' => $ids['email'], 'changes' => ['text' => str_repeat('😀', 2048), 'description' => str_repeat('😀', 4096), 'required' => true]], true);
			$this->edit($client, 'forms_update_option', ['form_id' => $id, 'question_id' => $ids['dropdown'], 'option_id' => $ids['option'], 'changes' => ['text' => 'Label after submission']], true);
			self::assertSame($before, $this->answers());
			$native = $api->api($uid, 'GET', "forms/{$id}");
			self::assertSame(str_repeat('😀', 2048), $native['questions'][0]['text']);
			self::assertSame(str_repeat('😀', 4096), $native['questions'][0]['description']);
			$bad = ['form_id' => $id, 'question_id' => $ids['email'], 'changes' => ['text' => str_repeat('😀', 2049)]];
			$before = FormsState::database();
			self::assertFalse($client->tool('forms_update_question', $bad)->ok());
			self::assertSame($before, FormsState::database());
		} finally {
			$settings->restore();
			$policy->setGrant($uid, 'forms', 'edit', false);
		}
	}
	/** Checks revocation, foreign/deleted active map and archive/changed-plan without a destructive call.
	 * @return void
	 */
	public function testActiveMapAndAuthorizationChangesRefuseWithoutMutation(): void
	{
		$api = new FormsApiScene();
		$ids = EditScene::create($api, true);
		$other = EditScene::create($api);
		$policy = Server::get(GrantPolicy::class);
		$uid = FormsScene::EDITOR;
		$policy->setGrant($uid, 'forms', 'edit', true);
		$client = $this->client($uid, 'bearer');
		try {
			$args = ['form_id' => $ids['form'], 'question_id' => $ids['email'], 'changes' => ['text' => 'Changed']];
			$plan = $client->tool('forms_update_question', $args);
			self::assertTrue($plan->ok(), $plan->describe());
			$api->api(FormsScene::OWNER, 'PATCH', "forms/{$ids['form']}/questions/{$ids['email']}", ['keyValuePairs' => ['text' => 'External']]);
			EditScene::unlock($api, $ids['form']);
			$before = FormsState::database();
			$changed = $client->tool('forms_update_question', $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan->structured()['plan_state']]);
			self::assertTrue($changed->ok(), $changed->describe());
			self::assertArrayHasKey('plan_state', $changed->structured());
			self::assertSame($before, FormsState::database());
			foreach ([$other['email'], PHP_INT_MAX] as $qid) {
				$bad = $args;
				$bad['question_id'] = $qid;
				self::assertFalse($client->tool('forms_update_question', $bad)->ok());
				self::assertSame($before, FormsState::database());
			}
			$api->api(FormsScene::OWNER, 'DELETE', "forms/{$ids['form']}/questions/{$ids['email']}");
			EditScene::unlock($api, $ids['form']);
			$before = FormsState::database();
			self::assertTrue($client->tool('forms_update_question', $args)->isError());
			self::assertSame($before, FormsState::database());
			$api->api(FormsScene::OWNER, 'DELETE', "forms/{$ids['form']}/shares/{$ids['share']}");
			$before = FormsState::database();
			$args['question_id'] = $ids['dropdown'];
			self::assertTrue($client->tool('forms_update_question', $args)->isError());
			self::assertSame($before, FormsState::database());
		} finally {
			$policy->setGrant($uid, 'forms', 'edit', false);
		}
	}
	/** Creates one transport client without printing its authentication material.
	 * @param string $uid Synthetic actor.
	 * @param string $transport Basic or Bearer.
	 * @return McpClient Authenticated MCP client.
	 */
	private function client(string $uid, string $transport): McpClient
	{
		return new McpClient($transport === 'basic' ? LoginFactory::basic($uid) : LoginFactory::bearer($uid));
	}
	/** Confirms exactly one independently previewed point edit.
	 * @param McpClient $client Actor client.
	 * @param string $tool Point tool.
	 * @param array<string,mixed> $args Point request.
	 * @param bool $shared Shared confirmation.
	 * @return array<string,mixed> Observed receipt.
	 */
	private function edit(McpClient $client, string $tool, array $args, bool $shared = false): array
	{
		$plan = $client->tool($tool, $args);
		self::assertTrue($plan->ok(), $plan->describe());
		$reply = $client->tool($tool, $args + ['confirm' => true, 'confirm_shared' => $shared, 'plan_state' => $plan->structured()['plan_state']]);
		self::assertTrue($reply->ok(), $reply->describe());
		return $reply->json();
	}
	/** Hashes only the submission/answer tables without logging rows or respondent information.
	 * @return array<string,mixed> Private-data digests.
	 */
	private function answers(): array
	{
		return array_filter(FormsState::database(), static fn(string $key): bool => str_contains($key, 'submission') || str_contains($key, 'answer'), ARRAY_FILTER_USE_KEY);
	}
}
