<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Acl;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Modules\Deck\Integration\BoardRecipientResolver;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages as DeckMessages;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Recipients\LinkRecipientContext;
use OCA\Mcp\Modules\Deck\Recipients\RecipientDirectory;
use OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion;
use OCA\Mcp\Modules\Deck\Recipients\RecipientMessages;
use OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException;
use OCA\Mcp\Modules\Deck\Recipients\RecipientSnapshot;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Complete recipient resolution, authorization order, canonical fingerprints and refusal boundaries.
 */
final class BoardRecipientResolverTest extends TestCase {
	/**
	 * Controlled native source authorization boundary.
	 *
	 * @var DeckGatewayInterface
	 */
	private DeckGatewayInterface $gateway;
	/**
	 * Controlled complete native membership directory.
	 *
	 * @var RecipientDirectory
	 */
	private RecipientDirectory $directory;
	/**
	 * Operation grant decisions used by this test.
	 *
	 * @var GrantPolicy
	 */
	private GrantPolicy $policy;
	/**
	 * Real resolver under test.
	 *
	 * @var BoardRecipientResolver
	 */
	private BoardRecipientResolver $resolver;
	/**
	 * Current complete native ACL rows.
	 *
	 * @var array
	 */
	private array $acls = [];
	/**
	 * Group identities mapped to current native UID lists.
	 *
	 * @var array
	 */
	private array $groups = [];
	/**
	 * Circle identities mapped to current native UID lists.
	 *
	 * @var array
	 */
	private array $circles = [];
	/**
	 * Current display names independent of stable UIDs.
	 *
	 * @var array
	 */
	private array $names = [];
	/**
	 * Observed authorization and native lookup order.
	 *
	 * @var array
	 */
	private array $events = [];
	/**
	 * Optional single denied module and operation.
	 *
	 * @var ?array
	 */
	private ?array $denied = null;
	/**
	 * Deterministic monotonic clock instant in nanoseconds.
	 *
	 * @var int
	 */
	private int $now = 0;
	/**
	 * Board returned by the controlled native source lookup.
	 *
	 * @var int
	 */
	private int $sourceBoard = 20;
	/**
	 * Whether the native source lookup unexpectedly fails.
	 *
	 * @var bool
	 */
	private bool $sourceDenied = false;
	/**
	 * Whether the native circle backend unexpectedly fails.
	 *
	 * @var bool
	 */
	private bool $directoryFailed = false;
	/**
	 * Whether a controlled native call exceeds the budget.
	 *
	 * @var bool
	 */
	private bool $slow = false;
	/**
	 * Per-call native source exceptions or successful-return markers.
	 *
	 * @var array
	 */
	private array $sourceFailures = [];
	/**
	 * Optional controlled known or arbitrary directory exception.
	 *
	 * @var ?\Throwable
	 */
	private ?\Throwable $directoryFailure = null;
	/**
	 * Captured log message and class-only context pairs.
	 *
	 * @var array
	 */
	private array $logs = [];
	/**
	 * Number of grant decisions evaluated so far.
	 *
	 * @var int
	 */
	private int $grantCalls = 0;
	/**
	 * Last allowed decision number for a simulated context denial.
	 *
	 * @var int
	 */
	private int $denyAfterGrantCalls = PHP_INT_MAX;

