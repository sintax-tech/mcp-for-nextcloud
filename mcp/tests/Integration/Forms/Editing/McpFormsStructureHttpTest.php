<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Forms\Integration\StructureCapabilities;
use OCA\Mcp\Tests\Integration\Forms\{FormsApiScene, FormsScene, FormsState};
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Minimal Bearer structure proof with native OCS readback, independent edit grant and forbidden grid conversion. */
#[Group('forms')]
final class McpFormsStructureHttpTest extends IntegrationTestCase {
	/** Selects the explicit Forms cell and proven version pins.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_FORMS') !== '1') {
			self::markTestSkipped('Requires pinned Forms cell.');
		}
		if (!in_array(Server::get(IAppManager::class)->getAppVersion('forms'), StructureCapabilities::PROVEN, true)) {
			self::markTestSkipped('Installed Forms version is outside the proven structure pins.');
		}
	}

	/** Adds a question/options, reorders and deletes an option; refuses grid without changing native state.
	 * @return void
	 */
	public function testBearerStructureRoundtripAndGridRefusal(): void {
		$api = new FormsApiScene();
		$id = $api->form('HTTP structure proof');
		$uid = FormsScene::OWNER;
		$client = new McpClient(LoginFactory::bearer($uid));
		$policy = Server::get(GrantPolicy::class);
		$args = ['form_id' => $id, 'type' => 'dropdown', 'text' => 'HTTP choice'];
		try {
			$policy->setGrant($uid, 'forms', 'edit', false);
			$before = FormsState::database();
			self::assertFalse($client->tool('forms_add_question', $args)->ok());
			self::assertSame($before, FormsState::database());
			$policy->setGrant($uid, 'forms', 'edit', true);
			$question = $this->apply($client, 'forms_add_question', $args)['questionId'];
			$form = $api->api($uid, 'GET', "forms/$id");
			self::assertSame([$question], array_column($form['questions'], 'id'));
			self::assertSame('dropdown', $form['questions'][0]['type']);
			self::assertSame('HTTP choice', $form['questions'][0]['text']);
			$target = ['form_id' => $id, 'question_id' => $question];
			$options = $this->apply($client, 'forms_add_option', $target + ['texts' => ['First', 'Second'], 'option_type' => 'choice'])['optionIds'];
			self::assertCount(2, $options);
			$form = $api->api($uid, 'GET', "forms/$id");
			self::assertSame(['First', 'Second'], array_column($form['questions'][0]['options'], 'text'));
			$this->apply($client, 'forms_reorder_options', $target + ['option_ids' => array_reverse($options)]);
			$form = $api->api($uid, 'GET', "forms/$id");
			self::assertSame(array_reverse($options), array_column($form['questions'][0]['options'], 'id'));
			$this->apply($client, 'forms_delete_option', $target + ['option_id' => $options[0]]);
			$form = $api->api($uid, 'GET', "forms/$id");
			self::assertSame([$options[1]], array_column($form['questions'][0]['options'], 'id'));
			$before = FormsState::database();
			foreach ([false, true] as $confirm) {
				self::assertFalse($client->tool('forms_change_question_type', $target + ['type' => 'grid', 'confirm' => $confirm])->ok());
				self::assertSame($before, FormsState::database());
			}
		} finally {
			$policy->setGrant($uid, 'forms', 'edit', false);
		}
	}

	/** Confirms exactly one independently previewed structure change.
	 * @param McpClient $client Authenticated actor.
	 * @param string $tool Published tool.
	 * @param array<string,mixed> $args Request.
	 * @return array<string,mixed> Observed receipt.
	 */
	private function apply(McpClient $client, string $tool, array $args): array {
		$before = FormsState::database();
		$plan = $client->tool($tool, $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['requiresConfirmation']);
		self::assertSame($before, FormsState::database());
		$reply = $client->tool($tool, $args + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame('applied', $reply->json()['status'], $reply->describe());
		return $reply->json();
	}
}
