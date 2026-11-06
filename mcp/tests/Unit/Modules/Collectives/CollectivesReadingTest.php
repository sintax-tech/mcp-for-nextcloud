<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesSurface;
use PHPUnit\Framework\TestCase;

/**
 * The four Collectives reads (C01) through the real module over the native doubles: what they show, what they never
 * show (share tokens, paths, templates, hidden pages), how they page and every admission refusal.
 */
final class CollectivesReadingTest extends TestCase {
	private CollectivesRig $rig;

	/** @return void a fresh scene and module */
	protected function setUp(): void {
		$this->rig = new CollectivesRig($this);
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments arguments
	 * @return array<string, mixed> the decoded JSON answer, or ['error' => message]
	 */
	private function call(string $tool, array $arguments): array {
		$result = $this->rig->module()->call($tool, $arguments, 'alice');
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments arguments
	 * @return string the refusal message
	 */
	private function refusal(string $tool, array $arguments): string {
		try {
			$this->rig->module()->call($tool, $arguments, 'alice');
		} catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail("$tool was not refused");
	}

	/** @return void collectives come by allowlist: no share token, circle id or level */
	public function testListsCollectivesWithoutNativeSecrets(): void {
		$answer = $this->call('collectives_list', []);
		$this->assertSame([['id' => 7, 'name' => 'Wiki', 'emoji' => '📘', 'can_edit' => true, 'can_share' => false]], $answer['collectives']);
		$this->assertNull($answer['next_cursor']);
		$this->assertStringNotContainsString('secret', json_encode($answer));
	}

	/** @return void pages in Collectives order, landing page first; templates, paths and tokens never appear */
	public function testListsPagesInNativeOrderWithoutTemplatesOrPaths(): void {
		$answer = $this->call('collectives_pages', ['collective_id' => 7]);
		$this->assertSame(['Landing page', 'Team', 'Onboarding', 'Guide'], array_column($answer['pages'], 'title'));
		$this->assertSame(0, $answer['pages'][0]['parent_id']);
		$this->assertSame(['id', 'title', 'slug', 'parent_id', 'emoji', 'last_user', 'timestamp', 'size', 'subpage_order'], array_keys($answer['pages'][0]));
		$this->assertStringNotContainsString('secret', json_encode($answer));
		$this->assertStringNotContainsString('Template', json_encode($answer));
		$this->assertSame(['getPagesFromFolder'], CollectivesScene::$calls, 'the tree is read without forcing an index page');
	}

	/** @return void a parent filter keeps its subtree; a cursor continues only the listing that gave it */
	public function testFiltersByParentAndPagesWithSignedCursors(): void {
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$this->assertSame(['Onboarding'], array_column($this->call('collectives_pages', ['collective_id' => 7, 'parent_id' => $team])['pages'], 'title'));
		$first = $this->call('collectives_pages', ['collective_id' => 7, 'limit' => 3]);
		$this->assertCount(3, $first['pages']);
		$second = $this->call('collectives_pages', ['collective_id' => 7, 'limit' => 3, 'cursor' => $first['next_cursor']]);
		$this->assertSame(['Guide'], array_column($second['pages'], 'title'));
		$this->assertNull($second['next_cursor']);
		$this->assertStringContainsString('cursor', $this->refusal('collectives_pages', ['collective_id' => 7, 'parent_id' => $team, 'cursor' => $first['next_cursor']]));
		$this->assertStringContainsString('cursor', $this->refusal('collectives_pages', ['collective_id' => 7, 'cursor' => '3.forged']));
	}

	/** @return void a page is read whole with its etag; a template, a hidden page or an unknown id read as not found */
	public function testReadsAPageAndRefusesTemplatesHiddenAndForeignIds(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$answer = $this->call('collectives_read', ['collective_id' => 7, 'page_id' => $guide]);
		$this->assertSame("Guide text\n", $answer['content']);
		$this->assertSame('e' . $guide, $answer['etag']);
		$this->assertSame('Guide', $answer['page']['title']);
		$notFound = $this->refusal('collectives_read', ['collective_id' => 7, 'page_id' => 99999]);
		$this->assertSame($notFound, $this->refusal('collectives_read', ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['.templates/Template.md']]));
		$this->assertSame($notFound, $this->refusal('collectives_read', ['collective_id' => 8, 'page_id' => $guide]));
		$this->rig->hidden = [$guide];
		$this->assertSame($notFound, $this->refusal('collectives_read', ['collective_id' => 7, 'page_id' => $guide]));
		$this->rig->hidden = [];
		$this->rig->outOfScope = [$guide];
		$this->assertSame($notFound, $this->refusal('collectives_read', ['collective_id' => 7, 'page_id' => $guide]));
	}

	/** @return void only a .md outside every dotted folder is a page, whatever the tree says */
	public function testOnlyMarkdownOutsideDottedFoldersIsAPage(): void {
		$page = static fn (string $path, string $name): array => ['file_path' => $path, 'file_name' => $name];
		$this->assertSame('Readme.md', \OCA\Mcp\Modules\Collectives\Integration\PageFiles::relativePath($page('', 'Readme.md')));
		$this->assertSame('Team/Guide.md', \OCA\Mcp\Modules\Collectives\Integration\PageFiles::relativePath($page('Team', 'Guide.md')));
		foreach ([$page('.templates', 'Template.md'), $page('Team/.attachments.12', 'Note.md'), $page('', '.hidden.md'),
			$page('Team/../..', 'Guide.md'), $page('Team', 'Guide.txt')] as $outside) {
			$this->assertSame('', \OCA\Mcp\Modules\Collectives\Integration\PageFiles::relativePath($outside), json_encode($outside));
		}
	}

	/** @return void a page over the limit is refused, never truncated */
	public function testRefusesAPageOverTheReadLimit(): void {
		CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Guide.md']['content'] = str_repeat('a', 262145);
		$this->assertStringContainsString('262144', $this->refusal('collectives_read', ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Guide.md']]));
	}

	/** @return void title search over the tree; content hits are kept only for admitted pages and carry the index notice */
	public function testSearchesByTitleAndByContentWithoutLeaks(): void {
		$this->assertSame(['Guide'], array_column($this->call('collectives_search', ['collective_id' => 7, 'query' => 'gui'])['pages'], 'title'));
		CollectivesScene::$searchHits = [['file_id' => CollectivesScene::$ids['.templates/Template.md']], ['file_id' => 4242],
			['file_id' => CollectivesScene::$ids['Team/Onboarding.md']]];
		$answer = $this->call('collectives_search', ['collective_id' => 7, 'query' => 'welcome', 'scope' => 'content']);
		$this->assertSame(['Onboarding'], array_column($answer['pages'], 'title'));
		$this->assertCount(1, $answer['notices']);
		CollectivesScene::$searchHits = array_fill(0, 100, ['file_id' => CollectivesScene::$ids['Guide.md']]);
		$this->assertCount(2, $this->call('collectives_search', ['collective_id' => 7, 'query' => 'x', 'scope' => 'content'])['notices'], 'a full budget is declared');
	}

	/** @return void a version without a proven content search refuses it and keeps the title search */
	public function testRefusesContentSearchOfAnUnprovenShape(): void {
		$this->rig->surface = new CollectivesSurface(CollectivesSurface::MEMBERS, 'OCA\\Collectives\\Service\\MissingSearch');
		$this->assertStringContainsString('Content search', $this->refusal('collectives_search', ['collective_id' => 7, 'query' => 'x', 'scope' => 'content']));
	}

	/** @return void each admission condition alone refuses before Collectives is asked */
	public function testEveryAdmissionConditionRefuses(): void {
		$read = ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Guide.md']];
		$unavailable = 'Collectives is not available in a supported version.';
		foreach ([
			'no grant' => fn () => $this->rig->grants = [],
			'app disabled' => fn () => $this->rig->appEnabled = false,
			'another session' => fn () => $this->rig->sessionUser = 'bob',
		] as $case => $break) {
			$this->rig = new CollectivesRig($this);
			$break();
			$this->assertSame($unavailable, $this->refusal('collectives_read', $read), $case);
			$this->assertSame([], CollectivesScene::$calls, $case . ': Collectives was asked');
		}
		$this->rig = new CollectivesRig($this);
		$this->rig->folderSetting = '';
		$this->assertStringContainsString('open Collectives once', $this->refusal('collectives_list', []));
		foreach (CollectivesSurface::MEMBERS as $index => $member) {
			$this->rig = new CollectivesRig($this);
			$members = CollectivesSurface::MEMBERS;
			$members[$index][1] .= 'Missing';
			$this->rig->surface = new CollectivesSurface($members);
			$this->assertSame($unavailable, $this->refusal('collectives_list', []), 'without ' . $member[1]);
		}
	}
}
