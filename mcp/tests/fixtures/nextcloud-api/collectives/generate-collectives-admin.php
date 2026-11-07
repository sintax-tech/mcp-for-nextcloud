<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;

require __DIR__ . '/../../../../vendor/autoload.php';

/**
 * Writes what creating a collective through MCP (C04) rests on, from the SHA-pinned official archives only:
 * - the surface of the native classes it names beyond the page tools (FoundingSurface) into ../<major>.php;
 * - collectives-admin-sources.json: the archive SHA-256, which must equal the pins of collectives-sources.json and
 *   recipient-sources.json, and the SHA-256 of every file a fact is read from;
 * - collectives-admin-behavior.json: the method bodies and file facts CollectivesAdminContractTest asserts.
 * It never touches the read-chain records of generate-collectives.php.
 * Usage: php generate-collectives-admin.php <directory with v<collectives>.tar.gz and circles-<circles>.tar.gz>
 */
final class CollectivesAdminFixtureGenerator {
	/** Native classes added to the API fixtures. */
	private const CLASSES = ['OCA\\Collectives\\Mount\\CollectiveFolderManager', 'OCA\\Circles\\CirclesManager',
		'OCA\\Circles\\Model\\FederatedUser', 'OCA\\Circles\\Model\\Probes\\CircleProbe'];
	/** @var array<string, list<string>> Collectives methods recorded, by file under lib/ */
	private const COLLECTIVES = [
		'Service/CollectiveService.php' => ['createCollective'],
		'Service/CircleHelper.php' => ['existsCircle', 'createCircle', 'flagCircleAsAppManaged', 'startSession', 'startSuperSession'],
		'Fs/NodeHelper.php' => ['sanitiseFilename'],
		'Service/PageService.php' => ['getPageByFile'],
		'Mount/CollectiveFolderManager.php' => ['getRootPath', 'getRootFolder', 'getFolder', 'initializeFolder'],
		'Controller/CollectiveController.php' => ['create'],
	];
	/** @var array<string, list<string>> Circles methods recorded, by file under lib/ */
	private const CIRCLES = [
		'CirclesManager.php' => ['startSession', 'stopSession', 'startSuperSession', 'getCircles', 'createCircle'],
		'Service/FederatedUserService.php' => ['getLocalFederatedUser', 'getGroupCircle', 'setMemberPatron', 'getCurrentEntity'],
		'FederatedItems/SingleMemberAdd.php' => ['verify', 'generateMember', 'manageMemberStatus'],
		'FederatedItems/MemberLevel.php' => ['verify', 'verifyMemberLevel', 'verifySwitchOwner'],
		'FederatedItems/MemberRemove.php' => ['verify'],
		'Service/NotificationService.php' => ['notificationInvited'],
		'Service/CircleService.php' => ['create', 'cleanCircleName', 'confirmCircleNotFull'],
		'Listeners/Files/MemberAddedSendMail.php' => ['handle'],
	];
	/** @var array<string, list<string>> native constants recorded, by class file under lib/ */
	private const CONSTANTS = ['Model/Member.php' => ['LEVEL_NONE', 'LEVEL_MEMBER', 'LEVEL_MODERATOR', 'LEVEL_ADMIN', 'LEVEL_OWNER', 'TYPE_USER',
		'TYPE_GROUP', 'STATUS_INVITED', 'STATUS_REQUEST', 'STATUS_MEMBER', 'STATUS_BLOCKED'], 'Model/Circle.php' => ['CFG_SINGLE', 'CFG_PERSONAL', 'CFG_INVITE']];
	/** The notice Collectives returns when it adopts an existing team. */
	public const ADOPTION = 'Created collective "%s" for existing team.';

