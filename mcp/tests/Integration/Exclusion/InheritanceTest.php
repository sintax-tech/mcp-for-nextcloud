<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V2 (real): a file inside an excluded folder is hidden without a tag of its own, however deep, whether it was
 * created from outside the tools (WebDAV, the server's Files API) or through the tools before the folder was excluded.
 */
final class InheritanceTest extends ExclusionTestCase {
	/** @return array<string, array{string}> */
	public static function places(): array {
		return ['home' => ['home'], 'groupfolder' => ['groupfolder'], 'external storage' => ['external'], 'external alias' => ['alias']];
	}

	#[DataProvider('places')]
	public function testAFileCreatedOutsideTheToolsInsideAnExcludedFolderIsHidden(string $arena): void {
		$this->makeFile($arena, 'open/pub-' . $this->run . '.txt', 'public');
		$locked = $this->makeFolder($arena, 'locked');
		$this->tag($locked);
		$this->exclude();

		// Created by another client through the server's own WebDAV endpoint, after the folder is already excluded.
		$dav = $this->dav();
		$base = $this->path($arena, 'locked');
		foreach (['a', 'a/b', 'a/b/c'] as $folder) {
			$dav->mkcol($base . '/' . $folder);
		}
		$dav->put($base . '/a/b/c/new-' . $this->run . '.txt', 'secret by inheritance');
		$dav->put($base . '/top-' . $this->run . '.txt', 'secret at the top');

		$alice = $this->mcp();
		foreach (['a/b/c/new-' . $this->run . '.txt', 'top-' . $this->run . '.txt', 'a/b/c', 'a', ''] as $relative) {
			$this->assertHiddenFromRead($alice, rtrim($base . '/' . $relative, '/'), "locked/$relative");
		}
		$this->assertNotContains('locked', $this->listNames($alice, $this->path($arena)) ?? ['locked'], 'the excluded folder is not listed');
		$this->assertContains('open', $this->listNames($alice, $this->path($arena)) ?? [], 'its sibling still is');

		$tree = $alice->tool('files_tree', ['path' => $this->path($arena)]);
		$this->assertTrue($tree->ok(), $tree->describe());
		$this->assertStringContainsString('pub-' . $this->run . '.txt', $tree->text());
		$this->assertStringNotContainsString('new-' . $this->run, $tree->text());
		$this->assertStringNotContainsString('locked', $tree->text());

		$this->assertSame([], $this->searchNames($alice, 'new-' . $this->run), 'name search finds nothing of the excluded folder');
		$this->assertContains('pub-' . $this->run . '.txt', $this->searchNames($alice, 'pub-' . $this->run), 'name search still finds the visible file');
	}

	#[DataProvider('places')]
	public function testAFileCreatedThroughTheToolsBecomesHiddenWhenAnAncestorIsExcluded(string $arena): void {
		$alice = $this->mcp();
		$mkdir = $alice->write('files_mkdir', ['path' => $this->path($arena, 'proj', 'sub')]);
		$this->assertTrue($mkdir->ok(), 'files_mkdir: ' . $mkdir->describe());
		$create = $alice->write('files_create', ['path' => $this->path($arena, 'proj', 'sub', 'made.txt'), 'content' => 'made by the tools']);
		$this->assertTrue($create->ok(), 'files_create: ' . $create->describe());
		$this->exclude();
		$this->assertReadable($alice, $this->path($arena, 'proj', 'sub', 'made.txt'), 'made by the tools', 'before the exclusion');

		// The tag goes on the grandparent only; the file and its folder never carry one.
		$this->tag($this->node(self::ALICE, $this->path($arena, 'proj')));
		$this->assertHiddenFromRead($alice, $this->path($arena, 'proj', 'sub', 'made.txt'), 'file under an excluded grandparent');
		$this->assertHiddenFromRead($alice, $this->path($arena, 'proj', 'sub'), 'folder under an excluded parent');
		$this->assertNotContains('proj', $this->listNames($alice, $this->path($arena)) ?? ['proj']);
	}

	public function testDescendantsAtEveryLevelOfAnExcludedFolderCreatedByTheServersFilesApi(): void {
		$locked = $this->makeFolder('home', 'locked');
		$this->tag($locked);
		$this->exclude();
		$deep = 'locked/l1/l2/l3/l4/l5';
		$this->makeFile('home', $deep . '/leaf.txt', 'leaf');
		$this->makeFile('home', 'locked/l1/mid.txt', 'mid');

		$alice = $this->mcp();
		foreach (['locked/l1/l2/l3/l4/l5/leaf.txt', 'locked/l1/mid.txt', 'locked/l1/l2/l3/l4/l5', 'locked/l1'] as $relative) {
			$this->assertHiddenFromRead($alice, $this->path('home', $relative), $relative);
		}
	}

	/** @return list<string> names found by a name search */
	private function searchNames(\OCA\Mcp\Tests\Integration\Support\McpClient $client, string $query): array {
		$reply = $client->tool('files_search', ['query' => $query, 'mode' => 'name', 'limit' => 50]);
		$this->assertTrue($reply->ok(), 'files_search: ' . $reply->describe());
		$data = $reply->json();
		$this->assertIsArray($data);
		return array_values(array_map(static fn (array $f): string => (string)$f['name'], $data['files'] ?? []));
	}
}
