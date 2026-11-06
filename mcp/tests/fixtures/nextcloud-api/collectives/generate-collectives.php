<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\{FixtureFile,LibScanner,SourceParser};

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * Regenerates the Collectives surfaces of tests/fixtures/nextcloud-api/<major>.php and collectives-behavior.json from
 * the SHA-verified official archives of collectives-sources.json. Nothing is executed: classes are parsed, and the
 * native methods whose behaviour the module relies on are copied verbatim as evidence.
 *
 * Usage: php generate-collectives.php <directory with v<version>.tar.gz and server-<version>-Entity.php>
 */
final class CollectivesFixtureGenerator {
	/** Native methods recorded per release, by file under lib/. */
	public const METHODS = [
		'Service/PageService.php' => ['verifyEditPermissions', 'getPageFile', 'getFolder', 'getPagesFromFolder', 'findAll', 'createBase', 'create', 'move',
			'find', 'getPageByFile', 'getParentPageId', 'getIndexPageFile'],
		'Service/CollectiveServiceBase.php' => ['getCollective'],
		'Service/CollectiveService.php' => ['getCollectives'],
		'Service/SearchService.php' => ['searchCollective'],
		'Fs/UserFolderHelper.php' => ['getUserFolderSetting', 'get'],
		'Listeners/NodeWrittenListener.php' => ['handle'],
		'Model/PageInfo.php' => ['jsonSerialize'],
		'Db/Collective.php' => ['canEdit', 'jsonSerialize'],
	];

	/**
	 * @param string $archives directory of the pinned archives
	 * @return void writes the five fixtures and the behaviour record
	 */
	public static function run(string $archives): void {
		$pins = json_decode(file_get_contents(__DIR__ . '/collectives-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$behavior = [];
		foreach ($pins as $major => $pin) {
			$version = $pin['collectives']['version'];
			$tree = sys_get_temp_dir() . '/mcp-collectives-fixture-' . bin2hex(random_bytes(5));
			mkdir($tree . '/unpack', recursive: true);
			try {
				self::verify($archives . '/v' . $version . '.tar.gz', $pin['collectives']['sha256']);
				foreach ($pin['server']['files'] as $path => $sha) {
					$file = $archives . '/server-' . $pin['server']['version'] . '-' . basename($path);
					self::verify($file, $sha);
					@mkdir($tree . '/' . dirname($path), recursive: true);
					copy($file, $tree . '/' . $path);
				}
				(new PharData($archives . '/v' . $version . '.tar.gz'))->extractTo($tree . '/unpack');
				$roots = glob($tree . '/unpack/*', GLOB_ONLYDIR);
				if (count($roots) !== 1) {
					throw new RuntimeException('Expected one app root');
				}
				mkdir($tree . '/apps');
				rename($roots[0], $tree . '/apps/collectives');
				$app = $tree . '/apps/collectives';
				$xml = simplexml_load_file($app . '/appinfo/info.xml');
				if ((string)$xml->version !== $version) {
					throw new RuntimeException('Source version mismatch');
				}
				$fixtureFile = dirname(__DIR__) . '/' . $major . '.php';
				$fixture = require $fixtureFile;
				$parser = new SourceParser($tree);
				foreach (array_keys((new LibScanner(dirname(__DIR__, 4) . '/lib'))->classes()) as $class) {
					if (str_starts_with($class, 'OCA\\Collectives\\')) {
						$fixture['classes'][$class] = $parser->surface($class) ?? throw new RuntimeException('Missing ' . $class);
					}
				}
				ksort($fixture['classes']);
				$fixture['collectivesSources'] = $pin;
				$old = file_get_contents($fixtureFile);
				FixtureFile::write($fixtureFile, substr($old, 0, strpos($old, 'return [')), $fixture);
				$methods = [];
				foreach (self::METHODS as $file => $names) {
					$source = file_get_contents($app . '/lib/' . $file);
					foreach ($names as $name) {
						$methods[$file . '::' . $name] = self::method($source, $name);
					}
				}
				$behavior[$version] = ['sha256' => $pin['collectives']['sha256'],
					'nextcloud' => ['min' => (int)$xml->dependencies->nextcloud['min-version'], 'max' => (int)$xml->dependencies->nextcloud['max-version']],
					'methods' => $methods];
				echo 'NC' . $major . ' Collectives ' . $version . ' surfaces and methods generated' . PHP_EOL;
			} finally {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($files as $file) {
					$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
				}
				rmdir($tree);
			}
		}
		ksort($behavior);
		file_put_contents(__DIR__ . '/collectives-behavior.json', json_encode($behavior, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
	}

	/**
	 * One native method, copied verbatim from `function` to its closing brace, without running it.
	 * @param string $source PHP source
	 * @param string $name method name
	 * @return string the method
	 */
	public static function method(string $source, string $name): string {
		$tokens = token_get_all($source);
		foreach ($tokens as $i => $token) {
			if (!is_array($token) || $token[0] !== T_FUNCTION) {
				continue;
			}
			$j = $i + 1;
			while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}
			if (!is_array($tokens[$j] ?? null) || $tokens[$j][1] !== $name) {
				continue;
			}
			$text = '';
			$depth = 0;
			for ($k = $i; $k < count($tokens); $k++) {
				$text .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
				if ($tokens[$k] === '{' || (is_array($tokens[$k]) && $tokens[$k][0] === T_CURLY_OPEN)) {
					$depth++;
				} elseif ($tokens[$k] === '}' && --$depth === 0) {
					return $text;
				}
			}
		}
		throw new RuntimeException('Missing native method ' . $name);
	}

	/**
	 * @param string $path local source
	 * @param string $expected its pinned SHA-256
	 * @return void
	 */
	private static function verify(string $path, string $expected): void {
		if (!is_file($path) || hash_file('sha256', $path) !== $expected) {
			throw new RuntimeException('Source SHA mismatch: ' . $path);
		}
	}
}

if (count($argv) !== 2) {
	throw new RuntimeException('Usage: generate-collectives.php <verified archives>');
}
CollectivesFixtureGenerator::run($argv[1]);
