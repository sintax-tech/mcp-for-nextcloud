<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Fresh real FO2 service graph; deliberate session transitions are fixture-only, never a product repair mechanism. */
require dirname(__DIR__,2).'/bootstrap.php';
set_exception_handler(static function(\Throwable $error):void{fwrite(STDERR,$error::class.': '.$error->getMessage()."\n");exit(1);});
use OCA\Mcp\Tests\Integration\Forms\{FormsScene,FormsResultsScene};
use OCP\Server;
$scenario=$argv[1];$session=Server::get(\OCP\IUserSession::class);$users=Server::get(\OCP\IUserManager::class);Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
$session->setUser($scenario==='other'?$users->get(FormsScene::OWNER):(in_array($scenario,['null','lazy'],true)?null:$users->get(FormsScene::READER)));
$lazy=$scenario==='lazy';if($lazy){\OC\AppFramework\Utility\SimpleContainer::$useLazyObjects=true;}
$service=Server::get(\OCA\Forms\Service\FormsService::class);
$uninitialized=$lazy&&(new \ReflectionClass($service))->isUninitializedLazyObject($service);
if($scenario!=='cold'&&!$lazy){$f=new \OCA\Forms\Db\Form();$f->setId(0);$f->setOwnerId(FormsScene::OWNER);$f->setAccess(['permitAllUsers'=>false,'showToAllUsers'=>false]);$service->getPermissions($f);}
if(Server::get(\OCA\Forms\Db\ShareMapper::class)->findByForm(0)!==[]){throw new RuntimeException('Sentinel premise failed');}
$session->setUser($users->get(FormsScene::READER));$accepted=true;
try{Server::get(\OCA\Mcp\Tools\Forms\Results\ResultsReadFlow::class)->run(['form_id'=>FormsResultsScene::read()['form']],FormsScene::READER,true);}
catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){$accepted=false;}
if($accepted===in_array($scenario,['null','other'],true)){throw new RuntimeException('Unexpected captured FO2 context result');}
$mappers=[];if($accepted){foreach(['SubmissionMapper','AnswerMapper','QuestionMapper','OptionMapper']as$name){$mappers[]=get_class(Server::get('OCA\\Forms\\Db\\'.$name));}}
echo json_encode(['scenario'=>$scenario,'accepted'=>$accepted,'sameNativeInstance'=>Server::get(\OCA\Forms\Service\FormsService::class)===$service,'initiallyUninitialized'=>$uninitialized,'nativeMappers'=>$mappers],JSON_THROW_ON_ERROR)."\n";
