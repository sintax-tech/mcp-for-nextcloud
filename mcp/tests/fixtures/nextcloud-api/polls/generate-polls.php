<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\{FixtureFile,LibScanner,SourceParser};

require __DIR__ . '/../../../../vendor/autoload.php';
require __DIR__ . '/PollsContentFixtureMethods.php';

/** Regenerates API surfaces and the source-method evidence from SHA-verified official archives. */
final class PollsFixtureGenerator {
	/**
	 * Extracts one exact native method without executing it; balances only PHP punctuation, not string contents.
	 * @param string $source PHP source
	 * @param string $name method name
	 * @return string full method from function through its closing brace
	 */
	public static function method(string $source, string $name): string {
		$tokens = token_get_all($source);
		for ($i=0;$i<count($tokens);$i++) {
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
				continue;
			}
			$j=$i+1;
			while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}
			if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][1] !== $name) {
				continue;
			}
			$text='';$depth=0;$opened=false;
			for ($j=$i;$j<count($tokens);$j++) {
				$token=$tokens[$j];$text.=is_array($token)?$token[1]:$token;
				if ($token === '{') {$depth++;$opened=true;}
				if ($token === '}' && --$depth === 0 && $opened) {return $text;}
			}
		}
		throw new RuntimeException('Missing native method '.$name);
	}

	/**
	 * Generates all five server fixtures and four version behavior records, preserving prior fixture sections.
	 * @param string $archives directory of pinned archives and core Entity/QBMapper files
	 * @return void
	 */
	public static function run(string $archives): void {
		$pins=json_decode(file_get_contents(__DIR__.'/polls-sources.json'),true,512,JSON_THROW_ON_ERROR);
		$behavior=[];
		foreach ($pins as $major=>$pin) {
			$version=$pin['polls']['version'];
			$archive=$archives.'/v'.$version.'.tar.gz';
			self::verify($archive,$pin['polls']['sha256']);
			$tree=sys_get_temp_dir().'/mcp-po1-fixture-'.bin2hex(random_bytes(5));
			mkdir($tree,recursive:true);
			try {
				foreach ($pin['server']['files'] as $path=>$sha) {
					$file=$archives.'/server-'.$pin['server']['version'].'-'.basename($path);
					self::verify($file,$sha);
					@mkdir($tree.'/'.dirname($path),recursive:true);
					copy($file,$tree.'/'.$path);
				}
				mkdir($tree.'/unpack');
				(new PharData($archive))->extractTo($tree.'/unpack');
				$roots=glob($tree.'/unpack/*',GLOB_ONLYDIR);
				if (count($roots)!==1) {throw new RuntimeException('Expected one app root');}
				mkdir($tree.'/apps');rename($roots[0],$tree.'/apps/polls');
				$app=$tree.'/apps/polls';
				$xml=simplexml_load_file($app.'/appinfo/info.xml');
				if ((string)$xml->version!==$version) {throw new RuntimeException('Source version mismatch');}
				$fixtureFile=dirname(__DIR__).'/'.$major.'.php';
				$fixture=require $fixtureFile;
				$parser=new SourceParser($tree);
				$scanner=new LibScanner(dirname(__DIR__,4).'/lib');
				foreach (array_keys($scanner->classes()) as $class) {
					if (!str_starts_with($class,'OCA\\Polls\\')) {continue;}
					$surface=$parser->surface($class);
					if ($surface===null) {throw new RuntimeException('Missing '.$class);}
					$fixture['classes'][$class]=$surface;
				}
				ksort($fixture['classes']);$fixture['pollsSources']=$pin;
				$old=file_get_contents($fixtureFile);
				$header=substr($old,0,strpos($old,'return ['));
				FixtureFile::write($fixtureFile,$header,$fixture);
				$methods=[];
				foreach ([
					...PollsContentFixtureMethods::MAP,
					'UserSession.php'=>['cleanSession','getCurrentUser','getCurrentUserId'],
					'Service/PollService.php'=>['listPolls','get'],
					'Db/PollMapper.php'=>['findForMe','buildQuery'],
					'Db/Poll.php'=>['getIsAllowed','getAllowAccessPoll','getAllowEditPoll','getVoteUrl','jsonSerialize',PollsContentFixtureMethods::expiration($version)],
					'Service/OptionService.php'=>['__construct','list','getPoll','filterBookedUp'],
					'Db/Option.php'=>['jsonSerialize','getIsLocked','getIsLockedByOptionLimit'],
				] as $file=>$names) {
					$source=file_get_contents($app.'/lib/'.$file);
					foreach ($names as $name) {$methods[$file.'::'.$name]=self::method($source,$name);}
				}
				$behavior[$version]=['sha256'=>$pin['polls']['sha256'],'nextcloud'=>['min'=>(int)$xml->dependencies->nextcloud['min-version'],'max'=>(int)$xml->dependencies->nextcloud['max-version']],'methods'=>$methods];
				echo 'NC'.$major.' Polls'.$version.' native surfaces and methods generated'.PHP_EOL;
			} finally {
				$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($files as $file) {$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}
				rmdir($tree);
			}
		}
		file_put_contents(__DIR__.'/polls-behavior.json',json_encode($behavior,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
	}

	/**
	 * Rejects unpinned bytes before parsing.
	 * @param string $path local source
	 * @param string $expected SHA256
	 * @return void
	 */
	private static function verify(string $path,string $expected): void {
		if (!is_file($path) || hash_file('sha256',$path)!==$expected) {throw new RuntimeException('Source SHA mismatch: '.$path);}
	}
}
if (count($argv)!==2) {throw new RuntimeException('Usage: generate-polls.php <verified archives>');}
PollsFixtureGenerator::run($argv[1]);

require __DIR__ . '/PollsCreateFixture.php';
PollsCreateFixture::run($argv[1]);