	/**
	 * Compose the real resolver with deterministic native boundaries and the real PlanState.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = $this->createMock(DeckGatewayInterface::class);
		$this->gateway->method('cardFileContext')->willReturnCallback(function ($uid, $card, $edit): array {
			self::assertTrue($edit);
			$this->events[] = 'source';
			$failure = array_shift($this->sourceFailures);
			if ($failure instanceof \Throwable) {
				throw $failure;
			}
			if ($this->sourceDenied) {
				throw new RuntimeException('native denied');
			}
			self::assertTrue($edit);
			return ['boardId' => $this->sourceBoard, 'board' => ['owner' => 'owner']];
		});
		$this->policy = $this->createMock(GrantPolicy::class);
		$this->policy->method('within')->willReturnCallback(static fn ($uid, $work) => $work());
		$this->policy->method('granted')->willReturnCallback(function ($uid, $module, $operation): bool {
			$this->events[] = $module . '.' . $operation;
			return ++$this->grantCalls <= $this->denyAfterGrantCalls && $this->denied !== [$module, $operation];
		});
		$this->directory = $this->createMock(RecipientDirectory::class);
		$this->directory->method('acls')->willReturnCallback(function (): array {
			$this->events[] = 'acls';
			return $this->acls;
		});
		$this->directory->method('user')->willReturnCallback(fn ($uid): IUser => $this->user($uid));
		$this->directory->method('group')->willReturnCallback(function ($id): array {
			if ($this->slow) {
				$this->now = RecipientExpansion::MAX_DURATION_NS + 1;
				return [];
			}
			return array_map($this->user(...), $this->groups[$id]);
		});
		$this->directory->method('circle')->willReturnCallback(function ($id): array {
			if ($this->directoryFailure !== null) {
				throw $this->directoryFailure;
			}
			if ($this->directoryFailed) {
				throw new RuntimeException('Circles absent');
			}
			return array_map($this->user(...), $this->circles[$id]);
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('test-instance-secret');
		$this->resolver = new BoardRecipientResolver($this->gateway, $this->policy, $this->directory,
			new PlanState($config), $this->logger(), fn (): int => $this->now);
	}

	/**
	 * Build a real target binding; no Files validation belongs in this component.
	 *
	 * @param string $actor Authenticated caller.
	 * @param int $board Expected board identity.
	 * @param int $card Expected card identity.
	 * @param int $file Expected file identity.
	 * @param string $etag Expected file version.
	 * @return LinkRecipientContext Internal link context.
	 */
	private function context(string $actor = 'actor', int $board = 20, int $card = 7, int $file = 99, string $etag = 'v1'): LinkRecipientContext {
		return new LinkRecipientContext($actor, $board, $card, $file, $etag);
	}

	/**
	 * Resolve a controlled existing native identity, keeping display names independent.
	 *
	 * @param string $uid Stable UID, including numeric-looking or guest identities.
	 * @return IUser Controlled native user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($this->names[$uid] ?? 'Name ' . $uid);
		return $user;
	}

	/**
	 * Construct one scalar ACL with its permission fields preserved.
	 *
	 * @param int $type Native ACL type.
	 * @param string $id Participant identity.
	 * @return array<string, mixed> Native ACL boundary data.
	 */
	private function acl(int $type, string $id): array {
		return ['type' => $type, 'participant' => $id, 'edit' => false, 'share' => false, 'manage' => false];
	}

