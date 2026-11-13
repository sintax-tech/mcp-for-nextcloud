<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Files\Presentation\TaggingMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * files_set_tag over the verbatim native DAV pipeline of NC34 and NC35: the plan writes nothing, the confirmed call
 * changes exactly one relation through PUT or DELETE on the relation collection, the native collection decides the
 * permission, and every tag or file that cannot be used gets the same refusal as a missing file, with no write.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SetTagTest extends TestCase {
	private SetTagWorld $world;

	/** @return iterable<string,array{string}> the supported source pins */
	public static function pins(): iterable {
		yield 'NC34 server v34.0.4' => ['34'];
		yield 'NC35 server v35.0.1' => ['35'];
	}

	#[DataProvider('pins')]
	public function testAssigningByNamePlansFirstThenPutsOneRelation(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		$plan = $this->world->tool->preview(['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => true], new ToolContext('alice'));
		self::assertSame(['id' => SetTagWorld::CONTRACT, 'name' => 'Contract'], $plan['tag']);
		self::assertSame(['assign', false, false, false], [$plan['change'], $plan['assigned_now'], $plan['nothing_to_do'], $plan['requiresSharedConfirmation']]);
		self::assertSame([], $this->world->writes, 'the plan writes nothing');
		self::assertSame(['PROPFIND systemtags', 'PROPFIND systemtags-relations/files/42'], $this->world->native->methods);

		$receipt = $this->confirm(['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => true], $plan);
		self::assertSame([['assign', '42', SetTagWorld::CONTRACT]], $this->world->writes);
		self::assertSame(['changed' => true, 'path' => '/f42.txt', 'tag' => ['id' => '1', 'name' => 'Contract'], 'assigned' => true],
			array_diff_key($receipt, ['tags' => 0]));
		self::assertSame(['1'], array_column($receipt['tags'], 'id'), 'the receipt reads the relation back like files_list_tags');
		self::assertContains('PUT systemtags-relations/files/42/1', $this->world->native->methods);
		$this->assertOnlyReadsAndOneRelationWrite();
		self::assertSame(0, $this->world->native->backendCalls, 'no Sabre authentication backend ran');
	}

	#[DataProvider('pins')]
	public function testRemovingByIdDeletesOnlyTheRelation(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		$this->world->relations[SetTagWorld::OWN] = [SetTagWorld::CONTRACT, SetTagWorld::RESTRICTED];
		$arguments = ['path' => '/f42.txt', 'tag_id' => SetTagWorld::CONTRACT, 'assigned' => false];
		$receipt = $this->confirm($arguments, $this->world->preview->plan($arguments, 'alice'));
		self::assertSame([['unassign', '42', SetTagWorld::CONTRACT]], $this->world->writes);
		self::assertFalse($receipt['assigned']);
		self::assertSame([SetTagWorld::RESTRICTED], $this->world->relations[SetTagWorld::OWN], 'the other tag stays');
		self::assertContains('DELETE systemtags-relations/files/42/1', $this->world->native->methods);
		$this->assertOnlyReadsAndOneRelationWrite();
	}

	#[DataProvider('pins')]
	public function testAnUnchangedRelationIsNothingToDoAndSendsNoWrite(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		$this->world->relations[SetTagWorld::OWN] = [SetTagWorld::CONTRACT];
		$arguments = ['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => true];
		$plan = $this->world->preview->plan($arguments, 'alice');
		self::assertTrue($plan['nothing_to_do']);
		$receipt = $this->confirm($arguments, $plan);
		self::assertSame([false, true], [$receipt['changed'], $receipt['assigned']]);
		self::assertSame([], $this->world->writes);
		self::assertSame([], array_filter($this->world->native->methods, static fn (string $m): bool => !str_starts_with($m, 'PROPFIND ')));
	}

	/** @return iterable<string,array{string,array<string,mixed>}> tags or files that must not be usable, on both pins */
	public static function unusable(): iterable {
		foreach (self::cases() as $name => $arguments) {
			foreach (self::pins() as $pin => [$major]) {
				yield $name . ' on ' . $pin => [$major, $arguments];
			}
		}
	}

	/** @return iterable<string,array<string,mixed>> tags or files that must not be usable, whatever the reason */
	private static function cases(): iterable {
		yield 'unknown tag name' => ['path' => '/f42.txt', 'tag' => 'Nope', 'assigned' => true];
		yield 'unknown tag ID' => ['path' => '/f42.txt', 'tag_id' => '999', 'assigned' => true];
		yield 'invisible tag by name' => ['path' => '/f42.txt', 'tag' => 'Secret', 'assigned' => true];
		yield 'invisible tag by ID' => ['path' => '/f42.txt', 'tag_id' => SetTagWorld::SECRET, 'assigned' => true];
		yield 'exclusion tag assigned by name' => ['path' => '/f42.txt', 'tag' => 'Hide from MCP', 'assigned' => true];
		yield 'exclusion tag removed by ID' => ['path' => '/f42.txt', 'tag_id' => SetTagWorld::EXCLUSION, 'assigned' => false];
		yield 'ambiguous name' => ['path' => '/f42.txt', 'tag' => 'Twin', 'assigned' => true];
		yield 'malformed tag ID' => ['path' => '/f42.txt', 'tag_id' => '1 OR 1', 'assigned' => true];
		yield 'hidden file' => ['path' => '/f77.txt', 'tag' => 'Contract', 'assigned' => true];
		yield 'missing file' => ['path' => '/f99.txt', 'tag' => 'Contract', 'assigned' => true];
		yield 'file without UPDATE' => ['path' => '/f44.txt', 'tag' => 'Contract', 'assigned' => true];
	}

	#[DataProvider('unusable')]
	public function testUnusableTagsAndFilesAreRefusedLikeAMissingFileWithoutWriting(string $major, array $arguments): void {
		$this->world = new SetTagWorld($this, $major);
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->world->preview->plan($arguments, 'alice')));
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->world->handler->handle($arguments + ['confirm' => true, 'plan_state' => str_repeat('a', 64)], 'alice')));
		self::assertSame([], $this->world->writes);
		self::assertSame([], array_filter($this->world->native->methods, static fn (string $m): bool => !str_starts_with($m, 'PROPFIND ')));
	}

	#[DataProvider('pins')]
	public function testTheNativeCollectionDecidesTheTagAndTheFile(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		// Visible but assignable only by administrators: MCP plans it, the native createFile refuses it (403).
		$restricted = ['path' => '/f42.txt', 'tag_id' => SetTagWorld::RESTRICTED, 'assigned' => true];
		$plan = $this->world->preview->plan($restricted, 'alice');
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->confirm($restricted, $plan)));
		self::assertContains('PUT systemtags-relations/files/42/4', $this->world->native->methods);
		// MCP sees UPDATE, but no alias of the file grants it to the native relation write (403).
		$readOnly = ['path' => '/f56.txt', 'tag' => 'Contract', 'assigned' => true];
		$plan = $this->world->preview->plan($readOnly, 'alice');
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->confirm($readOnly, $plan)));
		self::assertSame([], $this->world->writes);
	}

	/** @return iterable<string,array{string,array<string,mixed>,bool}> a tag selected for exclusion while the confirmation runs */
	public static function selectedMeanwhile(): iterable {
		foreach (self::pins() as $pin => [$major]) {
			yield 'assign by ID on ' . $pin => [$major, ['path' => '/f42.txt', 'tag_id' => SetTagWorld::CONTRACT, 'assigned' => true], false];
			yield 'assign by name on ' . $pin => [$major, ['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => true], false];
			yield 'remove by ID on ' . $pin => [$major, ['path' => '/f42.txt', 'tag_id' => SetTagWorld::CONTRACT, 'assigned' => false], true];
			yield 'remove by name on ' . $pin => [$major, ['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => false], true];
		}
	}

	#[DataProvider('selectedMeanwhile')]
	public function testATagSelectedForExclusionDuringTheConfirmationIsNeverWritten(string $major, array $arguments, bool $tagged): void {
		$this->world = new SetTagWorld($this, $major);
		$this->world->relations[SetTagWorld::OWN] = $tagged ? [SetTagWorld::CONTRACT] : [];
		$plan = $this->world->preview->plan($arguments, 'alice');
		// The administrator selects the tag once the confirmed call has rebuilt the plan, while it reads the relation
		// back over DAV, so the plan and its plan_state still match: only the check at the sink can stop the write.
		$this->world->native->afterMethod = function (): void {
			if (end($this->world->native->methods) === 'PROPFIND systemtags-relations/files/42') {
				$this->world->exclusions[] = SetTagWorld::CONTRACT;
			}
		};
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->confirm($arguments, $plan)));
		self::assertContains(SetTagWorld::CONTRACT, $this->world->exclusions, 'the selection changed during the confirmation');
		self::assertSame([], $this->world->writes);
		self::assertSame([], array_filter($this->world->native->methods, static fn (string $m): bool => !str_starts_with($m, 'PROPFIND ')));
	}

	#[DataProvider('pins')]
	public function testAnUnreadableExclusionSelectionAtTheSinkRefusesTheWrite(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		$arguments = ['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => true];
		$plan = $this->world->preview->plan($arguments, 'alice');
		$this->world->native->afterMethod = function (): void {
			if (end($this->world->native->methods) === 'PROPFIND systemtags-relations/files/42') {
				$this->world->exclusionsReadable = false;
			}
		};
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->confirm($arguments, $plan)));
		self::assertSame([], $this->world->writes);
	}

	/** @return iterable<string,array{string,string,bool}> each way an app adds a DAV plugin, assigning and removing */
	public static function vetoRoutes(): iterable {
		foreach (self::pins() as $pin => [$major]) {
			foreach (['typed SabrePluginAddEvent' => 'eventPlugins', 'legacy addPlugin event' => 'legacyPlugins', 'PluginManager' => 'appPlugins'] as $route => $property) {
				yield $route . ', assign, on ' . $pin => [$major, $property, true];
				yield $route . ', remove, on ' . $pin => [$major, $property, false];
			}
		}
	}

	#[DataProvider('vetoRoutes')]
	public function testAnAppPluginVetoStopsTheWriteAndLeavesTheReadsAlone(string $major, string $property, bool $assign): void {
		$this->world = new SetTagWorld($this, $major);
		$this->world->relations[SetTagWorld::OWN] = $assign ? [] : [SetTagWorld::CONTRACT];
		$veto = new VetoPlugin();
		if ($property === 'appPlugins') {
			$this->world->native->appPlugins = [$veto];
		} else {
			$this->world->{$property} = [$veto];
		}
		$arguments = ['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => $assign];
		$plan = $this->world->preview->plan($arguments, 'alice');
		self::assertSame($assign ? 'assign' : 'remove', $plan['change'], 'the reads (catalog and relation) answer as without the plugin');
		self::assertSame(SetTagWorld::notFound(), $this->world->refusal(fn () => $this->confirm($arguments, $plan)));
		self::assertSame([($assign ? 'beforeBind' : 'beforeUnbind') . ' systemtags-relations/files/42/1'], $veto->vetoed);
		self::assertSame([], $this->world->writes, 'the vetoed relation was not written');
		self::assertSame($assign ? [] : [SetTagWorld::CONTRACT], $this->world->relations[SetTagWorld::OWN]);
	}

	#[DataProvider('pins')]
	public function testAFileOfSomeoneElseNeedsConfirmShared(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		$arguments = ['path' => '/f43.txt', 'tag' => 'Contract', 'assigned' => true];
		$plan = $this->world->preview->plan($arguments, 'alice');
		self::assertTrue($plan['requiresSharedConfirmation']);
		self::assertSame(array_keys(SetTagWorld::samplePlan()), array_keys($plan), 'the sample plan of the readability contract keeps the real keys');
		self::assertSame(array_keys(SetTagWorld::samplePlan()['shared'][0]), array_keys($plan['shared'][0]));
		$asked = $this->confirm($arguments, $plan);
		self::assertSame([true, 'shared', 'Bob'], [$asked['requiresConfirmation'], $asked['scope'], $asked['sharedBy']]);
		self::assertSame([], $this->world->writes, 'no write without confirm_shared');
		$this->confirm($arguments + ['confirm_shared' => true], $plan);
		self::assertSame([['assign', '43', SetTagWorld::CONTRACT]], $this->world->writes);
	}

	#[DataProvider('pins')]
	public function testAChangeAfterThePlanOrARevokedGrantWritesNothing(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		$arguments = ['path' => '/f42.txt', 'tag' => 'Contract', 'assigned' => true];
		$plan = $this->world->preview->plan($arguments, 'alice');
		$this->world->relations[SetTagWorld::OWN] = [SetTagWorld::CONTRACT];
		try {
			$this->confirm($arguments, $plan);
			self::fail('A stale plan was confirmed');
		} catch (PlanChanged $changed) {
			self::assertTrue($changed->plan['nothing_to_do']);
			self::assertSame(TaggingMessages::planChanged(), $changed->plan['warnings'][0]['message']);
		}
		$this->world->relations[SetTagWorld::OWN] = [];
		$plan = $this->world->preview->plan($arguments, 'alice');
		$this->world->granted = false;
		self::assertSame([ToolFailure::class, TaggingMessages::notAllowed(), 0], $this->world->refusal(fn () => $this->confirm($arguments, $plan)));
		self::assertSame([], $this->world->writes);
	}

	#[DataProvider('pins')]
	public function testExactlyOneOfTagIdAndTag(string $major): void {
		$this->world = new SetTagWorld($this, $major);
		foreach ([['path' => '/f42.txt', 'assigned' => true], ['path' => '/f42.txt', 'tag' => 'Contract', 'tag_id' => '1', 'assigned' => true]] as $arguments) {
			try {
				$this->world->preview->plan($arguments, 'alice');
				self::fail('Accepted ' . json_encode($arguments));
			} catch (ArgumentValidationException $error) {
				self::assertSame(['field' => 'arguments', 'rule' => TaggingMessages::oneOfTagIdOrName()], $error->details());
			}
		}
		self::assertSame([], $this->world->native->methods, 'nothing reached DAV');
	}

	/**
	 * @param array<string,mixed> $arguments plan arguments
	 * @param array<string,mixed> $plan the plan they produced
	 * @return array<string,mixed> the decoded result of the confirmed call
	 */
	private function confirm(array $arguments, array $plan): array {
		$result = $this->world->tool->execute($arguments + ['confirm' => true, PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], new ToolContext('alice'));
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/** @return void every request was a PROPFIND, except at most one PUT or DELETE on a relation */
	private function assertOnlyReadsAndOneRelationWrite(): void {
		$writes = array_values(array_filter($this->world->native->methods, static fn (string $m): bool => !str_starts_with($m, 'PROPFIND ')));
		self::assertCount(1, $writes);
		self::assertMatchesRegularExpression('#^(PUT|DELETE) systemtags-relations/files/\d+/\d+$#', $writes[0]);
	}
}
