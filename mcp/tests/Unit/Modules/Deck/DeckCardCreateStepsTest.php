<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use InvalidArgumentException;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Deck\Creation\CreateCardPreview;
use OCA\Mcp\Modules\Deck\DeckCardCreateSteps;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Platform\MailCards\MailCardStep;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * The card step of a mail card goes through the very plan and effect of deck_create_card, behind the registry's own
 * ToolAccess. The only Deck writes it can reach are the card and its assignees: no file link, no share, no delete.
 */
final class DeckCardCreateStepsTest extends TestCase {
	use DeckTestHelpers;

	/** The gateway writes a mail card step must never reach. */
	private const FORBIDDEN_WRITES = ['updateCard', 'moveCard', 'deleteCard', 'createBoard', 'createStack', 'deleteEmptyStack', 'deleteEmptyBoard',
		'unassignCardUser', 'unlinkCardFile', 'changeCardLabel', 'changeDependency'];
	/** A description carrying the private reference a mail card writes. */
	private const CARD = ['stackId' => 10, 'title' => 'Pedido 42', 'description' => 'Arquivo: /Documentos/pedido.pdf'];
	private GrantPolicy $policy;
	/** Whether the Deck app is enabled for the actor. */
	private bool $appEnabled = true;
	/** @var DeckGatewayInterface&MockObject */
	private DeckGatewayInterface $gateway;
	private DeckCardCreateSteps $steps;
	/** The Deck module whose execution context the steps use; a test hands it another gateway. */
	private \OCA\Mcp\Modules\Deck\DeckModule $module;

	/** Builds the plan and the execution context of deck_create_card over a gateway that refuses every other write. @return void */
	protected function setUp(): void {
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$this->policy->setGrant('alice', 'deck', 'create', true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'deck' && $this->appEnabled);
		$apps->method('getAppVersion')->willReturn('1.17.5');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$this->gateway = $this->gatewayOwnedBy('alice');
		foreach (self::FORBIDDEN_WRITES as $write) {
			$this->gateway->expects(self::never())->method($write);
		}
		$this->gateway->method('createCard')->willReturnCallback(fn (string $user, int $stack, string $title, string $description): \OCA\Deck\Db\Card => $this->card(['id' => 9, 'stackId' => $stack, 'title' => $title, 'description' => $description]));
		// No other service of the container is reachable: a share or file link would have to ask for one.
		$this->module = DeckTestModule::build($this->createMock(ContainerInterface::class), $apps, new NullLogger(), $this->createMock(IURLGenerator::class),
			$this->createMock(ITimeFactory::class), $users);
		DeckTestModule::setGateway($this->module, $this->gateway);
		$context = DeckTestModule::context($this->module);
		$access = new ToolAccess($this->policy, $apps, $users, null, new CapabilityRegistry([], new NullLogger()));
		$this->steps = new DeckCardCreateSteps($access, new CreateCardPreview($context), $context);
	}

	/** The plan is the one of deck_create_card and writes nothing. @return void */
	public function testThePlanIsTheOneOfDeckCreateCardAndWritesNothing(): void {
		$this->gateway->expects(self::never())->method('createCard');

		$plan = $this->steps->planCard('alice', self::CARD);

		self::assertSame('deck_create_card', $plan['action']);
		self::assertSame('Arquivo: /Documentos/pedido.pdf', $plan['card']['description']);
		self::assertSame([], $plan['shared']);
	}

	/** The confirmed step creates exactly one card with the reference in its description, and nothing else. @return void */
	public function testTheConfirmedStepCreatesOneCardWithTheReferenceAndNoShare(): void {
		$this->gateway->expects(self::once())->method('createCard')->with('alice', 10, 'Pedido 42', 'Arquivo: /Documentos/pedido.pdf', null);

		$step = $this->steps->createCard('alice', self::CARD);

		self::assertSame(MailCardStep::APPLIED, $step->status, (string)$step->message);
		self::assertSame(9, $step->ids['cardId']);
		self::assertSame('Arquivo: /Documentos/pedido.pdf', $step->result['description']);
	}

