<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Deck\Db\Card;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Locating\{CalendarStore,Classification,EventRepository};
use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Throwable;

require_once __DIR__ . '/../Deck/Stubs/deck_stubs.php';

/** Original cross-module reference outcomes and exact read order, frozen before changing their boundaries. */
final class ReferenceCharacterizationTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../../../fixtures/talk-reference-characterization.json';

	public function testReferenceReadsAndFailuresStayIdentical(): void {
		$recorded = [];
		foreach (['card', 'board-mismatch', 'card-denied', 'PUBLIC', 'PRIVATE', 'CONFIDENTIAL', 'deleted', 'invalid-ics', 'missing', 'no-grant', 'scope-unavailable'] as $scenario) {
			$trace = [];
			$deck = $this->createMock(DeckGatewayInterface::class);
			$deck->method('findCard')->willReturnCallback(static function (string $uid, int $id) use (&$trace, $scenario): Card {
				$trace[] = ['findCard', $uid, $id];
				if ($scenario === 'card-denied') { throw new \RuntimeException('native denial /alice/private'); }
				return new Card(['id' => $id, 'title' => "Line one\nLine two"]);
			});
			$deck->method('cardBoardId')->willReturnCallback(static function (string $uid, int $id) use (&$trace): int { $trace[] = ['cardBoardId', $uid, $id]; return 4; });
			$store = new FakeCalendarStore();
			$store->addCalendar('principals/users/alice', 3, 'events', 'principals/users/bob');
			$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event1\r\nDTSTART:20261001T100000Z\r\nSUMMARY:Planning\r\nCLASS:$scenario\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
			if ($scenario !== 'missing') { $store->addObject(3, 'event.ics', $scenario === 'invalid-ics' ? 'invalid' : $ics); }
			if ($scenario === 'deleted') { $store->objects[3]['event.ics']['deleted'] = true; }
			$scope = new ScopeStub(); $scope->unavailable = $scenario === 'scope-unavailable';
			$apps = $this->createMock(IAppManager::class); $apps->method('isEnabledForUser')->willReturn(true);
			$users = $this->createMock(IUserManager::class); $users->method('get')->willReturn($this->createMock(IUser::class));
			$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
			$policy->setGrant('alice', 'deck', 'read', $scenario !== 'no-grant');
			$policy->setGrant('alice', 'calendar', 'read', true);
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $params): string => $route . ':' . json_encode($params));
			$urls->method('linkToRoute')->willReturn('/apps/calendar/');
			$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example' . $url);
			$urls->method('getWebroot')->willReturn('/cloud');
			$classification = new Classification();
			$linker = new ReferenceLinker($deck, ScopeStub::access($store, $scope), $store, new EventRepository($store, $classification), $classification, $apps, $users, $policy, $urls);
			$args = in_array($scenario, ['card', 'board-mismatch', 'card-denied', 'no-grant'], true)
				? ['type' => 'deck_card', 'card_id' => 7, 'board_id' => $scenario === 'board-mismatch' ? 9 : 4]
				: ['type' => 'calendar_event', 'calendar' => '/remote.php/dav/calendars/alice/events/', 'uid' => 'event1'];
			try { $result = $linker->resolve('alice', $args); }
			catch (Throwable $e) { $result = ['failure' => substr($e::class, strrpos($e::class, '\\') + 1), 'message' => $e->getMessage(), 'previous' => $e->getPrevious()?->getMessage()]; }
			$recorded[$scenario] = ['result' => $result, 'deck' => $trace, 'scope' => $scope->asked];
			// Compare the original linker against the owning-module ports before switching production to them.
			if ($scenario === 'no-grant') { continue; } // This gate belongs to the caller, not the read port.
			$oldTrace = $trace; $oldScope = $scope->asked; $trace = []; $scope->asked = [];
			try {
				if ($args['type'] === 'deck_card') {
					$read = (new \OCA\Mcp\Tools\Deck\DeckCardReference($deck))->read('alice', $args['card_id'], $args['board_id']);
				} else {
					$read = (new \OCA\Mcp\Modules\Calendar\CalendarEventReference(ScopeStub::access($store, $scope), $store, new EventRepository($store, $classification), $classification))
						->read('alice', $args['calendar'], $args['uid']);
				}
				if ($read->item === null) {
					$actual = ['failure' => 'ConversationAccessException', 'message' => \OCA\Mcp\Modules\Talk\Presentation\Messages::referenceNotFound(), 'previous' => $read->failure?->getMessage()];
				} else {
					$item = $read->item;
					$title = $item['title'];
					if ($args['type'] === 'calendar_event' && $title === '') { $title = \OCA\Mcp\Modules\Talk\Presentation\Messages::referenceUntitled(); }
					$url = $args['type'] === 'deck_card'
						? $urls->linkToRouteAbsolute('deck.page.indexCard', ['boardId' => $item['boardId'], 'cardId' => $args['card_id']])
						: $urls->getAbsoluteURL($urls->linkToRoute('calendar.view.index') . 'edit/' . base64_encode($urls->getWebroot() . $item['path'] . rawurlencode($item['uri'])));
					$actual = ['type' => $args['type'], 'title' => trim((string)preg_replace('/\s+/u', ' ', $title)), 'url' => $url];
				}
			} catch (Throwable $e) {
				$actual = ['failure' => substr($e::class, strrpos($e::class, '\\') + 1), 'message' => $e->getMessage(), 'previous' => $e->getPrevious()?->getMessage()];
			}
			self::assertSame($result, $actual, $scenario . ': projection and original failure cause');
			self::assertSame($oldTrace, $trace, $scenario . ': native Deck read order');
			self::assertSame($oldScope, $scope->asked, $scenario . ': scoped calendar lookup');

		}
		$json = json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('MCP_TALK_CHARACTERIZE_WRITE') === '1') { file_put_contents(self::FIXTURE, $json); }
		self::assertSame((string)file_get_contents(self::FIXTURE), $json);
	}
}