	/**
	 * @param string $archives directory of the pinned archives
	 * @return void writes the five fixtures, the pins and the behaviour record
	 */
	public static function run(string $archives): void {
		ini_set('memory_limit', '2G');
		$collectivesPins = json_decode((string)file_get_contents(__DIR__ . '/collectives-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$circlesPins = json_decode((string)file_get_contents(dirname(__DIR__) . '/recipient-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$sources = [];
		$behavior = ['collectives' => [], 'circles' => []];
		foreach ($collectivesPins as $major => $pin) {
			$version = $pin['collectives']['version'];
			$circles = $circlesPins[$major]['circles'];
			$tree = sys_get_temp_dir() . '/mcp-collectives-admin-' . bin2hex(random_bytes(5));
			mkdir($tree . '/apps', recursive: true);
			try {
				$collectivesApp = self::unpack($archives . '/v' . $version . '.tar.gz', $pin['collectives']['sha256'], $tree, 'collectives', $version);
				$circlesApp = self::unpack($archives . '/circles-' . $circles['version'] . '.tar.gz', $circles['sha256'], $tree, 'circles',
					$circles['manifestVersion'] ?? $circles['version']);
				$fixtureFile = dirname(__DIR__) . '/' . $major . '.php';
				$fixture = require $fixtureFile;
				$parser = new SourceParser($tree);
				foreach (self::CLASSES as $class) {
					$fixture['classes'][$class] = $parser->surface($class) ?? throw new RuntimeException('Missing ' . $class);
				}
				ksort($fixture['classes']);
				$old = (string)file_get_contents($fixtureFile);
				FixtureFile::write($fixtureFile, substr($old, 0, (int)strpos($old, 'return [')), $fixture);
				$files = [];
				$collectivesRecord = self::record($collectivesApp, self::COLLECTIVES, $files) + ['l10n' => self::adoption($collectivesApp),
					'adminGate' => self::adminGate($collectivesApp, $files)];
				$circlesRecord = self::record($circlesApp, self::CIRCLES, $files) + ['constants' => self::constants($circlesApp, $files),
					'userSingleId' => self::userSingleId($circlesApp, $files),
					'teamFolderListener' => is_file($circlesApp . '/lib/Listeners/TeamFolderLifecycleListener.php'),
				];
				$behavior['collectives'][$version] ??= $collectivesRecord;
				$behavior['circles'][$circles['version']] ??= $circlesRecord;
				$sources[$major] = ['collectives' => ['version' => $version, 'sha256' => $pin['collectives']['sha256']],
					'circles' => ['version' => $circles['version'], 'sha256' => $circles['sha256']], 'files' => $files];
			} finally {
				self::remove($tree);
			}
		}
		file_put_contents(__DIR__ . '/collectives-admin-sources.json', json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		file_put_contents(__DIR__ . '/collectives-admin-behavior.json', json_encode($behavior, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
	}

	/**
	 * @param string $archive the official archive
	 * @param string $sha its pinned SHA-256
	 * @param string $tree the tree to unpack under apps/
	 * @param string $app app id
	 * @param string $version the version its info.xml must declare
	 * @return string the app root
	 */
	private static function unpack(string $archive, string $sha, string $tree, string $app, string $version): string {
		if (!is_file($archive) || hash_file('sha256', $archive) !== $sha) {
			throw new RuntimeException('Source SHA mismatch: ' . $archive);
		}
		$unpack = $tree . '/unpack-' . $app;
		mkdir($unpack);
		(new PharData($archive))->extractTo($unpack);
		$roots = glob($unpack . '/*', GLOB_ONLYDIR) ?: [];
		if (count($roots) !== 1) {
			throw new RuntimeException('Expected one app root in ' . $archive);
		}
		rename($roots[0], $tree . '/apps/' . $app);
		if ((string)simplexml_load_file($tree . '/apps/' . $app . '/appinfo/info.xml')->version !== $version) {
			throw new RuntimeException('Source version mismatch: ' . $archive);
		}
		return $tree . '/apps/' . $app;
	}

	/**
	 * @param string $app app root
	 * @param array<string, list<string>> $methods methods to record, by file under lib/
	 * @param array<string, string> $files SHA-256 of every file read, by app-relative path; filled here
	 * @return array{methods: array<string, string>} the method bodies, `file::method`
	 */
	private static function record(string $app, array $methods, array &$files): array {
		$found = [];
		foreach ($methods as $file => $names) {
			$path = $app . '/lib/' . $file;
			$files[basename($app) . '/lib/' . $file] = hash_file('sha256', $path);
			$source = (string)file_get_contents($path);
			foreach ($names as $name) {
				$found[$file . '::' . $name] = self::method($source, $name);
			}
		}
		if (is_file($app . '/lib/Service/EmojiHelper.php')) {
			$files[basename($app) . '/lib/Service/EmojiHelper.php'] = hash_file('sha256', $app . '/lib/Service/EmojiHelper.php');
			$found['Service/EmojiHelper.php'] = (string)file_get_contents($app . '/lib/Service/EmojiHelper.php');
		}
		return ['methods' => $found];
	}

	/**
	 * @param string $app the Collectives root
	 * @return array{files:int, empty:list<string>} how many translations carry the adoption notice, and which are empty
	 */
	private static function adoption(string $app): array {
		$count = 0;
		$empty = [];
		foreach (glob($app . '/l10n/*.json') ?: [] as $file) {
			$translations = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['translations'] ?? [];
			if (array_key_exists(self::ADOPTION, $translations)) {
				$count++;
				foreach ((array)$translations[self::ADOPTION] as $text) {
					if (trim((string)$text) === '') {
						$empty[] = basename($file);
					}
				}
			}
		}
		return ['files' => $count, 'empty' => $empty];
	}

	/**
	 * @param string $app the Circles root
	 * @param array<string, string> $files SHA-256 of every file read; filled here
	 * @return array<string, int|string> the native constants the member tools rely on, `Class::NAME` => value
	 */
	private static function constants(string $app, array &$files): array {
		$found = [];
		foreach (self::CONSTANTS as $file => $names) {
			$files['circles/lib/' . $file] = hash_file('sha256', $app . '/lib/' . $file);
			$source = (string)file_get_contents($app . '/lib/' . $file);
			foreach ($names as $name) {
				if (preg_match('/const ' . $name . " = ('([^']*)'|(-?\\d+));/", $source, $m) !== 1) {
					throw new RuntimeException('Missing constant ' . $name);
				}
				$found[basename($file, '.php') . '::' . $name] = ($m[3] ?? '') !== '' ? (int)$m[3] : $m[2];
			}
		}
		return $found;
	}

	/**
	 * @param string $app the Collectives root
	 * @param array<string, string> $files SHA-256 of every file read; filled here
	 * @return string the getters of the collectives store that gate "Manage members" in the Collectives interface
	 */
	private static function adminGate(string $app, array &$files): string {
		$path = $app . '/src/stores/collectives.js';
		$files['collectives/src/stores/collectives.js'] = hash_file('sha256', $path);
		$source = (string)file_get_contents($path);
		return preg_match('/isCollectiveAdmin: \\(\\) => \\(collective\\) => \\{[^}]*\\}/', $source, $m) === 1 ? $m[0] : '';
	}

	/**
	 * @param string $app the Circles root
	 * @param array<string, string> $files SHA-256 of every file read; filled here
	 * @return string|null the value of ConfigLexicon::USER_SINGLE_ID, null when the release has no such constant
	 */
	private static function userSingleId(string $app, array &$files): ?string {
		$path = $app . '/lib/ConfigLexicon.php';
		if (!is_file($path)) {
			return null;
		}
		$files['circles/lib/ConfigLexicon.php'] = hash_file('sha256', $path);
		return preg_match("/const USER_SINGLE_ID = '([^']+)'/", (string)file_get_contents($path), $m) === 1 ? $m[1] : null;
	}

	/**
	 * @param string $source PHP source
	 * @param string $name a method name
	 * @return string the whole method, from `function` to its closing brace
	 */
	private static function method(string $source, string $name): string {
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

	/** @param string $path a directory to delete @return void */
	private static function remove(string $path): void {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($path);
	}
}

if (count($argv) !== 2) {
	throw new RuntimeException('Usage: generate-collectives-admin.php <verified archives>');
}
CollectivesAdminFixtureGenerator::run($argv[1]);
