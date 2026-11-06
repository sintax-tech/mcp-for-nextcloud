<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Model\PageInfo;
use OCA\Collectives\Service\NotFoundException;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

/**
 * The world behind the native Collectives doubles: one collective "Wiki" (id 7) of alice, mounted at
 * `/alice/files/.Collectives/Wiki` in a {@see FakeTree}. Pages are the `.md` files of that folder outside dotted
 * folders, derived the way PageInfo derives them; a `.templates` file exists to prove it is never a page.
 */
final class CollectivesScene {
	/** The collective of the scene. */
	public const CID = 7;
	/** Its folder in alice's tree. */
	public const ROOT = '/alice/files/.Collectives/Wiki';

	public static FakeTree $tree;
	/** @var array<int, array{name:string, members:list<string>, canEdit:bool}> collectives by id */
	public static array $collectives = [];
	/** @var array<int, list<int>> subpage order by page id */
	public static array $orders = [];
	/** @var list<string> native calls worth asserting */
	public static array $calls = [];
	/** @var list<array{file_id:int}> what the content index answers */
	public static array $searchHits = [];
	/** @var array<string, int> page ids by path relative to ROOT */
	public static array $ids = [];

	/**
	 * @param TestCase $test owner of the tree mocks
	 * @return void a landing page, a leaf "Guide", an index page "Team" with "Onboarding", and a template
	 */
	public static function reset(TestCase $test): void {
		require_once __DIR__ . '/NativeCollectives.php';
		self::$tree = new FakeTree($test);
		self::$collectives = [self::CID => ['name' => 'Wiki', 'members' => ['alice'], 'canEdit' => true]];
		self::$calls = [];
		self::$searchHits = [];
		self::$ids = [];
		foreach (['Readme.md' => "# Wiki\n", 'Guide.md' => "Guide text\n", 'Team/Readme.md' => "Team\n",
			'Team/Onboarding.md' => "Welcome\n", '.templates/Template.md' => "Template\n"] as $path => $content) {
			self::$ids[$path] = self::$tree->addFile(self::ROOT . '/' . $path, $content, 'text/markdown');
		}
		self::$orders = [self::$ids['Readme.md'] => [self::$ids['Team/Readme.md'], self::$ids['Guide.md']]];
	}

	/** @param string $uid actor @return list<Collective> collectives the actor is a member of */
	public static function collectivesOf(string $uid): array {
		$found = [];
		foreach (array_keys(self::$collectives) as $id) {
			if (in_array($uid, self::$collectives[$id]['members'], true)) {
				$found[] = self::collective($id, $uid);
			}
		}
		return $found;
	}

	/** @param int $id collective @param string $uid actor @return Collective a fresh entity with a share token */
	public static function collective(int $id, string $uid): Collective {
		$data = self::$collectives[$id] ?? null;
		if ($data === null || !in_array($uid, $data['members'], true)) {
			throw new NotFoundException('Collective not found: ' . $id);
		}
		return new Collective(['id' => $id, 'name' => $data['name'], 'emoji' => '📘', 'circleId' => 'circle-secret',
			'canEdit' => $data['canEdit'], 'canShare' => false, 'shareToken' => 'share-token-secret', 'level' => 9]);
	}

	/** @param int $cid collective @param string $uid actor @return Folder the actor's folder of the collective */
	public static function folder(int $cid, string $uid): Folder {
		self::collective($cid, $uid);
		return self::$tree->node(self::ROOT);
	}

	/** @return File the file with that id inside the collective folder */
	public static function fileById(int $cid, int $id, string $uid): File {
		self::collective($cid, $uid);
		foreach (self::$tree->nodes as $path => $node) {
			if ($node['id'] === $id && $node['type'] === 'file' && str_starts_with($path, self::ROOT . '/')) {
				return self::$tree->node($path);
			}
		}
		throw new NotFoundException('File not found: ' . $id);
	}

	/** @return list<PageInfo> every page of the collective, derived from the tree now */
	public static function pages(int $cid, string $uid): array {
		self::collective($cid, $uid);
		$pages = [];
		foreach (self::relativePages() as $relative => $id) {
			$dir = dirname($relative) === '.' ? '' : dirname($relative);
			$name = basename($relative);
			$isIndex = $name === 'Readme.md';
			$pages[] = new PageInfo(['id' => $id, 'title' => $isIndex ? ($dir === '' ? 'Landing page' : basename($dir)) : basename($name, '.md'),
				'parentId' => self::parentOf($relative), 'fileName' => $name, 'filePath' => $dir, 'emoji' => null,
				'lastUserDisplayName' => 'Alice', 'timestamp' => 1759200000, 'size' => strlen(self::$tree->nodes[self::ROOT . '/' . $relative]['content']),
				'subpageOrder' => self::$orders[$id] ?? [], 'shareToken' => 'page-token-secret', 'collectivePath' => '/appdata/collectives/7']);
		}
		return $pages;
	}

