<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{SchemaServices, SchemaColumnInput, SchemaViewInput};
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use PHPUnit\Framework\Attributes\{DataProvider, RunInSeparateProcess, PreserveGlobalState};
use PHPUnit\Framework\TestCase;

/** Executes the real upstream DTO/model sources, isolated by pinned release. No network or containers. */
final class NativeFactoriesTest extends TestCase {
	/** @return iterable<string,array> Three active source pins for the remaining cells and the approved archive. */
	public static function pins(): iterable { foreach (['2.2.2', '2.3.2', '2.4.0'] as $v) yield $v => [$v]; }
	/**
	 * @param string $pin Official source pin.
	 * @return void
	 */
	#[DataProvider('pins')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testFusedColumnDtoAndEmptyViewListsUseTheActualNativeModels(string $pin): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fixture['factorySources'][$pin] as $path => $source) {
			if (isset($fixture['sources'][$pin]['files'][$path])) $this->assertSame($fixture['sources'][$pin]['files'][$path], hash('sha256', $source));
			eval(substr($source, 5));
		}
		foreach (\OCA\Mcp\Modules\Tables\Integration\SchemaApiShape::members($pin) as $member) {
			if (class_exists($member[0], false)) $this->assertTrue(\OCA\Mcp\Modules\Tables\Integration\SchemaApiShape::matches($member), $member[0] . '::' . $member[1]);
		}
		foreach (['OCA\\Tables\\Errors\\NotFoundError', 'OCA\\Tables\\Errors\\PermissionError'] as $class) {
			try { \OCA\Mcp\Modules\Tables\Integration\SchemaNativeCalls::run(static function () use ($class): never { throw new $class('private target'); }); $this->fail('Native refusal escaped'); }
			catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $error) { $this->assertSame(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound(), $error->getMessage()); }
		}
		$services = $this->createMock(SchemaServices::class);
		$services->method('version')->willReturn($pin);
		$circles = new class {
			public bool $allowed = false;
			public array $calls = [];
			/**
			 * @param string $id Exact team ID.
			 * @param string $uid Caller.
			 * @return bool Actor eligibility.
			 */
			public function circleExists(string $id, string $uid): bool { $this->calls[] = [$id, $uid]; return $this->allowed; }
		};
		$services->method('get')->willReturnCallback(static function (string $name) use ($circles): object {
			if ($name === 'circles') return $circles;
			$class = 'OCA\\Tables\\Validation\\ColumnDtoValidator'; return new $class();
		});
		$identities = $this->createMock(UsergroupWhitelist::class);
		$identities->method('entries')->willReturnCallback(static fn ($column, $value): array => $value);
		$factory = new SchemaColumnInput($services, $identities);
		try { $factory->of(['title' => 'Changed'], ['type' => 'relation', 'subtype' => ''], 'alice', $pin); $this->fail('Relation edit accepted'); }
		catch (\InvalidArgumentException $error) { $this->assertSame('Invalid argument: column_id', $error->getMessage()); }
		$result = $factory->of(['title' => 'Changed'], ['id' => 3, 'tableId' => 1, 'title' => 'Old', 'type' => 'number', 'subtype' => '',
			'description' => 'Keep', 'numberDefault' => 2, 'numberMin' => 1, 'numberMax' => 5, 'numberDecimals' => 2], 'alice', $pin);
		$this->assertSame('Keep', $result['dto']->getDescription());
		$this->assertSame(1.0, $result['dto']->getNumberMin());
		$this->assertNull($result['dto']->getType());
		$this->assertNull($result['dto']->getSubtype());
		if (in_array($pin, ['2.2.2', '2.3.2', '2.4.0'], true)) {
			$team = ['title' => 'Team', 'type' => 'usergroup', 'subtype' => '', 'options' => [
				['name' => 'usergroupDefault', 'value' => [['id' => 'team-1', 'type' => 2]]], ['name' => 'usergroupSelectTeams', 'value' => true]]];
			try { $factory->of($team, null, 'alice', $pin); $this->fail('Ineligible team accepted'); }
			catch (\InvalidArgumentException) { $this->assertSame(['team-1', 'alice'], end($circles->calls)); }
			$circles->allowed = true; $allowed = $factory->of($team, null, 'alice', $pin);
			$this->assertSame([['id' => 'team-1', 'type' => 2]], json_decode($allowed['dto']->getUsergroupDefault(), true));
		}
		if (in_array($pin, ['2.2.2', '2.3.2', '2.4.0'], true)) {
			$text = ['title' => 'Old', 'type' => 'text', 'subtype' => 'line', 'textMaxLength' => -1];
			try { $factory->of(['title' => 'Changed'], $text, 'alice', $pin); $this->fail('Legacy unlimited sentinel accepted on modern profile'); }
			catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure) { $this->addToAssertionCount(1); }
		}
		foreach (['link' => 'url,files', 'line' => '^[A-Z]+$'] as $subtype => $pattern) {
			$preserved = $factory->of(['title' => 'Changed'], ['title' => 'Old', 'type' => 'text', 'subtype' => $subtype, 'textAllowedPattern' => $pattern], 'alice', $pin);
			$this->assertSame($pattern, $preserved['dto']->getTextAllowedPattern());
		}
		$shareService = new class {
			public array $captured = [];
			/**
			 * @param mixed ...$args Native share arguments.
			 * @return object Native entity.
			 */
			public function create(...$args): object { $this->captured = $args; return new class { /** @return int Native ID. */ public function getId(): int { return 42; } }; }
		};
		$shareServices = $this->createMock(SchemaServices::class); $shareServices->method('get')->willReturn($shareService);
		$writer = new \OCA\Mcp\Modules\Tables\Integration\SchemaNativeWriter($shareServices);
		$bits = ['read' => false, 'create' => true, 'update' => false, 'delete' => true, 'manage' => true];
		$this->assertSame(42, $writer->shareNode(['arguments' => ['node_id' => 3, 'node_type' => 'view', 'recipient' => ['id' => 'bob', 'type' => 'user'], 'permissions' => $bits], 'changes' => [], 'profile' => $pin], 'alice'));
		if (in_array($pin, ['1.0.0', '2.0.0'], true)) $this->assertSame([3, 'view', 'bob', 'user', false, true, false, true, true, 0], $shareService->captured);
		else foreach ($bits as $bit => $expected) $this->assertSame($expected, $shareService->captured[0]->{'getPermission' . ucfirst($bit)}());
		$view = SchemaViewInput::of(['filter' => [], 'sort' => [], 'columnSettings' => []], $pin);
		if ($pin === '1.0.0') $this->assertSame(['filter' => '[]', 'sort' => '[]', 'columnSettings' => '[]'], $view);
		else {
			$detail = iterator_to_array($view->updateDetail(), false);
			$this->assertCount(3, $detail);
			foreach ($detail as $value) $this->assertSame([], $value->jsonSerialize());
			$description = SchemaViewInput::of(['description' => '0'], $pin);
			$this->assertSame(in_array($pin, ['2.3.2', '2.4.0'], true) ? 1 : 0, iterator_count($description->updateDetail()));
		}
	}
}
