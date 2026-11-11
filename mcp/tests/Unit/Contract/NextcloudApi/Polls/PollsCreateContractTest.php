<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Semantic four-pin source evidence; runtime cells remain separately required. */
final class PollsCreateContractTest extends TestCase {
	/**
	 * Supplies only the four pinned native app records, not the separate core records.
	 * @return iterable<string,array{string,array<string,mixed>}> pin and source record
	 */
	public static function pins(): iterable {
		$all=json_decode(file_get_contents(__DIR__.'/../../../../fixtures/nextcloud-api/polls/polls-create.json'),true,512,JSON_THROW_ON_ERROR);
		foreach(['9.1.4','9.3.0']as$v){yield $v=>[$v,$all[$v]];}
	}
	/**
	 * Guards source-selected migration/schema and the persistence values actually used by a new row.
	 * @param string $version pin
	 * @param array<string,mixed> $r native source
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testPersistedDefaultsAndReadbackAccessors(string $version,array $r): void {
		$gen=['8.6.3'=>'V5','9.0.7'=>'V9','9.1.4'=>'V10','9.3.0'=>'V11'][$version];
		self::assertSame($gen,$r['generation']);
		self::assertStringContainsString('use OCA\\Polls\\Db\\'.$gen.'\\TableManager;',$r['migrationSource']);
		$cols=$r['pollColumns'];
		foreach(['allow_comment'=>'1','allow_maybe'=>'1','hide_booked_up'=>'1','use_no'=>'1','allow_proposals'=>"'disallow'",'misc_settings'=>'null','description'=>'null']as$field=>$value){
			self::assertMatchesRegularExpression("/'".$field."' => [^\\n]*'default' => ".preg_quote($value,'/')."[,\\]]/",$cols);
		}
		self::assertStringContainsString('TableSchema::TABLES[$tableName]',$r['methods']['Db/'.$gen.'/TableManager.php::createTable']);
		self::assertStringContainsString('modifyColumn',$r['methods']['Db/'.$gen.'/TableManager.php::createTable']);
		self::assertStringContainsString("return \$this->description ?? '';",$r['methods']['Db/Poll.php::getDescription']);
		self::assertStringNotContainsString('function getMiscSettings(',$r['pollSource']);
		if($version==='8.6.3'){self::assertStringNotContainsString('function getTimezoneName(',$r['pollSource']);}
		else{self::assertStringContainsString("\$this->timezoneName === ''",$r['methods']['Db/Poll.php::getTimezoneName']);}
	}
	/**
	 * Discriminates native add argument layout, policy, insertion/event ordering, and mutation/duplicate behavior.
	 * @param string $version pin
	 * @param array<string,mixed> $r native source
	 * @return void
	 */
	#[DataProvider('pins')]
	public function testNativeSinksAndFreshValueMechanism(string $version,array $r): void {
		$add=$r['methods']['Service/PollService.php::add'];
		self::assertStringContainsString('getPollCreationAllowed()',$add);
		self::assertTrue(strpos($add,'->insert(')<strpos($add,'->update('));
		self::assertTrue(strpos($add,'->update(')<strpos($add,'new PollCreatedEvent'));
		self::assertStringContainsString("setOwner(\$this->userSession->getCurrentUserId())",$add);
		self::assertStringContainsString("setDescription('')",$add);self::assertStringContainsString('setAllowMaybe(0)',$add);
		$option=$r['methods']['Service/OptionService.php::add'];
		self::assertStringContainsString('PERMISSION_OPTION_ADD',$option);self::assertStringContainsString('if ($voteYes)',$option);
		self::assertTrue(strpos($option,'->insert(')<strpos($option,'new OptionCreatedEvent'));
		if($version==='8.6.3'){
			self::assertStringNotContainsString('$timezoneName',$add);self::assertStringContainsString('$simpleOption->setOrder(',$option);
			self::assertStringContainsString(' || ',$option);self::assertStringContainsString("new SimpleOption(",$r['methods']['Model/SimpleOption.php::fromArray']);
		}else{
			self::assertStringContainsString('?string $timezoneName = null, string $votingVariant',$add);
			self::assertStringContainsString('$newOption->setPoll(',$option);self::assertStringContainsString(' && ',$option);
			self::assertTrue(strpos($option,'->setPoll(')<strpos($option,'->setFromSimpleOption('));
			self::assertStringContainsString('getPollOptionHash()',$r['methods']['Db/Option.php::setText']);
		}
	}
	/** @return void equality setters must not be mistaken for persisted columns, and readback hydration is real */
	public function testCoreOnlyPersistsChangedFieldsAndHydratesPublicGetters(): void {
		$all=json_decode(file_get_contents(__DIR__.'/../../../../fixtures/nextcloud-api/polls/polls-create.json'),true,512,JSON_THROW_ON_ERROR);
		foreach($all['_core']as$major=>$r){
			$setter=$r['Entity']['methods']['setter'];self::assertStringContainsString('$args[0] === $this->$name',$setter);
			self::assertTrue(strpos($setter,'return;')<strpos($setter,'markFieldUpdated'));
			foreach(['insert','update']as$m){self::assertStringContainsString('getUpdatedFields()',$r['QBMapper']['methods'][$m]);}
			self::assertStringContainsString('$instance->setter($prop, [$value])',$r['Entity']['methods']['fromRow']);
			self::assertStringContainsString('$this->getter(',$r['Entity']['methods']['__call']);
		}
	}
}
