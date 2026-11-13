<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Routines\Preparation\AccountEmailMatch;
use OCA\Mcp\Modules\Routines\Preparation\CardCandidates;
use OCA\Mcp\Modules\Routines\Preparation\FileCandidates;
use OCA\Mcp\Modules\Routines\Preparation\MailCandidates;
use OCA\Mcp\Modules\Routines\Preparation\ParticipantRoster;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Tools\PrepareMeeting;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;

/** Mail, Deck, files and people of the dossier: exact matches, missing grants, and the budgets. */
final class PrepareMeetingSourcesTest extends TestCase {
	/** @var list<string> grants of a dossier that may consult every source */
	private const ON = ['calendar.read', 'mail.messages', 'deck.read', 'files.read', 'people.read'];

	/**
	 * A fragment hit is kept only when that field holds the same address. A longer address is dropped.
	 *
	 * @return void
	 */
	public function testMailKeepsOnlyTheExactAddressAfterTheFragment(): void {
		$email = 'ada@example.com';
		$ports = new PreparationDouble(PreparationDouble::json([self::event(['attendees' => [$email]])]),
			static function (string $userId, array $arguments) use ($email): array {
				$field = isset($arguments['from']) ? 'from' : (isset($arguments['to']) ? 'to' : 'cc');
				self::assertSame([$email], $arguments[$field]);
				self::assertSame('2026-03-10', $arguments['since']);
				self::assertSame('2026-04-09', $arguments['until']);
				self::assertSame(20, $arguments['limit']);
				self::assertArrayNotHasKey('body', $arguments);
				return PreparationDouble::json(['messages' => [
					self::message(1, $field, $email),
					self::message(2, $field, $email . '.evil'),
				], 'folders' => [['mailboxId' => 3]], 'hasMore' => false]);
			});
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		self::assertSame([1], array_column($dossier['sources']['mail']['candidates'], 'id'));
		self::assertSame('exact_from', $dossier['sources']['mail']['candidates'][0]['reason']);
		self::assertFalse($dossier['sources']['mail']['truncated']);
	}

	/**
	 * Mail, Deck and files without their own grant are named and not called. The dossier still returns.
	 *
	 * @return void
	 */
	public function testASourceWithoutItsGrantIsOmitted(): void {
		$item = self::event(['attendees' => ['ada@example.com'], 'cardIds' => [4], 'filePaths' => ['/Notes/a.md']]);
		$ports = new PreparationDouble(PreparationDouble::json([$item]));
		$dossier = $this->dossier($this->tool($ports, ['calendar.read']), self::window());
		self::assertSame(['events'], array_column($ports->calls, 0));
		foreach (['mail', 'deck', 'files'] as $source) {
			self::assertSame('not_consulted', $dossier['sources'][$source]['status']);
			self::assertSame('grant', $dossier['sources'][$source]['reason']);
			self::assertSame([], $dossier['sources'][$source]['candidates']);
		}
		self::assertSame('not_consulted', $dossier['participants'][0]['account']);
		self::assertNull($dossier['participants'][0]['uid']);
	}

	/**
	 * A port that is not bound is unavailable. It is not treated as a grant and it is not called.
	 *
	 * @return void
	 */
	public function testAnUnboundSourceIsUnavailable(): void {
		$ports = new PreparationDouble(PreparationDouble::json([self::event(['attendees' => ['ada@example.com']])]));
		$dossier = $this->dossier($this->tool($ports, self::ON, false), self::window());
		self::assertSame(['events'], array_column($ports->calls, 0));
		foreach (['mail', 'deck', 'files'] as $source) {
			self::assertSame('unavailable', $dossier['sources'][$source]['status']);
			self::assertSame('unbound', $dossier['sources'][$source]['reason']);
		}
	}

	/**
	 * Messages, participants and folders stop at the budget. The answer says it was cut and does not echo a hidden count.
	 *
	 * @return void
	 */
	public function testTruncationIsExplicitAndDoesNotEchoACount(): void {
		$emails = [];
		for ($i = 1; $i <= 11; $i++) {
			$emails[] = 'p' . $i . '@example.com';
		}
		$messages = [];
		for ($id = 1; $id <= 21; $id++) {
			$messages[] = self::message($id, 'from', 'p1@example.com');
		}
		$folders = [];
		for ($id = 1; $id <= 21; $id++) {
			$folders[] = ['mailboxId' => $id];
		}
		$messages[] = self::message(90, 'from', 'p1@example.com') + ['mailboxId' => 21];
		$ports = new PreparationDouble(PreparationDouble::json([self::event(['attendees' => $emails])]),
			static fn (string $userId, array $arguments): array => PreparationDouble::json([
				'messages' => $messages, 'folders' => $folders, 'hasMore' => false,
			]));
		$encoded = json_encode($this->dossier($this->tool($ports, ['calendar.read', 'mail.messages']), self::window()), JSON_THROW_ON_ERROR);
		$dossier = json_decode($encoded, true);
		self::assertTrue($dossier['participantsTruncated']);
		self::assertCount(10, $dossier['participants']);
		self::assertTrue($dossier['sources']['mail']['truncated']);
		self::assertSame('folder_budget', $dossier['sources']['mail']['reason']);
		self::assertLessThanOrEqual(20, count($dossier['sources']['mail']['candidates']));
		self::assertStringNotContainsString('p11@example.com', $encoded);
		self::assertStringNotContainsString('"id": 21', $encoded);
		self::assertStringNotContainsString('"id": 90', $encoded);
		self::assertStringNotContainsString('21', $encoded);
	}

