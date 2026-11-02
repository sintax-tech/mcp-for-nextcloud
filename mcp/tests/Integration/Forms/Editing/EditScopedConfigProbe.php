<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Modules\Forms\Editing\EditContext;

/** Discriminates the actual fresh config collaborator's app scope within one native process, avoiding cross-SAPI cache assumptions. */
final class EditScopedConfigProbe {
	/** Uses opposite settings under forms/mcp and checks the actual factory-returned collaborator, restoring both in finally.
	 * @param EditContext $context Real product native graph.
	 * @return array<string,mixed> Observed namespace boundary; no form, file or mail operation occurs.
	 */
	public static function run(EditContext $context):array{
		$settings=new EditAppSettings();
		try{
			$settings->set('forms','allowConfirmationEmail',true);$settings->set('mcp','allowConfirmationEmail',false);
			if($context->config->getAllowConfirmationEmail()!==true){throw new \RuntimeException('Actual config graph did not read the forms app scope');}
			$settings->set('forms','allowConfirmationEmail',false);$settings->set('mcp','allowConfirmationEmail',true);
			if($context->config->getAllowConfirmationEmail()!==false){throw new \RuntimeException('Actual config graph incorrectly followed the mcp app scope');}
			return ['scenario'=>'scoped-config','accepted'=>true,'formsScopeTrueControl'=>true,'formsScopeFalseControl'=>true,'nativeCallAttempted'=>false];
		}finally{$settings->restore();}
	}
}
