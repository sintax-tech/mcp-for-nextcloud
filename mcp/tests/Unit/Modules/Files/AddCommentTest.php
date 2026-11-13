<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Files\Commenting\AddCommentPlan;
use OCA\Mcp\Modules\Files\Commenting\CommentMentions;
use OCA\Mcp\Modules\Files\Presentation\CommentingMessages;
use OCA\Mcp\Modules\Files\Tools\AddComment;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * files_add_comment over the verbatim native comments pipeline of NC34 and NC35: the plan writes nothing, the
 * confirmed call adds exactly one comment through the native POST as alice, moves only alice's own read marker, and
 * notifies exactly the accounts the plan named; unreachable files, refused mentions, a changed plan and a revoked
 * grant write nothing.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AddCommentTest extends TestCase {
	private CommentWorld $world;

	/** @return iterable<string,array{string}> the supported source pins */
	public static function pins(): iterable {
		yield 'NC34 server v34.0.4' => ['34'];
		yield 'NC35 server v35.0.1' => ['35'];
	}

	#[DataProvider('pins')]
	public function testPlanShowsTheStoredTextTheMentionsAndTheMarkerWithoutWriting(string $major): void {
		$this->world = new CommentWorld($this, $major);
		$plan = $this->world->preview->plan(['path' => '/f42.txt', 'text' => "  Ready for review, @bob and @alice\n`@nobody` is code.  "], 'alice');
		self::assertSame("Ready for review, @bob and @alice\n`@nobody` is code.", $plan['text'], 'trimmed as the native setMessage stores it');
		self::assertSame([['id' => 'bob', 'display_name' => 'Bob Lima']], $plan['notifies'], 'alice is never notified of her own mention');
		self::assertSame(['now' => '2026-11-01T10:00:00Z', 'after' => 'now'], $plan['read_marker']);
		self::assertFalse($plan['requiresSharedConfirmation']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan[PlanState::ARGUMENT]);
		self::assertSame([[], [], []], [$this->world->saves, $this->world->markerWrites, $this->world->notified]);
		self::assertSame(0, $this->world->native->backendCalls);
		self::assertSame(array_keys(CommentWorld::samplePlan()), array_keys($plan), 'the renderer contract sample keeps the real keys');
	}

	#[DataProvider('pins')]
	public function testConfirmedCommentIsOneNativePostByTheActor(string $major): void {
		$this->world = new CommentWorld($this, $major);
		$arguments = ['path' => '/f42.txt', 'text' => 'Approved, @bob'];
		$state = $this->world->preview->plan($arguments, 'alice')[PlanState::ARGUMENT];
		$receipt = $this->world->handler->handle($arguments + [PlanState::ARGUMENT => $state], 'alice');
		self::assertTrue($receipt['added']);
		self::assertSame('/f42.txt', $receipt['path']);
		self::assertSame(['id' => '100', 'author' => ['type' => 'users', 'id' => 'alice', 'display_name' => 'Alice'], 'text' => 'Approved, @bob',
			'reply_to' => null, 'verb' => 'comment'], array_intersect_key($receipt['comment'], array_flip(['id', 'author', 'text', 'reply_to', 'verb'])));
		self::assertSame([['42', 'alice', 'Approved, @bob']], $this->world->saves, 'one comment, author taken from the session');
		self::assertSame([['42', 'alice']], $this->world->markerWrites, 'only the author\'s own marker moves');
		self::assertSame(['bob'], $this->world->notified, 'the native listener notified exactly whom the plan named');
		self::assertSame(array_fill(0, 4, 'comments/files/42'), $this->world->native->built, 'preview marker, then marker, POST and receipt');
		self::assertSame(0, $this->world->native->backendCalls);
	}

	#[DataProvider('pins')]
	public function testUnreachableItemsAreRefusedLikeAMissingOne(string $major): void {
		$this->world = new CommentWorld($this, $major);
		foreach (['/f77.txt', '/missing.txt', '/f55.txt', '/forbidden.txt'] as $path) {
			self::assertSame(CommentWorld::notFound(), $this->world->refusal(fn () => $this->world->preview->plan(['path' => $path, 'text' => 'Hi'], 'alice')), $path);
			self::assertSame(CommentWorld::notFound(), $this->world->refusal(fn () => $this->world->handler->handle(['path' => $path, 'text' => 'Hi',
				PlanState::ARGUMENT => str_repeat('a', 64)], 'alice')), $path);
		}
		self::assertSame(['comments/files/55', 'comments/files/55'], $this->world->native->built, 'only the item the Files gate admits reaches DAV');
		self::assertSame([[], []], [$this->world->saves, $this->world->markerWrites]);
	}

	#[DataProvider('pins')]
	public function testMentionsOfAnythingButAnExistingAccountAreRefusedBeforeDav(string $major): void {
		$this->world = new CommentWorld($this, $major);
		foreach ([
			'Hi @ghost' => CommentingMessages::unknownMention('@ghost'),
			'Hi @"group/admin"' => CommentingMessages::unsupportedMention('@"group/admin"'),
			'Hi @"guest/0a1b"' => CommentingMessages::unsupportedMention('@"guest/0a1b"'),
			'Hi @"email/0a1b"' => CommentingMessages::unsupportedMention('@"email/0a1b"'),
			'Hi @"federated_user/bob@other"' => CommentingMessages::unsupportedMention('@"federated_user/bob@other"'),
			'Hi @"user/bob"' => CommentingMessages::unknownMention('@"user/bob"'),
			'   ' => CommentingMessages::emptyText(),
			str_repeat('x', AddCommentPlan::MAX_LENGTH + 1) => CommentingMessages::tooLong(AddCommentPlan::MAX_LENGTH),
		] as $text => $message) {
			self::assertSame([ToolFailure::class, $message, 0], $this->world->refusal(fn () => $this->world->preview->plan(['path' => '/f42.txt', 'text' => (string)$text], 'alice')));
		}
		self::assertSame([], $this->world->native->built);
		self::assertSame([], $this->world->notified);
	}

	#[DataProvider('pins')]
	public function testMentionScanIsTheNativeOne(string $major): void {
		CommentWorld::loadNative($major);
		foreach (['@bob', 'Hi @bob and @"carol smith", see @"group/admins" @"guest/0a1b" @"email/9f"', 'mail me at bob@example.com',
			"```\n@inside code\n```\n@outside", '`@inline` @after', '@"federated_team/x" @"team/y" @"federated_group/z" @"federated_user/u@h"',
			'@bob.lima\'s note', '(@bob) [@carol]', '@a @a @ab'] as $text) {
			$native = (new \OC\Comments\Comment())->setMessage($text)->getMentions();
			$ours = array_map(static fn (array $m): array => ['type' => $m['type'], 'id' => $m['id']], CommentMentions::scan($text));
			self::assertSame($native, $ours, $text);
		}
	}

	#[DataProvider('pins')]
	public function testAChangedPlanARevokedGrantAndAMissingStateWriteNothing(string $major): void {
		$this->world = new CommentWorld($this, $major);
		$state = $this->world->preview->plan(['path' => '/f42.txt', 'text' => 'First text'], 'alice')[PlanState::ARGUMENT];
		try {
			$this->world->handler->handle(['path' => '/f42.txt', 'text' => 'Other text', PlanState::ARGUMENT => $state], 'alice');
			self::fail('A different text was written with the first plan');
		} catch (PlanChanged) {
		}
		$this->world->granted = false;
		self::assertSame([ToolFailure::class, CommentingMessages::notAllowed(), 0],
			$this->world->refusal(fn () => $this->world->handler->handle(['path' => '/f42.txt', 'text' => 'First text', PlanState::ARGUMENT => $state], 'alice')));
		$this->world->granted = true;
		try {
			$this->world->handler->handle(['path' => '/f42.txt', 'text' => 'First text'], 'alice');
			self::fail('Confirmed without plan_state');
		} catch (\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException) {
		}
		self::assertSame([[], [], []], [$this->world->saves, $this->world->markerWrites, $this->world->notified]);
	}

	#[DataProvider('pins')]
	public function testASharedFileNeedsConfirmShared(string $major): void {
		$this->world = new CommentWorld($this, $major);
		$arguments = ['path' => '/f43.txt', 'text' => 'Looks good'];
		$plan = $this->world->preview->plan($arguments, 'alice');
		self::assertTrue($plan['requiresSharedConfirmation']);
		$asked = $this->world->handler->handle($arguments + [PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]], 'alice');
		self::assertTrue($asked['requiresConfirmation']);
		self::assertSame([], $this->world->saves);
		$done = $this->world->handler->handle($arguments + [PlanState::ARGUMENT => $plan[PlanState::ARGUMENT], 'confirm_shared' => true], 'alice');
		self::assertTrue($done['added']);
		self::assertSame([['43', 'alice', 'Looks good']], $this->world->saves);
	}

	#[DataProvider('pins')]
	public function testAFileHiddenRightBeforeThePostIsRefused(string $major): void {
		$this->world = new CommentWorld($this, $major);
		$arguments = ['path' => '/f42.txt', 'text' => 'Late'];
		$state = $this->world->preview->plan($arguments, 'alice')[PlanState::ARGUMENT];
		$clears = 0;
		// The confirmed call clears the gate cache once for the plan's marker read and once more right before the POST.
		$this->world->onClearCache = function () use (&$clears): void {
			if (++$clears === 2) {
				$this->world->hidden[] = CommentWorld::OWN;
			}
		};
		self::assertSame(CommentWorld::notFound(), $this->world->refusal(fn () => $this->world->handler->handle($arguments + [PlanState::ARGUMENT => $state], 'alice')));
		self::assertSame([[], []], [$this->world->saves, $this->world->markerWrites]);
	}

	public function testDefinitionIsADefaultDeniedCommentsWrite(): void {
		$definition = AddComment::definition();
		self::assertSame(['files_add_comment', 'files', 'comments'], [$definition->name, $definition->module, $definition->operation]);
		self::assertSame(['path', 'text'], $definition->inputSchema['required']);
		self::assertSame(AddCommentPlan::MAX_LENGTH, $definition->inputSchema['properties']['text']['maxLength']);
		self::assertContains('comments', \OCA\Mcp\Access\Grants\GrantCatalog::CATALOG['files']);
	}
}
