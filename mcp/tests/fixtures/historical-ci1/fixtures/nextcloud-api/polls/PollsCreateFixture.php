<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Static SHA-pinned creation/persistence source evidence; never executes upstream classes or migrations. */
final class PollsCreateFixture {
	/** Per-pin applicable schema, determined by the migration's actual import. */
	private const SCHEMA = ['8.6.3'=>['V5','Version080307Date20250826231102.php'],
		'9.0.7'=>['V9','Version090000Date20260302212000.php'],'9.1.4'=>['V10','Version090000Date20260302212000.php'],
		'9.3.0'=>['V11','Version090200Date20260822163500.php']];
	/**
	 * Extracts source methods with the existing token-balanced parser and exact schema/default declarations.
	 * @param string $archives offline verified archive directory
	 * @return void
	 */
	public static function run(string $archives): void {
		$pins = json_decode(file_get_contents(__DIR__.'/polls-sources.json'),true,512,JSON_THROW_ON_ERROR);
		$records = [];
		foreach ($pins as $pin) {
			$v = $pin['polls']['version']; if (isset($records[$v])) { continue; }
			$file = $archives.'/v'.$v.'.tar.gz';
			if (hash_file('sha256',$file) !== $pin['polls']['sha256']) { throw new RuntimeException('Unpinned Polls archive'); }
			$archive = new PharData($file); $prefix = null;
			foreach (new RecursiveIteratorIterator($archive) as $entry) {
				if (str_ends_with($entry->getPathname(),'/lib/Service/PollService.php')) {
					$prefix = substr($entry->getPathname(),0,-strlen('lib/Service/PollService.php')); break;
				}
			}
			if ($prefix === null) { throw new RuntimeException('Missing source root'); }
			[$generation,$migration] = self::SCHEMA[$v];
			$map = ['Service/PollService.php'=>['__construct','add'], 'Service/OptionService.php'=>['add','get','getPoll'],
				'Db/Poll.php'=>['getDescription'], 'Db/Option.php'=>['__construct','setFromSimpleOption','getPollOptionText'],
				'Model/SimpleOption.php'=>['fromArray'], 'Model/Settings/AppSettings.php'=>['getPollCreationAllowed','getBooleanSetting'],
				'Listener/BaseListener.php'=>['handle'],'Service/ActivityService.php'=>['publishActivityEvent'],
				'Event/BaseEvent.php'=>['__construct'], 'Db/'.$generation.'/TableManager.php'=>['createTable'],
				'Migration/'.$migration=>['changeSchema']];
			if ($v !== '8.6.3') {
				$map['Db/Poll.php'][] = 'getTimezoneName'; $map['Db/Option.php'][] = 'setPoll'; $map['Db/Option.php'][] = 'setText';
				$map['Model/DateTimeImmutable.php'] = ['__construct']; $map['Model/DateInterval.php'] = ['__construct'];
			}
			$methods=[]; $hashes=[];
			foreach ($map as $name=>$names) {
				$source = file_get_contents($prefix.'lib/'.$name);$hashes[$name] = hash('sha256',$source);
				foreach ($names as $method) { $methods[$name.'::'.$method] = PollsFixtureGenerator::method($source,$method); }
			}
			$schema = file_get_contents($prefix.'lib/Migration/'.$generation.'/TableSchema.php');
			$tables = strpos($schema,'public const TABLES');
			if ($tables === false) { throw new RuntimeException('Missing table definition'); }
			$start = strpos($schema,'Poll::TABLE => [',$tables); $end = strpos($schema,'Option::TABLE => [',$start);
			$poll = file_get_contents($prefix.'lib/Db/Poll.php');
			$records[$v] = ['sha256'=>$pin['polls']['sha256'],'generation'=>$generation,'migration'=>$migration,
				'migrationSource'=>file_get_contents($prefix.'lib/Migration/'.$migration),'schemaSHA256'=>hash('sha256',$schema),
				'pollColumns'=>substr($schema,$start,$end-$start),'pollSource'=>$poll,'methods'=>$methods,'sourceSHA256'=>$hashes];
			echo 'Polls '.$v.' creation/persistence/readback source evidence generated'.PHP_EOL;
		}
		$records['_core'] = [];
		foreach (['32','33','34','35'] as $nc) {
			foreach (['Entity'=>['setter','getter','__call','fromRow'],'QBMapper'=>['insert','update','mapRowToEntity']] as $name=>$methods) {
				$source = file_get_contents($archives.'/server-'.$nc.'.0.0-'.$name.'.php');
				$record=['sha256'=>hash('sha256',$source),'methods'=>[]];
				foreach ($methods as $method) { $record['methods'][$method]=PollsFixtureGenerator::method($source,$method); }
				$records['_core'][$nc][$name]=$record;
			}
		}
		file_put_contents(__DIR__.'/polls-create.json',json_encode($records,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
	}
}
