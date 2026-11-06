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
		$this->assertSame([], CollectivesScene::$calls, 'no native tree builder is called, so no index page can be written');
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
		$this->rig->version = '4.5.0';
		$this->assertStringContainsString('cursor', $this->refusal('collectives_pages', ['collective_id' => 7, 'limit' => 3, 'cursor' => $first['next_cursor']]), 'another version');
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

	/** @return void a folder of pages without its Readme.md: reads and plans are refused before Collectives could create it */
	public function testAMissingIndexIsRefusedInsteadOfCreated(): void {
		unset(CollectivesScene::$tree->nodes[CollectivesScene::ROOT . '/Team/Readme.md']);
		$this->rig->grants = ['read'];
		$before = CollectivesScene::$tree->nodes;
		foreach ([['collectives_pages', ['collective_id' => 7]], ['collectives_read', ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Guide.md']]],
			['collectives_search', ['collective_id' => 7, 'query' => 'x']]] as [$tool, $arguments]) {
			$this->assertStringContainsString('no Readme.md', $this->refusal($tool, $arguments), $tool);
		}
		$this->rig->grants = ['read', 'create', 'edit', 'move'];
		try {
			$this->rig->module()->preview('collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'X'], 'alice');
			$this->fail('a plan was made over a tree Collectives would complete');
		} catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
			$this->assertStringContainsString('no Readme.md', $e->getMessage());
		}
		$this->assertSame($before, CollectivesScene::$tree->nodes, 'nothing was written');
		$this->assertNotContains('newPage', CollectivesScene::$calls);
		$this->assertNotContains('getPagesFromFolder', CollectivesScene::$calls, 'Collectives was not asked for the tree');
	}

	/**
	 * The Readme.md of a folder is removed by somebody else after the module listed the folder and before Collectives
	 * reads its pages: nothing is written, at read or plan, and the pages that lost their index are left out.
	 * @return void
	 */
	public function testAnIndexRemovedDuringTheReadIsNeverRecreated(): void {
		$team = CollectivesScene::ROOT . '/Team/Readme.md';
		$remove = static function () use ($team): void {
			unset(CollectivesScene::$tree->nodes[$team]);
		};
		CollectivesScene::$beforeFind = $remove;
		$this->rig->grants = ['read'];
		$pages = $this->call('collectives_pages', ['collective_id' => 7])['pages'];
		$this->assertSame(['Landing page', 'Guide'], array_column($pages, 'title'), 'Team and Onboarding lost their index and are left out');
		$this->assertArrayNotHasKey($team, CollectivesScene::$tree->nodes, 'the index was not written back');
		$this->assertNotContains('newPage', CollectivesScene::$calls);
		CollectivesScene::reset($this);
		$this->rig = new CollectivesRig($this);
		$before = CollectivesScene::$tree->nodes;
		unset($before[$team]);
		CollectivesScene::$beforeFind = $remove;
		$this->rig->module()->preview('collectives_create', ['collective_id' => 7, 'parent_id' => 0, 'title' => 'X'], 'alice');
		$this->assertSame($before, CollectivesScene::$tree->nodes, 'the plan wrote nothing either');
		$this->assertSame([], array_values(array_intersect(['newPage', 'getPagesFromFolder', 'create'], CollectivesScene::$calls)));
	}

	/** @return void a hidden page leaks nowhere: not in the subpage order nor as the parent of a page shown */
	public function testHiddenPagesLeakNoIdThroughReferences(): void {
		$guide = CollectivesScene::$ids['Guide.md'];
		$team = CollectivesScene::$ids['Team/Readme.md'];
		$this->rig->hidden = [$guide, $team];
		$pages = $this->call('collectives_pages', ['collective_id' => 7])['pages'];
		$this->assertSame(['Landing page', 'Onboarding'], array_column($pages, 'title'));
		$this->assertSame([], $pages[0]['subpage_order']);
		$this->assertNull($pages[1]['parent_id'], 'the parent of Onboarding may not be shown');
		$landing = $this->call('collectives_read', ['collective_id' => 7, 'page_id' => CollectivesScene::$ids['Readme.md']]);
		foreach ([$guide, $team] as $id) {
			$this->assertNotContains($id, $landing['page']['subpage_order']);
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

	/** @return void the 4.0.0 shape, whose search creates the indices folder, is refused before Collectives is asked */
	public function testTheLegacySearchIsRefusedWithoutTouchingCollectives(): void {
		$this->rig->surface = new CollectivesSurface(CollectivesSurface::MEMBERS, 'OCA\\Collectives\\Service\\LegacySearchService');
		CollectivesScene::$searchHits = [['file_id' => CollectivesScene::$ids['Guide.md']]];
		$this->assertStringContainsString('Content search is not available', $this->refusal('collectives_search', ['collective_id' => 7, 'query' => 'x', 'scope' => 'content']));
		$this->assertSame([], array_values(array_filter(CollectivesScene::$calls, static fn (string $call): bool => str_starts_with($call, 'searchCollective'))));
		$this->assertSame(['Guide'], array_column($this->call('collectives_search', ['collective_id' => 7, 'query' => 'guide'])['pages'], 'title'), 'title search still works');
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
