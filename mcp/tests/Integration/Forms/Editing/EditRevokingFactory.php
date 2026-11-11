<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Editing\EditContext;

/** Test-only renewed-policy boundary revokes a disposable grant externally without invalidating the outer local snapshot. */
final class EditRevokingFactory extends EditNativeFactory {
	/** Retains the real product factory without changing its session or DI.
	 * @param EditNativeFactory $real Real fresh admission.
	 */
	public function __construct(private EditNativeFactory $real) { }
	/** Revokes in a separate PHP process before reusing the real current policy admission.
	 * @param string $uid Synthetic actor.
	 * @param EditContext $context Current graph.
	 * @param callable(string):string|null $unsupportedVersion Caller-specific version refusal.
	 * @return void
	 */
	public function assertAllowed(string $uid,EditContext $context,?callable $unsupportedVersion = null):void{
		$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__,2).'/ci/forms/edit-revoke.php').' '.escapeshellarg($uid);$lines=[];exec($command.' 2>&1',$lines,$exit);
		if($exit!==0||implode('',$lines)!=='revoked'){throw new \RuntimeException('External edit-grant writer failed');}
		$this->real->assertAllowed($uid,$context,$unsupportedVersion);
	}
}
