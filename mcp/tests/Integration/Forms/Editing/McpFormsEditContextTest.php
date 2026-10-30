<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Forms\{FormsApiScene, FormsScene};
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
/** Runs each captured graph and deterministic native failure in its own real server PHP process. */
final class McpFormsEditContextTest extends IntegrationTestCase
{
	/** Selects the opt-in isolated Forms stage.
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		if (getenv('MCP_IT_WITH_FORMS') !== '1') {
			self::markTestSkipped('Requires pinned Forms cell.');
		}
	}
	/** Discriminates both values of the real Forms-scoped config against the opposite MCP value in one process.
	 * @return void
	 */
	public function testNativeScopedConfigUsesFormsNotMcp(): void
	{
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(FormsScene::OWNER, 'forms', 'edit', true);
		try {
			$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/ci/forms/edit-context.php') . ' scoped-config 0 0 0 2>&1';
			$lines = [];
			exec($command, $lines, $exit);
			self::assertSame(0, $exit, implode("\n", $lines));
			$out = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
			self::assertTrue($out['formsScopeTrueControl']);
			self::assertTrue($out['formsScopeFalseControl']);
			self::assertFalse($out['nativeCallAttempted']);
			file_put_contents('/tmp/mcp-forms-edit-context.jsonl', json_encode($out, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
		} finally {
			$policy->setGrant(FormsScene::OWNER, 'forms', 'edit', false);
		}
	}
	/** Covers cache captures and both question/option partial-write and logical-delete windows with native persistence.
	 * @return void
	 */
	public function testNativeGraphsAndOnePatchPartialBoundaries(): void
	{
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(FormsScene::OWNER, 'forms', 'edit', true);
		$api = new FormsApiScene();
		try {
			$cases = ['cold', 'warm', 'controller-null', 'controller-other', 'config-null', 'config-other', 'submission-null', 'submission-other', 'forms-null', 'forms-other', 'no-native-create', 'external-revoke', 'partial', 'deleted-race'];
			if (PHP_VERSION_ID >= 80400 && property_exists(\OC\AppFramework\Utility\SimpleContainer::class, 'useLazyObjects')) {
				$cases[] = 'lazy';
			}
			foreach ($cases as $case) {
				foreach (in_array($case, ['partial', 'deleted-race'], true) ? [false, true] : [false] as $option) {
					$ids = EditScene::create($api);
					$qid = $option ? $ids['dropdown'] : $ids['email'];
					$oid = $option ? $ids['option'] : 0;
					$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/ci/forms/edit-context.php') . ' ' . escapeshellarg($case) . ' ' . escapeshellarg((string) $ids['form']) . ' ' . escapeshellarg((string) $qid) . ' ' . escapeshellarg((string) $oid) . ' 2>&1';
					$lines = [];
					exec($command, $lines, $exit);
					self::assertSame(0, $exit, implode("\n", $lines));
					$out = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
					self::assertSame(!str_starts_with($case, 'forms-') && $case !== 'external-revoke', $out['accepted']);
					if ($case === 'lazy') {
						self::assertTrue($out['initialLazy']);
					}
					if ($case === 'partial') {
						self::assertSame('partial', $out['status']);
						self::assertFalse($out['actorLockObserved']);
					}
					if ($case === 'deleted-race') {
						self::assertSame('uncertain', $out['status']);
						self::assertTrue($out['secondProcessBarrier']);
					}
					file_put_contents('/tmp/mcp-forms-edit-context.jsonl', json_encode($out + ['optionTool' => $option], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
				}
			}
		} finally {
			$policy->setGrant(FormsScene::OWNER, 'forms', 'edit', false);
		}
	}
}