	/**
	 * An unreadable card is omitted. A traversal path is not read. A Deck link path is read as that path.
	 *
	 * @return void
	 */
	public function testAnUnreadableCardIsOmittedAndOnlyExplicitPathsAreRead(): void {
		$item = self::event(['cardIds' => [7, 8], 'filePaths' => ['../secret', '/Notes/a.md']]);
		$ports = new PreparationDouble(
			PreparationDouble::json([$item]),
			null,
			static function (string $userId, array $arguments): array {
				if ($arguments['cardId'] === 7) {
					throw new ToolFailure('hidden card 7');
				}
				return PreparationDouble::json(['title' => 'Brief', 'url' => 'https://cloud/card/8']);
			},
			static fn (string $userId, array $arguments): array => PreparationDouble::json(['items' => [['path' => 'Notes/brief.md']]]),
			static fn (string $userId, array $arguments): array => ['content' => [
				['type' => 'text', 'text' => 'notes'],
				['type' => 'text', 'text' => json_encode(['path' => $arguments['path'], 'etag' => 'e1'], JSON_THROW_ON_ERROR)],
			]],
		);
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		self::assertSame([8], array_column($dossier['sources']['deck']['candidates'], 'cardId'));
		self::assertStringNotContainsString('hidden card', json_encode($dossier, JSON_THROW_ON_ERROR));
		$files = $dossier['sources']['files']['candidates'];
		self::assertSame(['/Notes/a.md', '/Notes/brief.md'], array_column($files, 'path'));
		self::assertSame(['event_file', 'deck_link'], array_column($files, 'reason'));
		foreach ($ports->calls as [$name, $arguments]) {
			self::assertStringNotContainsString('..', (string)($arguments['path'] ?? ''));
		}
	}

	/**
	 * people.read plus one exact hit yields the account. Two exact hits, and a partial hit, yield none.
	 *
	 * @return void
	 */
	public function testOneExactAccountIsKeptAndASecondIsNot(): void {
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willReturnCallback(static function (string $email): array {
			if ($email === 'one@example.com') {
				return [[
					'exact' => ['users' => [['value' => ['shareWith' => 'pedro'], 'subline' => 'one@example.com']]],
					'users' => [['value' => ['shareWith' => 'wide'], 'subline' => 'one@example.com']],
				], false];
			}
			return [['exact' => ['users' => [
				['value' => ['shareWith' => 'a'], 'subline' => 'two@example.com'],
				['value' => ['shareWith' => 'b'], 'subline' => 'two@example.com'],
			]]], false];
		});
		$item = self::event(['attendees' => ['one@example.com', 'two@example.com']]);
		$ports = new PreparationDouble(PreparationDouble::json([$item]));
		$dossier = $this->dossier($this->tool($ports, self::ON, true, $search), self::window());
		self::assertSame(['pedro', null], array_column($dossier['participants'], 'uid'));
		self::assertSame(['exact_or_none', 'exact_or_none'], array_column($dossier['participants'], 'account'));
	}

	/**
	 * A reader failure that carries a count becomes unavailable and the count is not repeated.
	 *
	 * @return void
	 */
	public function testAMailReaderFailureDoesNotEchoACount(): void {
		$ports = new PreparationDouble(PreparationDouble::json([self::event(['attendees' => ['ada@example.com']])]),
			static function (string $userId, array $arguments): array {
				throw new ToolFailure('too many folders: 101');
			});
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		self::assertSame('unavailable', $dossier['sources']['mail']['status']);
		self::assertSame('reader', $dossier['sources']['mail']['reason']);
		self::assertStringNotContainsString('101', json_encode($dossier, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param list<string> $grants module.operation pairs that are granted
	 * @param bool $optional whether mail, Deck and files are bound
	 * @return PrepareMeeting the tool over the double
	 */
	private function tool(PreparationDouble $ports, array $grants, bool $optional = true, ?ISearch $search = null): PrepareMeeting {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturnCallback(
			static fn (string $uid, string $module, string $operation): bool => in_array($module . '.' . $operation, $grants, true),
		);
		$sources = new PreparationSources($ports, $optional ? $ports : null, $optional ? $ports : null, $optional ? $ports : null);
		$roster = new ParticipantRoster($policy, new AccountEmailMatch($search ?? $this->createMock(ISearch::class)));
		return new PrepareMeeting(new PrepareMeetingHandler(
			$sources, $policy, $roster, new MailCandidates($sources, $policy), new CardCandidates($sources, $policy), new FileCandidates($sources, $policy),
		));
	}

	/**
	 * @return array<string, mixed> the decoded dossier
	 */
	private function dossier(PrepareMeeting $tool, array $arguments): array {
		$result = $tool->execute($arguments, new ToolContext('alice'));
		$decoded = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @param array<string, mixed> $extra fields the reader attached
	 * @return array<string, mixed> one occurrence
	 */
	private static function event(array $extra = []): array {
		return $extra + ['uid' => 'evt-1', 'summary' => 'Planning', 'start' => '2026-04-09T10:00:00Z', 'end' => '2026-04-09T11:00:00Z',
			'calendar' => '/calendars/alice/personal/', 'location' => '', 'allDay' => false];
	}

	/**
	 * @return array<string, mixed> the window of the dossier
	 */
	private static function window(): array {
		return ['from' => '2026-04-08T00:00:00Z', 'to' => '2026-04-10T00:00:00Z'];
	}

	/**
	 * @return array<string, mixed> one message whose named field holds the address
	 */
	private static function message(int $id, string $field, string $email): array {
		return ['id' => $id, 'subject' => 'Hello', 'date' => '2026-04-01', 'url' => 'https://cloud/m/' . $id, 'mailboxId' => 3,
			$field => ['count' => 1, 'addresses' => [['name' => 'A', 'email' => $email]]]];
	}
}
