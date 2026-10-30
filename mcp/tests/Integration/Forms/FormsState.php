<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

use OCP\Server;

/** Read-only evidence snapshots: hashes only, not rows, submissions, user text or secrets. */
final class FormsState {
	/** Digests every Forms table through the disposable server connection, solely for P9 test evidence.
	 * @return array<string,array<string,mixed>> Per-table count and complete content hash.
	 */
	public static function database(): array {
		$db=Server::get(\OCP\IDBConnection::class);$prefix=Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix','oc_');$out=[];
		foreach($db->createSchema()->getTables() as $table){
			$name=$table->getName();if(!str_starts_with($name,$prefix.'forms_v2_')){continue;}
			$query=$db->getQueryBuilder();$result=$query->select('*')->from(substr($name,strlen($prefix)))->executeQuery();
			try{$rows=$result->fetchAll();}finally{$result->closeCursor();}
			$rows=array_map(static function(array $row):string{ksort($row);return json_encode($row,JSON_THROW_ON_ERROR);},$rows);sort($rows);
			$out[$name]=['rows'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
		}
		ksort($out);return $out;
	}

	/** Hashes a synthetic owner's native personal files without returning content or paths.
	 * @return string Complete known tree hash, including file bytes.
	 */
	public static function files(): string {
		$root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder(FormsScene::OWNER);$queue=[$root];$entries=[];
		while($queue!==[]){$node=array_pop($queue);$entries[]=[(int)$node->getId(),$node instanceof \OCP\Files\File ? hash('sha256',$node->getContent()) : 'folder'];
			if($node instanceof \OCP\Files\Folder){array_push($queue,...$node->getDirectoryListing());}}
		sort($entries);return hash('sha256',json_encode($entries,JSON_THROW_ON_ERROR));
	}
}
