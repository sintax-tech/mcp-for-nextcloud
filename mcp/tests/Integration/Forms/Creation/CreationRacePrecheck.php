<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Creation;
use OCA\Mcp\Tools\Forms\Creation\{CreateFormContext,UploadFolderPrecheck};
use OCP\Server;

/** Deterministic test barrier models a competing writer after the actual empty precheck, not a frequency claim. */
final class CreationRacePrecheck extends UploadFolderPrecheck {
	/** @var string|null Digest immediately after the fixture's competing write. */
	public ?string $beforeTitle = null;
	/** Checks the real native universe first, then introduces the permitted residual-race control.
	 * @param CreateFormContext $context Real pinned graph.
	 * @param int $id Newly generated form ID.
	 * @param string $uid Disposable actor.
	 * @return void
	 */
	public function assertClear(CreateFormContext $context, int $id, string $uid): void {
		parent::assertClear($context, $id, $uid);
		$root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
		$forms = $root->nodeExists('Forms') ? $root->get('Forms') : $root->newFolder('Forms');
		$forms->newFolder($id . ' - before-title')->newFile('synthetic.txt', 'native rename preserves these synthetic bytes');
		$this->beforeTitle = CreationCollisionPrecheck::snapshot($uid);
	}
}
