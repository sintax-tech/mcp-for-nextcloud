<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

use OCA\Mcp\Tools\Forms\Creation\CreateNativeArguments;
use PHPUnit\Framework\TestCase;

/** Compares the actual production argument maps against every complete SHA-bound native constructor signature. */
final class CreateNativeArgumentsContractTest extends TestCase {
	/** Detects extra or missing real keys without relying on a parallel MCP parameter-name list.
	 * @return void
	 */
	public function testExactNamedArgumentsForEveryPinAndPreservedIdentities(): void {
		foreach (FormsSourcePins::all() as $pin => $sha) {
			$f = json_decode(file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/forms/' . $pin . '.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertSame($sha, $f['sha256']);
			$objects = []; $resolutions = [];
			$get = static function (string $class) use (&$objects, &$resolutions): object {
				$resolutions[] = $class; return $objects[$class] ??= new \stdClass();
			};
			$session = $this->createMock(\OCP\IUserSession::class); $service = new \stdClass(); $mapper = new \stdClass();
			$config = new \stdClass(); $submission = new \stdClass();
			$native = CreateNativeArguments::submission($pin, $get, $session, $service);
			$args = CreateNativeArguments::controller($pin, $get, $session, ['service' => $service, 'mapper' => $mapper], $config, $submission, $native);
			foreach (['OCA\\Forms\\Service\\SubmissionService' => $native, 'OCA\\Forms\\Controller\\ApiController' => $args] as $class => $passed) {
				$source = $f['sources'][str_replace('\\', '/', substr($class, strlen('OCA\\Forms\\')))];
				self::assertSame($source['sha256'], hash('sha256', $source['source']));
				$declared = array_map(static fn (string $name): string => rtrim($name, '?'), $f['classes'][$class]['methods']['__construct']);
				$actual = array_keys($passed); sort($declared); sort($actual);
				self::assertSame($declared, $actual, $pin . ':' . $class . ' exact named argument set');
			}
			self::assertSame($session, $native['userSession']); self::assertSame($session, $args['userSession']);
			self::assertSame($service, $native['formsService']); self::assertSame($service, $args['formsService']);
			self::assertSame($mapper, $args['formMapper']); self::assertSame($config, $args['configService']);
			self::assertSame($submission, $args['submissionService']); self::assertSame('forms', $args['appName']);
			foreach ($native as $key => $value) { if (array_key_exists($key, $args)) { self::assertSame($value, $args[$key], $pin . ':' . $key); } }
			self::assertSame(\OCA\Forms\Db\QuestionMapper::class, $resolutions[0]);
			self::assertSame($pin === '5.3.0' ? \OCA\Forms\Db\ShareMapper::class : \OCP\Security\ISecureRandom::class, $resolutions[array_key_last($resolutions)]);
		}
	}
	/** Prevents the tested maps from becoming disconnected fixtures beside a different factory assembly.
	 * @return void
	 */
	public function testFactoryConsumesTheTestedMaps(): void {
		$source = file_get_contents(__DIR__ . '/../../../../../lib/Tools/Forms/Creation/FormsCreateNativeFactory.php');
		self::assertStringContainsString('$native = CreateNativeArguments::submission($version, $get, $this->session, $read[\'service\']);', $source);
		self::assertStringContainsString('$args = CreateNativeArguments::controller($version, $get, $this->session, $read, $config, $submission, $native);', $source);
		self::assertStringContainsString('new \\OCA\\Forms\\Service\\SubmissionService(...$native)', $source);
		self::assertStringContainsString('new \\OCA\\Forms\\Controller\\ApiController(...$args)', $source);
	}
}
