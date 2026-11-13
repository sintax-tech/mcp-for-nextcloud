<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCA\Mcp\Modules\Deck\Labels\LabelSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/** The native side of the board label tools: MANAGE first, catalog and usage, LabelService arguments, rechecks. */
final class BoardLabelsGatewayTest extends TestCase {
	/** @var array<string, \PHPUnit\Framework\MockObject\MockObject> */
	private array $services = [];
	private DeckServiceGateway $gateway;
	private array $boardFields = [];
	/** @var list<int> */
	private array $checks = [];

	/** @return void Fresh native service doubles for an authenticated board manager. */
	protected function setUp(): void {
		foreach ([BoardService::class, CardService::class, PermissionService::class, LabelMapper::class, LabelService::class, StackMapper::class, \OCP\IUserManager::class] as $class) {
			$this->services[$class] = $this->createMock($class);
		}
		$this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(function ($mapper, $id, int $permission): bool {
			$this->checks[] = $permission;
			return true;
		});
		$this->services[BoardService::class]->method('findAll')->with(-2, false, true)->willReturnCallback(fn (): array => [
			new Board($this->boardFields + ['id' => 20, 'owner' => 'alice', 'title' => 'Board'])]);
		$this->services[LabelMapper::class]->method('findAll')->with(20)->willReturn([
			new Label(['id' => 2, 'boardId' => 20, 'title' => 'Feature', 'color' => '317CCC']), new Label(['id' => 1, 'boardId' => 20, 'title' => 'bug', 'color' => 'ff7a66'])]);
		$this->services[LabelMapper::class]->method('findAssignedLabelsForBoard')->with(20)->willReturn([
			new Label(['id' => 1]), new Label(['id' => 1]), new Label(['id' => 2])]);
		$this->services[LabelMapper::class]->method('findBoardId')->willReturnCallback(static fn (int $id): ?int => $id === 99 ? null : 20);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $this->services[$id]);
		$session = $this->createMock(\OCP\ISession::class);
		$session->method('get')->with('user_id')->willReturn('alice');
		$this->gateway = new DeckServiceGateway($container, $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), null, $session);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCatalogNeedsManageAndCountsCardAssociations(): void {
		$state = $this->gateway->boardLabels()->boardLabelState('alice', 20);
		self::assertSame([Acl::PERMISSION_MANAGE], $this->checks);
		self::assertSame([1, 2], array_column($state['labels'], 'id'));
		self::assertSame('317ccc', $state['labels'][1]['color']);
		self::assertSame([1 => 2, 2 => 1], $state['usage']);
		self::assertSame('Board', $state['board']['name']);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testArchivedBoardIsRefused(): void {
		$this->boardFields = ['archived' => true];
		$this->expectException(DeckRefusalException::class);
		$this->gateway->boardLabels()->boardLabelState('alice', 20);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testMissingAndForeignLabelsLookAlike(): void {
		foreach ([99, 3] as $missing) {
			try {
				$this->gateway->boardLabels()->labelState('alice', $missing);
				self::fail('label ' . $missing . ' found');
			} catch (NoPermissionException) {
			}
		}
		self::assertSame(2, $this->gateway->boardLabels()->labelState('alice', 1)['cards']);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testCreateUsesTheNativePositionalOrder(): void {
		$this->services[LabelService::class]->expects(self::once())->method('create')->with('sev:high', 'aa0000', 20)
			->willReturn(new Label(['id' => 3, 'boardId' => 20, 'title' => 'sev:high', 'color' => 'aa0000']));
		self::assertSame(['id' => 3, 'boardId' => 20, 'title' => 'sev:high', 'color' => 'aa0000'], $this->gateway->boardLabels()->createLabel('alice', 20, 'sev:high', 'aa0000'));
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testUpdateAndDeleteRecheckThePlanBeforeTheNativeCall(): void {
		$state = $this->gateway->boardLabels()->labelState('alice', 1);
		$this->services[LabelService::class]->expects(self::once())->method('update')->with(1, 'defect', '00ff00')
			->willReturn(new Label(['id' => 1, 'boardId' => 20, 'title' => 'defect', 'color' => '00ff00']));
		$this->gateway->boardLabels()->updateLabel('alice', 1, 'defect', '00ff00', LabelSnapshot::of($state, false));
		$this->services[LabelService::class]->expects(self::never())->method('delete');
		$stale = LabelSnapshot::of($state, true);
		$stale['cards'] = 1;
		$this->expectException(DeckConflictException::class);
		$this->gateway->boardLabels()->deleteLabel('alice', 1, $stale);
	}

	/** @return void Verifies the label operation against the native boundary. */
	public function testStackLabelsAskEditOnTheListAndReportManage(): void {
		$this->services[StackMapper::class]->method('findBoardId')->with(10)->willReturn(20);
		$this->services[PermissionService::class]->method('getPermissions')->with(20, 'alice')->willReturn([Acl::PERMISSION_MANAGE => false]);
		$state = $this->gateway->boardLabels()->stackLabels('alice', 10);
		self::assertSame([Acl::PERMISSION_EDIT], $this->checks);
		self::assertSame(['boardId' => 20, 'manage' => false], ['boardId' => $state['boardId'], 'manage' => $state['manage']]);
		self::assertCount(2, $state['labels']);
	}
	/** @return void Missing native service methods fail closed during preflight, before card creation. */
	public function testMissingNativeLabelApiIsRefusedBeforeEffects(): void {
		$this->services[LabelService::class] = new \stdClass();
		$this->services[CardService::class]->expects(self::never())->method('create');
		$this->expectException(NoPermissionException::class);
		$this->gateway->boardLabels()->stackLabels('alice', 10);
	}

}
