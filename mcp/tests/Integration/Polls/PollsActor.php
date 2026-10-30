<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

/** Isolated real Nextcloud bootstrap request; no OC_CONSOLE god-mode constant is introduced. */
final class PollsActor {
	/**
	 * Runs the scoped fixture probe in a separate PHP process in the NC container, never on the host.
	 * @param string $uid explicit actor
	 * @param string $action probe action
	 * @param array<string,mixed> $input fixture input
	 * @return array<string,mixed> safe probe output
	 */
	public static function run(string $uid,string $action,array $input=[]): array {
		$process=proc_open([PHP_BINARY,__DIR__.'/polls-actor.php',$uid,$action],
			[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
		if (!is_resource($process)) {throw new \RuntimeException('Cannot run Polls fixture actor');}
		fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);
		$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
		$code=proc_close($process);
		if ($code!==0) {throw new \RuntimeException('Polls probe failed ('.$code.'): '.$err.' '.$out);}
		return json_decode($out,true,512,JSON_THROW_ON_ERROR);
	}
}