	/** deck_create_card has no file argument, so a mail card cannot attach or share a file through it. @return void */
	public function testAFileArgumentIsRefusedByThePublishedSchema(): void {
		$this->gateway->expects(self::never())->method('createCard');
		$this->expectException(InvalidArgumentException::class);
		$this->steps->createCard('alice', self::CARD + ['attachment' => '/Documentos/pedido.pdf']);
	}

	/** @return array<string, array{\Closure(self): void}> the registry gates of deck_create_card */
	public static function refusedByTheRegistry(): array {
		return [
			'deck.create not granted' => [static function (self $test): void { $test->policy->setGrant('alice', 'deck', 'create', false); }],
			'Deck app off for the actor' => [static function (self $test): void { $test->appEnabled = false; }],
		];
	}

	/**
	 * Without the grant or the app, neither the plan nor the step reaches Deck.
	 *
	 * @param \Closure(self): void $deny the gate to close
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusedByTheRegistry')]
	public function testTheRegistryGateRefusesBothThePlanAndTheStep(\Closure $deny): void {
		$deny($this);
		$this->gateway->expects(self::never())->method('createCard');
		$this->gateway->expects(self::never())->method('stackOwnership');

		$step = $this->steps->createCard('alice', self::CARD);
		self::assertSame(MailCardStep::FAILED, $step->status);
		self::assertStringContainsString('deck_create_card', (string)$step->message);
		$this->expectException(ToolFailure::class);
		$this->steps->planCard('alice', self::CARD);
	}

	/** A board of somebody else needs confirm_shared, then creates the card. @return void */
	public function testABoardOfSomebodyElseNeedsConfirmShared(): void {
		$gateway = $this->gatewayOwnedBy('pedro', 'Equipe');
		$gateway->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
		DeckTestModule::setGateway($this->module, $gateway);

		self::assertSame(MailCardStep::FAILED, $this->steps->createCard('alice', self::CARD)->status);
		self::assertSame(MailCardStep::APPLIED, $this->steps->createCard('alice', self::CARD + ['confirm_shared' => true])->status);
	}

	/** A stack the board ACL hides is refused with no card. @return void */
	public function testAStackTheBoardAclHidesIsRefused(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('stackOwnership')->willThrowException(new NoPermissionException('no access'));
		$gateway->expects(self::never())->method('createCard');
		DeckTestModule::setGateway($this->module, $gateway);

		self::assertSame(MailCardStep::FAILED, $this->steps->createCard('alice', self::CARD)->status);
		$this->expectException(ToolFailure::class);
		$this->steps->planCard('alice', self::CARD);
	}

	/** An assignee outside the board is refused before the card exists. @return void */
	public function testAnAssigneeOutsideTheBoardIsRefusedBeforeTheCard(): void {
		$this->gateway->method('validateAssignees')->willThrowException(new InvalidArgumentException('Invalid argument: assignees'));
		$this->gateway->expects(self::never())->method('createCard');

		$this->expectException(InvalidArgumentException::class);
		$this->steps->createCard('alice', self::CARD + ['assignees' => ['mallory']]);
	}

	/** An assignment that fails after the card exists is partial, with the card kept and never deleted. @return void */
	public function testAFailedAssignmentAfterTheCardIsPartial(): void {
		$this->gateway->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
		$this->gateway->method('assignCardUser')->willThrowException(new RuntimeException('push failed'));

		$step = $this->steps->createCard('alice', self::CARD + ['assignees' => ['pedro']]);

		self::assertSame(MailCardStep::PARTIAL, $step->status);
		self::assertSame(9, $step->ids['cardId']);
		self::assertNotEmpty($step->message);
	}

	/** A card found again only by likeness after a failed write is uncertain, with its id and nothing written on it. @return void */
	public function testAProbableCardIsUncertain(): void {
		$gateway = $this->gatewayOwnedBy('alice');
		$gateway->method('createCard')->willThrowException(new RuntimeException('activity failed'));
		$gateway->method('findCreatedCard')->willReturn($this->card(['id' => 9]));
		$gateway->expects(self::never())->method('assignCardUser');
		DeckTestModule::setGateway($this->module, $gateway);

		$step = $this->steps->createCard('alice', self::CARD);

		self::assertSame(MailCardStep::UNCERTAIN, $step->status);
		self::assertSame(['cardId' => 9], $step->ids);
	}
}