	/**
	 * Resolve overlapping direct/group/circle membership once per stable UID, including the owner.
	 *
	 * @return void
	 */
	public function testCanonicalSetIgnoresAclOrderAndOverlappingPaths(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_USER, '123'), $this->acl(Acl::PERMISSION_TYPE_GROUP, 'g'),
			$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'c')];
		$this->groups['g'] = ['123', 'guest', 'owner'];
		$this->circles['c'] = ['guest', 'circle-user'];
		$first = $this->resolver->resolve($this->context());
		self::assertSame(['123', 'circle-user', 'guest', 'owner'], $first->uids);
		self::assertSame(4, $first->total);
		self::assertTrue($first->hasGroupAcl);
		self::assertTrue($first->hasCircleAcl);
		self::assertCount(3, $first->acls);
		self::assertSame(['deck.edit', 'files.read', 'files.share', 'source', 'acls'], array_slice($this->events, 0, 5));
		$this->acls = array_reverse($this->acls);
		$this->groups['g'] = ['owner', 'guest', '123', '123'];
		$second = $this->resolver->resolve($this->context());
		self::assertSame($first->recipientHash, $second->recipientHash);
		self::assertSame($first->planHash, $second->planHash);
		$this->groups['g'][] = 'new';
		$changed = $this->resolver->resolve($this->context());
		self::assertNotSame($first->recipientHash, $changed->recipientHash);
		array_pop($this->groups['g']);
		self::assertSame($first->recipientHash, $this->resolver->resolve($this->context())->recipientHash);
		$this->circles['c'][] = 'another';
		self::assertNotSame($first->recipientHash, $this->resolver->resolve($this->context())->recipientHash);
	}

	/**
	 * Bind identities to caller and targets, and display changes only to the presented plan hash.
	 *
	 * @return void
	 */
	public function testContextNamesAndPolicyFlagsHaveSeparateBindings(): void {
		$first = $this->resolver->resolve($this->context());
		foreach ([$this->context('another'), $this->context(card: 8), $this->context(file: 100), $this->context(etag: 'v2')] as $context) {
			$changed = $this->resolver->resolve($context);
			self::assertNotSame($first->recipientHash, $changed->recipientHash);
			self::assertNotSame($first->planHash, $changed->planHash);
		}
		$this->names['owner'] = 'Renamed';
		$renamed = $this->resolver->resolve($this->context());
		self::assertSame($first->recipientHash, $renamed->recipientHash);
		self::assertNotSame($first->planHash, $renamed->planHash);
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'empty')];
		$this->groups['empty'] = [];
		$flagged = $this->resolver->resolve($this->context());
		self::assertSame($renamed->recipientHash, $flagged->recipientHash);
		self::assertNotSame($renamed->planHash, $flagged->planHash);
	}

	/**
	 * Keep the current total complete while limiting presentation names and their lengths.
	 *
	 * @return void
	 */
	public function testPresentationIsLimitedWithoutLosingIdentities(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'many')];
		$this->groups['many'] = array_map(static fn ($i): string => 'u' . $i, range(1, RecipientSnapshot::MAX_NAMES + 2));
		foreach ($this->groups['many'] as $uid) {
			$this->names[$uid] = str_repeat('á', RecipientSnapshot::MAX_NAME_CHARS + 1);
		}
		$this->names['owner'] = str_repeat('á', RecipientSnapshot::MAX_NAME_CHARS + 1);
		$snapshot = $this->resolver->resolve($this->context());
		self::assertSame(RecipientSnapshot::MAX_NAMES + 3, $snapshot->total);
		self::assertCount(RecipientSnapshot::MAX_NAMES, $snapshot->names);
		self::assertCount($snapshot->total, $snapshot->uids);
		self::assertTrue($snapshot->truncated);
		foreach ($snapshot->names as $presented) {
			self::assertSame(str_repeat('á', RecipientSnapshot::MAX_NAME_CHARS), $presented['name']);
		}
	}

	/**
	 * Supply each required link grant independently.
	 *
	 * @return iterable<string, array{array{string,string}}> Denied grant cases.
	 */
	public static function grants(): iterable {
		yield 'Deck edit' => [['deck', 'edit']];
		yield 'Files read' => [['files', 'read']];
		yield 'Files share' => [['files', 'share']];
	}

	/**
	 * Never resolve source, ACL or people after a denied grant.
	 *
	 * @param array{string,string} $denied Required grant that is absent.
	 * @return void
	 */
	#[DataProvider('grants')]
	public function testMissingGrantClosesBeforeNativeDirectory(array $denied): void {
		$this->denied = $denied;
		$this->gateway->expects(self::never())->method('cardFileContext');
		$this->directory->expects(self::never())->method('acls');
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Require a current active readable/editable source before reading its ACLs.
	 *
	 * @return void
	 */
	public function testNativeSourceDenialClosesBeforeAclRead(): void {
		$this->sourceDenied = true;
		$this->directory->expects(self::never())->method('acls');
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Refuse card movement across boards before resolving any recipient.
	 *
	 * @return void
	 */
	public function testWrongBoardBindingClosesBeforeAclRead(): void {
		$this->directory->expects(self::never())->method('acls');
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context(board: 21));
	}

	/**
	 * Refuse all unsupported ACL types, even when their participant is an existing local user.
	 *
	 * @param int $type Unsupported native ACL code.
	 * @return void
	 */
	#[DataProvider('unsupportedTypes')]
	public function testUnknownAclTypeNeverResolvesItAsAUser(int $type): void {
		$this->acls = [$this->acl($type, 'owner')];
		$this->directory->expects(self::never())->method('user');
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Reject a recipient lookup failure rather than exposing a partial count.
	 *
	 * @return void
	 */
	public function testUnresolvedRecipientOrBackendErrorDoesNotReturnPartialState(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'missing')];
		$this->directoryFailed = true;
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Bound complete ACL count before expanding even the owner.
	 *
	 * @return void
	 */
	public function testAclLimitClosesBeforeUserLookup(): void {
		$this->acls = array_fill(0, BoardRecipientResolver::MAX_ACLS + 1, $this->acl(Acl::PERMISSION_TYPE_USER, 'owner'));
		$this->directory->expects(self::never())->method('user');
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Bound distinct identities and raw repeated work separately.
	 *
	 * @return void
	 */
	public function testRecipientLimitClosesInsteadOfTruncatingTotal(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'many')];
		$this->groups['many'] = array_map(static fn ($i): string => 'u' . $i, range(1, RecipientExpansion::MAX_RECIPIENTS));
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Refuse time consumed by a native lookup before returning any portrait.
	 *
	 * @return void
	 */
	public function testTimeLimitAfterNativeExpansionCloses(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'slow')];
		$this->slow = true;
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * Exercise unsupported codes without depending on a remote constant missing in older Deck tags.
	 *
	 * @return iterable<string, array{int}> Codes outside the supported allowlist.
	 */
	public static function unsupportedTypes(): iterable {
		foreach ([-1, 2, 3, 4, 5, 6, 8, 99] as $type) {
			yield (string)$type => [$type];
		}
	}

	/**
	 * Preserve a valid display name that PHP otherwise treats as false.
	 *
	 * @return void
	 */
	public function testNumericDisplayNameIsPresentationNotIdentity(): void {
		$this->names['owner'] = '0';
		self::assertSame([['uid' => 'owner', 'name' => '0']], $this->resolver->resolve($this->context())->names);
	}

	/**
	 * Refuse repeated expansion work independently of the distinct recipient limit.
	 *
	 * @return void
	 */
	public function testRawOverlapWorkLimitCloses(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'repeated')];
		$this->groups['repeated'] = array_fill(0, RecipientExpansion::MAX_VISITS, 'owner');
		$this->expectException(DeckRefusalException::class);
		$this->resolver->resolve($this->context());
	}

	/**
	 * A board change binds the same people to a different recipient hash.
	 *
	 * @return void
	 */
	public function testBoardBindingChangesFingerprint(): void {
		$first = $this->resolver->resolve($this->context());
		$this->sourceBoard = 21;
		$changed = $this->resolver->resolve($this->context(board: 21));
		self::assertNotSame($first->recipientHash, $changed->recipientHash);
		self::assertNotSame($first->planHash, $changed->planHash);
	}

	/**
	 * Context fields must not depend on PlanState's invalid UTF-8 substitution or permit empty targets.
	 *
	 * @param string $actor Bound actor UID.
	 * @param int $board Bound board identity.
	 * @param int $card Bound card identity.
	 * @param int $file Bound file identity.
	 * @param string $etag Observed file version.
	 * @return void
	 */
	#[DataProvider('invalidContexts')]
	public function testInvalidContextCloses(string $actor, int $board, int $card, int $file, string $etag): void {
		$this->expectException(DeckRefusalException::class);
		new LinkRecipientContext($actor, $board, $card, $file, $etag);
	}

	/**
	 * Supply malformed authentication or target bindings independently.
	 *
	 * @return iterable<string, array{string,int,int,int,string}> Invalid context fields.
	 */
	public static function invalidContexts(): iterable {
		yield 'empty actor' => ['', 1, 1, 1, 'v1'];
		yield 'invalid actor' => ["\xFF", 1, 1, 1, 'v1'];
		yield 'board' => ['actor', 0, 1, 1, 'v1'];
		yield 'card' => ['actor', 1, 0, 1, 'v1'];
		yield 'file' => ['actor', 1, 1, 0, 'v1'];
		yield 'empty etag' => ['actor', 1, 1, 1, ''];
		yield 'invalid etag' => ['actor', 1, 1, 1, "\xFF"];
	}
	/**
	 * Capture only the exact class-only logger payload, never native exception text or context.
	 *
	 * @return LoggerInterface Controlled log sink.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function ($message, $context): void {
			$this->logs[] = [$message, $context];
		});
		return $logger;
	}

	/**
	 * Preserve the safe inactive category only after two exact READ/EDIT markers and fresh grants.
	 *
	 * @return void
	 */
	public function testTwoExactInactiveMarkersAllowOnlySanitizedSourceMessage(): void {
		$this->sourceFailures = [DeckRefusalException::inactiveFileSource('actor', 7, 20),
			DeckRefusalException::inactiveFileSource('actor', 7, 20)];
		$this->directory->expects(self::never())->method('acls');
		try {
			$this->resolver->resolve($this->context());
			self::fail('Inactive source must refuse');
		} catch (DeckRefusalException $failure) {
			self::assertSame(\OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileSourceInactive(), $failure->getMessage());
		}
		self::assertSame(2, count(array_filter($this->events, static fn ($event): bool => $event === 'source')));
		self::assertSame(9, $this->grantCalls);
		self::assertSame([], $this->logs);
	}

	/**
	 * No arbitrary message, changed binding, lost permission, active return or lost grant reveals inactivity.
	 *
	 * @param string $case Controlled failure transition.
	 * @return void
	 */
	#[DataProvider('inactiveTransitions')]
	public function testInactiveTransitionsRemainGeneric(string $case): void {
		$marker = DeckRefusalException::inactiveFileSource('actor', 7, 20);
		$this->sourceFailures = [$marker, $marker];
		if ($case === 'forged text') {
			$this->sourceFailures[0] = new DeckRefusalException(\OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileSourceInactive());
		} elseif ($case === 'recipient failure before source') {
			$this->sourceFailures[0] = RecipientResolutionException::unsupported();
		} elseif ($case === 'arbitrary') {
			$this->sourceFailures[0] = new DeckRefusalException('secret native participant');
		} elseif ($case === 'wrong first board') {
			$this->sourceFailures[0] = DeckRefusalException::inactiveFileSource('actor', 7, 21);
		} elseif ($case === 'wrong second board') {
			$this->sourceFailures[1] = DeckRefusalException::inactiveFileSource('actor', 7, 21);
		} elseif ($case === 'wrong actor') {
			$this->sourceFailures[1] = DeckRefusalException::inactiveFileSource('another', 7, 20);
		} elseif ($case === 'wrong card') {
			$this->sourceFailures[1] = DeckRefusalException::inactiveFileSource('actor', 8, 20);
		} elseif ($case === 'lost READ' || $case === 'lost EDIT') {
			$this->sourceFailures[1] = new \OCA\Deck\NoPermissionException('secret native participant');
		} elseif ($case === 'active again') {
			$this->sourceFailures[1] = null;
		} elseif ($case === 'unexpected second failure') {
			$this->sourceFailures[1] = new RuntimeException('secret native participant');
		} elseif ($case === 'lost grant before retry') {
			$this->denyAfterGrantCalls = 3;
		} elseif ($case === 'lost grant after retry') {
			$this->denyAfterGrantCalls = 6;
		} elseif ($case === 'lost files.read before retry') {
			$this->denyAfterGrantCalls = 4;
		} elseif ($case === 'lost files.share before retry') {
			$this->denyAfterGrantCalls = 5;
		} elseif ($case === 'lost files.read after retry') {
			$this->denyAfterGrantCalls = 7;
		} elseif ($case === 'lost files.share after retry') {
			$this->denyAfterGrantCalls = 8;
		}
		$this->directory->expects(self::never())->method('acls');
		try {
			$this->resolver->resolve($this->context());
			self::fail('Uncertain source must refuse');
		} catch (DeckRefusalException $failure) {
			self::assertSame(\OCA\Mcp\Modules\Deck\Presentation\DeckAttachmentMessages::fileUnavailable(), $failure->getMessage());
		}
		foreach ($this->logs as [$message, $context]) {
			self::assertSame('Deck recipient resolution failed: {exceptionClass} (code {exception_code})', $message);
			self::assertSame(['exceptionClass' => RuntimeException::class, 'exception_code' => 0], $context);
		}
	}

	/**
	 * Enumerate authorization and binding losses without relying on exception text.
	 *
	 * @return iterable<string,array{string}> Inactive-source transitions that must stay generic.
	 */
	public static function inactiveTransitions(): iterable {
		foreach (['forged text', 'recipient failure before source', 'arbitrary', 'wrong first board', 'wrong second board', 'wrong actor',
			'wrong card', 'lost READ', 'lost EDIT', 'active again', 'unexpected second failure',
			'lost grant before retry', 'lost grant after retry', 'lost files.read before retry',
			'lost files.share before retry', 'lost files.read after retry', 'lost files.share after retry'] as $case) {
			yield $case => [$case];
		}
	}

	/**
	 * Native exception details never become a recipient refusal, even after the source is authorized.
	 *
	 * @return void
	 */
	public function testUnexpectedAuthorizedResolutionIsSanitizedAndLogsClassOnly(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'circle')];
		$this->directoryFailed = true;
		try {
			$this->resolver->resolve($this->context());
			self::fail('Incomplete resolution must refuse');
		} catch (DeckRefusalException $failure) {
			self::assertSame(RecipientMessages::incomplete(), $failure->getMessage());
		}
		self::assertSame([['Deck recipient resolution failed: {exceptionClass} (code {exception_code})', ['exceptionClass' => RuntimeException::class, 'exception_code' => 0]]], $this->logs);
	}

	/**
	 * Known authorized categories are distinct and never logged as unexpected failures.
	 *
	 * @param string $kind Authorized composition failure.
	 * @return void
	 */
	#[DataProvider('recipientFailureKinds')]
	public function testKnownRecipientFailuresHaveSanitizedCategories(string $kind): void {
		if ($kind === 'unsupported') {
			$this->acls = [$this->acl(99, 'hidden-id')];
		} elseif ($kind === 'limit') {
			$this->acls = array_fill(0, BoardRecipientResolver::MAX_ACLS + 1, $this->acl(Acl::PERMISSION_TYPE_USER, 'owner'));
		} else {
			$this->acls = [$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'circle')];
			$this->directoryFailure = RecipientResolutionException::incomplete();
		}
		try {
			$this->resolver->resolve($this->context());
			self::fail('Known incomplete resolution must refuse');
		} catch (DeckRefusalException $failure) {
			self::assertSame(RecipientMessages::$kind(), $failure->getMessage());
		}
		self::assertSame([], $this->logs);
	}

	/**
	 * Exercise every translated safe composition category.
	 *
	 * @return iterable<string,array{string}> Known failure kinds.
	 */
	public static function recipientFailureKinds(): iterable {
		foreach (['incomplete', 'unsupported', 'limit'] as $kind) {
			yield $kind => [$kind];
		}
	}

	/**
	 * Structural ACL changes bind future audience even when both flags and all current people stay equal.
	 *
	 * @return void
	 */
	public function testCanonicalAclStructureBindsOnlyThePlanHash(): void {
		$this->groups = ['empty-a' => [], 'empty-b' => []];
		$this->circles = ['empty-a' => [], 'empty-b' => []];
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'empty-a'),
			$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'empty-a')];
		$baseline = $this->resolver->resolve($this->context());
		foreach ([Acl::PERMISSION_TYPE_GROUP, Acl::PERMISSION_TYPE_CIRCLE] as $type) {
			$this->acls[] = $this->acl($type, 'empty-b');
			$expanded = $this->resolver->resolve($this->context());
			self::assertSame($baseline->recipientHash, $expanded->recipientHash);
			self::assertNotSame($baseline->planHash, $expanded->planHash);
			$this->acls = array_reverse([...$this->acls, ...$this->acls]);
			self::assertSame($expanded->planHash, $this->resolver->resolve($this->context())->planHash);
			$this->acls = [$this->acl(Acl::PERMISSION_TYPE_GROUP, 'empty-a'),
				$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'empty-a')];
			$this->acls[$type === Acl::PERMISSION_TYPE_GROUP ? 0 : 1]['participant'] = 'empty-b';
			self::assertNotSame($baseline->planHash, $this->resolver->resolve($this->context())->planHash);
			$this->acls = $baseline->acls;
		}
		foreach (['edit', 'share', 'manage'] as $permission) {
			$this->acls = $baseline->acls;
			$this->acls[0][$permission] = !$this->acls[0][$permission];
			$changed = $this->resolver->resolve($this->context());
			self::assertSame($baseline->recipientHash, $changed->recipientHash);
			self::assertNotSame($baseline->planHash, $changed->planHash);
		}
		self::assertArrayNotHasKey('acls', $baseline->presentation());
	}

	/**
	 * Every safe recipient category has an English source and a real translation in each supported catalog.
	 *
	 * @return void
	 */
	public function testSafeMessagesUseEverySupportedLanguage(): void {
		Translator::reset();
		$source = [RecipientMessages::incomplete(), RecipientMessages::unsupported(), RecipientMessages::limit()];
		try {
			foreach (['en', 'pt_BR', 'es', 'de'] as $locale) {
				Translator::use(new JsonL10n($locale));
				$translated = [RecipientMessages::incomplete(), RecipientMessages::unsupported(), RecipientMessages::limit()];
				if ($locale === 'en') {
					self::assertSame($source, $translated);
				} else {
					foreach ($translated as $index => $message) {
						self::assertNotSame($source[$index], $message);
						self::assertNotSame('', $message);
					}
				}
			}
		} finally {
			Translator::reset();
		}
	}

	/**
	 * A generic domain exception from an authorized directory is not trusted as caller-safe text.
	 *
	 * @return void
	 */
	public function testArbitraryAuthorizedDomainRefusalIsSanitized(): void {
		$this->acls = [$this->acl(Acl::PERMISSION_TYPE_CIRCLE, 'circle')];
		$this->directoryFailure = new DeckRefusalException('hidden recipient identity');
		try {
			$this->resolver->resolve($this->context());
			self::fail('Arbitrary domain failure must refuse');
		} catch (DeckRefusalException $failure) {
			self::assertSame(RecipientMessages::incomplete(), $failure->getMessage());
		}
		self::assertSame([], $this->logs);
	}

}