	/** @return PageInfo the created page; a leaf parent becomes a folder with a Readme.md first */
	public static function create(int $cid, int $parentId, string $title, ?int $templateId, string $uid): PageInfo {
		self::$calls[] = 'create';
		$folder = self::folderOf($parentId === 0 ? self::$ids['Readme.md'] : $parentId);
		$name = $title;
		for ($n = 2; isset(self::$tree->nodes[self::ROOT . '/' . ltrim($folder . '/' . $name . '.md', '/')]); $n++) {
			$name = $title . ' (' . $n . ')';
		}
		$id = self::$tree->addFile(self::ROOT . '/' . ltrim($folder . '/' . $name . '.md', '/'), '', 'text/markdown');
		$parent = $parentId === 0 ? self::$ids['Readme.md'] : $parentId;
		array_unshift(self::$orders[$parent], $id);
		return self::page($id);
	}

	/** @return PageInfo the page after the move; the order only changes when the parent changes */
	public static function move(int $cid, int $id, ?int $parentId, ?string $title, int $index, string $uid): PageInfo {
		self::$calls[] = 'move';
		$relative = array_search($id, self::relativePages(), true);
		$old = self::parentOf($relative);
		$parent = $parentId ?: $old;
		$isIndex = basename($relative) === 'Readme.md';
		$source = $isIndex ? dirname($relative) : $relative;
		$base = $title ?? ($isIndex ? basename(dirname($relative)) : basename($relative, '.md'));
		$target = ltrim(self::folderOf($parent) . '/' . $base . ($isIndex ? '' : '.md'), '/');
		self::rename($source, $target);
		if ($old !== $parent) {
			self::$orders[$old] = array_values(array_diff(self::$orders[$old] ?? [], [$id]));
			array_splice(self::$orders[$parent], min($index, count(self::$orders[$parent] ?? [])), 0, [$id]);
		}
		return self::page($id);
	}

	/** @return PageInfo the current page of that id */
	private static function page(int $id): PageInfo {
		foreach (self::pages(self::CID, 'alice') as $page) {
			if ($page->jsonSerialize()['id'] === $id) {
				return $page;
			}
		}
		throw new NotFoundException('Page not found: ' . $id);
	}

	/** @return string the folder, relative to ROOT, the subpages of a page live in; a leaf becomes a folder now */
	private static function folderOf(int $pageId): string {
		$relative = array_search($pageId, self::relativePages(), true);
		if (basename($relative) === 'Readme.md') {
			return dirname($relative) === '.' ? '' : dirname($relative);
		}
		$folder = substr($relative, 0, -3);
		self::rename($relative, $folder . '/Readme.md');
		self::$orders[$pageId] ??= [];
		return $folder;
	}

	/** @param string $from relative path @param string $to relative path, renaming everything below it too */
	private static function rename(string $from, string $to): void {
		$nodes = [];
		foreach (self::$tree->nodes as $path => $node) {
			$relative = substr($path, strlen(self::ROOT) + 1);
			if (str_starts_with($path, self::ROOT . '/') && ($relative === $from || str_starts_with($relative, $from . '/'))) {
				$path = self::ROOT . '/' . $to . substr($relative, strlen($from));
			}
			$nodes[$path] = $node;
		}
		self::$tree->nodes = $nodes;
		foreach (array_keys(self::$tree->nodes) as $path) {
			if (!isset(self::$tree->nodes[dirname($path)]) && str_starts_with($path, self::ROOT . '/')) {
				self::$tree->addFolder(dirname($path));
			}
		}
	}

	/** @return array<string, int> page ids by relative path: `.md` files outside dotted folders */
	private static function relativePages(): array {
		$pages = [];
		foreach (self::$tree->nodes as $path => $node) {
			$relative = str_starts_with($path, self::ROOT . '/') ? substr($path, strlen(self::ROOT) + 1) : null;
			if ($relative !== null && $node['type'] === 'file' && str_ends_with($relative, '.md') && !preg_match('#(^|/)\.#', $relative)) {
				$pages[$relative] = $node['id'];
			}
		}
		return $pages;
	}

	/** @param string $relative page path @return int its parent page id, 0 for the landing page */
	private static function parentOf(string $relative): int {
		$pages = self::relativePages();
		$dir = dirname($relative) === '.' ? '' : dirname($relative);
		if (basename($relative) === 'Readme.md') {
			if ($dir === '') {
				return 0;
			}
			$dir = dirname($dir) === '.' ? '' : dirname($dir);
		}
		return $pages[ltrim($dir . '/Readme.md', '/')] ?? 0;
	}
}
