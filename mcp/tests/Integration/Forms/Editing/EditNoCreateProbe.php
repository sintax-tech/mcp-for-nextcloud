<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Modules\Forms\Editing\EditContext;

/** Separates native edit admission from canCreateForms in the exact in-process graph, without a cross-SAPI config-cache claim. */
final class EditNoCreateProbe {
	/** Temporarily denies native creation for the graph actor, then performs its one admitted native point PATCH.
	 * @param EditContext $context Actual native graph.
	 * @param callable():array<string,mixed> $write One real product writer call.
	 * @return array<string,mixed> Native denied-create and allowed-edit observations.
	 */
	public static function run(EditContext $context,callable $write):array{
		$config=\OCP\Server::get(\OCP\IAppConfig::class);$config->clearCache();
		$had=$config->hasKey('forms','restrictCreation');$old=$config->getValueBool('forms','restrictCreation',false);$groupsHad=$config->hasKey('forms','creationAllowedGroups');$groups=$config->getValueArray('forms','creationAllowedGroups',[]);
		try{
			$config->setValueBool('forms','restrictCreation',true);$config->setValueArray('forms','creationAllowedGroups',[]);
			if($context->config->canCreateForms()!==false){throw new \RuntimeException('Native creation control must be denied in this exact graph');}
			$receipt=$write();if($receipt['status']!=='applied'){throw new \RuntimeException('Native edit incorrectly required creation permission');}
			return ['scenario'=>'no-native-create','accepted'=>true,'nativeCanCreateFalse'=>true,'nativeEditApplied'=>true,'status'=>$receipt['status']];
		}finally{
			$config->clearCache();if($had){$config->setValueBool('forms','restrictCreation',$old);}else{$config->deleteKey('forms','restrictCreation');}
			if($groupsHad){$config->setValueArray('forms','creationAllowedGroups',$groups);}else{$config->deleteKey('forms','creationAllowedGroups');}
		}
	}
}
