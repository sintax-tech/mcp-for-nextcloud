<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Fixtures\Polls;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationSurface;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;

require __DIR__ . '/../../../../vendor/autoload.php';

/** Extracts public signatures and semantic method evidence from the four hash-verified official archives. */
final class PollsMutationFixture {
	/** @param string $scratch Verified archives and tree-<major>/apps/polls sources. @return void */
	public static function run(string $scratch): void {
		$pins = json_decode(file_get_contents(__DIR__ . '/polls-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$out = [];
		foreach ([32, 33, 34, 35] as $major) {
			$p = $pins[$major]['polls']; $v = $p['version'];
			if (hash_file('sha256', $scratch . '/v' . $v . '.tar.gz') !== $p['sha256']) { throw new \RuntimeException('Source pin mismatch'); }
			$archived = [];
			foreach (new \RecursiveIteratorIterator(new \PharData($scratch . '/v' . $v . '.tar.gz')) as $file) {
				if ($file->isFile() && preg_match('#/lib/(.*\\.php)$#', $file->getPathname(), $match)) { $archived[$match[1]] = $file->getContent(); }
			}
			$root = $scratch . '/tree-' . $major;
			$parser = new SourceParser($root);
			$classes = array_keys(PollsMutationSurface::methods($major !== 32));
			$classes[] = PollsMutationSurface::USER_MAPPER;
			$classes[] = PollsSurface::OPTION;
			$classes[] = PollsMutationSurface::SHARE;
			$record = ['archive' => $p, 'surfaces' => [], 'files' => [], 'methods' => []];
			$record['settingsSource'] = file_get_contents($root . '/apps/polls/lib/Model/Settings/AppSettings.php');
			$record['shapeSources'] = [];
			foreach (['UserSession', 'Helper/Container', 'Db/EntityWithUser', 'Db/QBMapperWithUser', 'Model/UserBase', 'Model/User/User', 'Model/User/Admin',
				'Model/SimpleOption', 'Model/SentResult', 'Model/DateTimeImmutable', 'Model/DateInterval',
				'Service/PollService', 'Service/OptionService', 'Service/ShareService', 'Db/Poll', 'Db/Option', 'Db/Share',
				'Db/PollMapper', 'Db/OptionMapper', 'Db/ShareMapper', 'Db/UserMapper', 'Model/Settings/AppSettings'] as $path) {
				if ($major === 32 && in_array($path, ['Model/DateTimeImmutable', 'Model/DateInterval'], true)) { continue; }
				$record['shapeSources'][$path] = file_get_contents($root . '/apps/polls/lib/' . $path . '.php');
				if ($record['shapeSources'][$path] !== ($archived[$path . '.php'] ?? null)) { throw new \RuntimeException('Extracted source mismatch'); }
				$record['files'][$path . '.php'] = hash('sha256', $record['shapeSources'][$path]);
			}
			foreach ($classes as $class) {
				$record['surfaces'][$class] = $parser->surface($class) ?? throw new \RuntimeException('Missing native class');
				$path = str_replace('\\', '/', substr($class, strlen('OCA\\Polls\\'))) . '.php';
				$source = file_get_contents($root . '/apps/polls/lib/' . $path);
				if ($source !== ($archived[$path] ?? null)) { throw new \RuntimeException('Extracted source mismatch'); }
				$record['files'][$path] = hash('sha256', $source);
				$names = array_keys(PollsMutationSurface::methods($major !== 32)[$class] ?? []);
				$names = [...$names, ...match ($class) {
					PollsMutationSurface::SHARES => ['sendInvitation', 'createNewShare'],
					PollsMutationSurface::USER_MAPPER => [$major === 32 ? 'getUserObject' : 'createUserObject'],
					'OCA\\Polls\\Model\\Settings\\AppSettings' => ['getBooleanSetting', 'getPublicSharesAllowed'],
					PollsSurface::POLL => ['deserializeArray', 'setAnonymousSafe'],
					PollsSurface::OPTION => ['getOrder'],
					PollsMutationSurface::SHARE => $major === 32 ? ['getType'] : ['getType', 'setFromUserObject'],
					default => [],
				}];
				foreach (array_unique($names) as $name) { $record['methods'][$path . '::' . $name] = self::method($source, $name); }
			}
			$out[$v] = $record;
		}
		file_put_contents(__DIR__ . '/polls-mutation.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	}
	/** @param string $source Native PHP source, never executed. @param string $name Exact method. @return string Original method body and declaration. */
	private static function method(string $source, string $name): string {
		$tokens = token_get_all($source);
		foreach ($tokens as $i => $token) {
			if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
			$j = $i + 1;
			while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
			if (!is_array($tokens[$j] ?? null) || $tokens[$j][1] !== $name) { continue; }
			$text = ''; $depth = 0; $opened = false;
			for ($k = $i; $k < count($tokens); $k++) {
				$t = $tokens[$k]; $text .= is_array($t) ? $t[1] : $t;
				if ($t === '{') { $depth++; $opened = true; }
				if ($t === '}' && --$depth === 0 && $opened) { return $text; }
			}
		}
		throw new \RuntimeException('Native method missing: ' . $name);
	}
}
if (isset($argv[1]) && realpath($argv[0]) === __FILE__) { PollsMutationFixture::run($argv[1]); }
