<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Creation;

use OCA\Mcp\Modules\Forms\Creation\CreateFormContext;
use OCA\Mcp\Modules\Forms\Creation\UploadFolderPrecheck;
use OCP\Files\{File,Folder,IRootFolder};
use OCP\Server;

/** Seeds a deliberate collision after S0 only in this disposable test, then invokes the real product precheck. */
final class CreationCollisionPrecheck extends UploadFolderPrecheck {
	/** @var string|null Full synthetic Files snapshot after seeding and before the product guard. */
	public ?string $before = null;
	/** Seeds both populated and empty matching folders, plus unrelated/nonmatching controls.
	 * @param CreateFormContext $context Real pinned Forms graph.
	 * @param int $id Fresh native form ID generated before the fixture executes.
	 * @param string $uid Actual synthetic actor.
	 * @return void
	 */
	public function assertClear(CreateFormContext $context, int $id, string $uid): void {
		$root = Server::get(IRootFolder::class)->getUserFolder($uid);
		$forms = $root->nodeExists('Forms') ? $root->get('Forms') : $root->newFolder('Forms');
		$forms->newFolder($id . ' - occupied')->newFile('synthetic.txt', 'preserved synthetic bytes');
		$forms->newFolder($id . ' - empty');
		$forms->newFolder($id . 'x - unrelated')->newFile('control.txt', 'control bytes');
		$forms->newFile($id . ' - ordinary-file', 'file is not a folder match');
		$this->before = self::snapshot($uid);
		parent::assertClear($context, $id, $uid);
	}
	/** Hashes IDs, paths and bytes of this synthetic actor's complete tree without printing them.
	 * @param string $uid Disposable actor.
	 * @return string Complete test-only snapshot digest.
	 */
	public static function snapshot(string $uid): string {
		$queue = [Server::get(IRootFolder::class)->getUserFolder($uid)]; $rows = [];
		while ($queue !== []) {
			$n = array_pop($queue); $rows[] = [$n->getId(), $n->getPath(), $n instanceof File ? hash('sha256', $n->getContent()) : 'folder'];
			if ($n instanceof Folder) { array_push($queue, ...$n->getDirectoryListing()); }
		}
		sort($rows); return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
	}
}
