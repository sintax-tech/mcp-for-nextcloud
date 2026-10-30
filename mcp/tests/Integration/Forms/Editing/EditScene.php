<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCA\Mcp\Tests\Integration\Forms\{FormsApiScene,FormsScene};

/** Creates only disposable editing targets through native OCS; its explicit unlock is fixture setup, never product behavior. */
final class EditScene {
	/** Builds a private form with active email and dropdown targets and an optional EDIT share.
	 * @param FormsApiScene $api Native OCS fixture client.
	 * @param bool $shared Whether to share with the synthetic editor.
	 * @return array<string,int> Synthetic form/question/option/share identifiers.
	 */
	public static function create(FormsApiScene $api, bool $shared = false): array {
		$id=$api->form('FO4 synthetic editing');
		$q=$api->api(FormsScene::OWNER,'POST',"forms/$id/questions",['type'=>'short','text'=>'Email']);
		$api->api(FormsScene::OWNER,'PATCH',"forms/$id/questions/{$q['id']}",['keyValuePairs'=>['extraSettings'=>['validationType'=>'email']]]);
		$d=$api->api(FormsScene::OWNER,'POST',"forms/$id/questions",['type'=>'dropdown','text'=>'Choice']);
		$options=$api->api(FormsScene::OWNER,'POST',"forms/$id/questions/{$d['id']}/options",['optionTexts'=>['A','B'],'optionType'=>'choice']);
		$share=$shared?$api->share($id,0,FormsScene::EDITOR,['submit','edit'])['id']:0;
		self::unlock($api,$id);
		return ['form'=>$id,'email'=>$q['id'],'dropdown'=>$d['id'],'option'=>$options[0]['id'],'share'=>$share];
	}
	/** Resets a synthetic setup lock through the native owner API before another actor's test.
	 * @param FormsApiScene $api Native OCS client.
	 * @param int $id Synthetic form.
	 * @return void
	 */
	public static function unlock(FormsApiScene $api,int $id):void{$api->api(FormsScene::OWNER,'PATCH',"forms/$id",['keyValuePairs'=>['lockedUntil'=>null]]);}
}
