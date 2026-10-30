<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

/** Saves/restores only synthetic-cell boolean settings, refreshing the fixture's public cache before cross-process changes. */
final class EditAppSettings {
	/** @var array<string,array<string,array{had:bool,value:bool}>> Original typed app values, never credentials. */
	private array $saved=[];
	/** Saves a cell boolean exactly once and writes it with the public appconfig API.
	 * @param string $app Known fixture app.
	 * @param string $key Known boolean key.
	 * @param bool $value Fixture value.
	 * @return void
	 */
	public function set(string $app,string $key,bool $value):void{
		$config=\OCP\Server::get(\OCP\IAppConfig::class);$config->clearCache();
		$this->saved[$app][$key]??=['had'=>$config->hasKey($app,$key),'value'=>$config->getValueBool($app,$key,false)];$config->setValueBool($app,$key,$value);
	}
	/** Restores saved typed values/presence, never clears cache from a product read path.
	 * @return void
	 */
	public function restore():void{
		$config=\OCP\Server::get(\OCP\IAppConfig::class);
		foreach($this->saved as $app=>$keys){foreach($keys as $key=>$prior){$config->clearCache();if($prior['had']){$config->setValueBool($app,$key,$prior['value']);}else{$config->deleteKey($app,$key);}}}
		$config->clearCache();
	}
}
