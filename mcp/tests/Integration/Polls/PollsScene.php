<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

/** Users and ids belonging only to the disposable Polls cell. */
final class PollsScene {
	/** Native owner. */
	public const OWNER='mcp-it-polls-owner';
	/** Native participant with two explicit shares. */
	public const VIEWER='mcp-it-polls-viewer';
	/** Native delegated editor. */
	public const DELEGATE='mcp-it-polls-delegate';
	/** Only open polls are accessible. */
	public const OUTSIDER='mcp-it-polls-outsider';
	/** Instance admin, not a poll editor by that fact alone. */
	public const ADMIN='mcp-it-polls-admin';
	/** Entire isolated scene. */
	public const USERS=[self::OWNER,self::VIEWER,self::DELEGATE,self::OUTSIDER,self::ADMIN];

	/**
	 * Reads fixture ids without exposing tokens.
	 * @return array<string,mixed> ids
	 */
	public static function ids(): array {
		$file=getenv('MCP_IT_POLLS_SCENE');
		if (!is_string($file) || !is_file($file)) {throw new \RuntimeException('Polls fixture missing');}
		return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
	}

	/**
	 * Observational database snapshot ONLY for the tests, never a production read path.
	 * Includes every native Polls table discovered from the official schema, not only a preselected empty table.
	 * @return array<string,array{count:int,digest:string}> table counts and canonical row hashes
	 */
	public static function snapshot(): array {
		$db=\OCP\Server::get(\OCP\IDBConnection::class);
		$prefix=\OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix','oc_');
		$out=[];
		foreach ($db->createSchema()->getTables() as $table) {
			$name=$table->getName();
			if (str_starts_with($name,$prefix)) {$name=substr($name,strlen($prefix));}
			if (!str_starts_with($name,'polls_')) {continue;}
			$rows=$db->getQueryBuilder()->select('*')->from($name)->executeQuery();
			$canonical=[];
			foreach ($rows->fetchAll() as $row) {ksort($row);$canonical[]=json_encode($row,JSON_THROW_ON_ERROR);}
			$rows->closeCursor();sort($canonical);
			$out[$name]=['count'=>count($canonical),'digest'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR))];
		}
		ksort($out);
		if (count($out)<4) {throw new \RuntimeException('Polls snapshot did not cover its native tables');}
		return $out;
	}
}
