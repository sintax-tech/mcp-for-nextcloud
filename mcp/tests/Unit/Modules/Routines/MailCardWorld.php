<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Deck\Creation\CreateCardPreview;
use OCA\Mcp\Modules\Deck\DeckCardCreateSteps;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Files\Creation\{FileCreation, ProtectedFileDestination};
use OCA\Mcp\Modules\Mail\Integration\{MailGateway, MailMessageGateway, MailSaveReader};
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\{MailAttachmentSaveSteps, MailCardSourceSteps};
use OCA\Mcp\Modules\Mail\Reading\MailMessageReading;
use OCA\Mcp\Modules\Mail\Writing\{MailSaveBudget, MailSaveContext, MailSavePreflight, SaveAttachmentHandler, SaveAttachmentPreview};
use OCA\Mcp\Modules\Routines\MailTasks\{CreateMailCardHandler, CreateMailCardPreview, MailCardPlans};
use OCA\Mcp\Modules\Routines\Tools\CreateMailCard;
use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Platform\FileAccess\{ActorRoot, SharedWriteGuard};
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Tests\Unit\Access\ResourceScope\FilesScopes;
use OCA\Mcp\Tests\Unit\Modules\Deck\DeckAudienceFixture;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule;
use OCA\Mcp\Tests\Unit\Tools\Files\{FilesCatalogWiring, FilesToolsTestCase};
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMailLinks, FakeMailMessageApp, FakeMimePart, MailReplacementFixture, MailTestModule};
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * routines_create_mail_card over the real Mail, Files and Deck ports: the native Mail double with one message and its
 * PDF, alice's Files tree behind a scope-aware gate, and a Deck gateway double whose only writes are the card and its
 * assignees. Every grant, app and Guests decision is the registry's own ToolAccess over one policy.
 */
abstract class MailCardWorld extends FilesToolsTestCase {
	/** The bytes of the attachment. */
	protected const BYTES = "%PDF-1.4\x00\xff mail card";
	/** Where the attachment is saved. */
	protected const TARGET = '/alice/files/Documentos/pedido.pdf';
	protected FakeMailMessageApp $mail;
	protected DeckGatewayInterface $gateway;
	protected DeckAudienceFixture $readers;
	protected CreateMailCard $tool;
	/** @var array<string, bool> enablement of each app for alice */
	protected array $enabled = ['mail' => true, 'deck' => true];
	/** @var list<string>|null apps Guests allows alice, null without Guests */
	protected ?array $guestApps = null;
	/** @var list<string>|null selected Files scope folder ids, null for unrestricted */
	protected ?array $items = null;
	/** @var list<array{int, string, string}> cards the gateway created: stack, title, description */
	protected array $created = [];
	/** The registry over the routines module, with the same policy, apps and Guests as the ports. */
	protected \OCA\Mcp\Mcp\Tools\ToolRegistry $registry;

	/** Grants every permission the routine needs and wires the three real ports. @return void */
	protected function setUp(): void {
		parent::setUp();
		$this->mail = (new FakeMailMessageApp())->withAccount(1, 'alice', 'Private', 'alice@example.com');
		$this->mail->withFolder(1, ['id' => 1, 'name' => 'INBOX']);
		$this->mail->withMessage(1, ['id' => 7, 'subject' => 'Pedido 42', 'size' => 2000, 'threadRootId' => 'root-7', 'body' => 'Segue o pedido.',
			'structure' => FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), new FakeMimePart('application/pdf', 'pedido.pdf', 'attachment')]),
			'attachmentContents' => ['2' => self::BYTES], 'attachmentList' => [['id' => '2', 'fileName' => 'pedido.pdf', 'mime' => 'application/pdf', 'size' => strlen(self::BYTES)]]]);
		$this->tree->addFolder('/alice/files/Outros');
		$this->tree->addFolder('/alice/files/Leitura', ['permissions' => \OCP\Constants::PERMISSION_READ]);
		$this->tree->ops = [];
		foreach (['mail' => ['messages', 'save'], 'deck' => ['create'], 'routines' => ['mailtask']] as $module => $operations) {
			foreach ($operations as $operation) {
				$this->sharePolicy->setGrant('alice', $module, $operation, true);
			}
		}
		$this->wire();
	}

	/** @return void the routine over the three ports and the registry gate of each original tool */
	protected function wire(): void {
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $app === 'deck' ? '1.17.5' : '5.10.11');
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $this->enabled[$app] ?? false);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$guests = $this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(fn (string $uid, string $app): bool => $this->guestApps === null || $app === '' || in_array($app, $this->guestApps, true));
		$access = $this->access($apps, $users, $guests);
		$config = $this->createStub(\OCP\IConfig::class);
		$config->method('getUserValue')->willReturn('UTC');
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$source = new MailCardSourceSteps($access, new MailMessageReading($this->mail, new MailGateway($this->mail), new UserTimezone($config), FakeMailLinks::create($this)));
		$save = $this->saveContext($apps);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://cloud.example/f/' . $parameters['fileid']);
		$files = new MailAttachmentSaveSteps($access, new SaveAttachmentPreview($save), new SaveAttachmentHandler($save), $urls, new NullLogger());
		$this->readers = new DeckAudienceFixture($this);
		$cards = new DeckCardCreateSteps($access, ...$this->deck($apps, $users));
		$plans = new MailCardPlans($source, $files, $cards, new PlanState($config));
		$preview = new CreateMailCardPreview($plans);
		$this->tool = new CreateMailCard(new CreateMailCardHandler($plans, $preview, $files, $cards, new NullLogger()), $preview);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === CreateMailCard::class ? $this->tool : throw new \LogicException('Unexpected service ' . $id));
		$this->registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([new \OCA\Mcp\Modules\Routines\RoutinesModule($container)], $this->grants(), $apps, $users,
			new NullLogger(), $guests, MailTestModule::capabilities($apps));
	}

	/** @return \OCA\Mcp\Access\Grants\GrantPolicy the grants every port and the registry read */
	protected function grants(): \OCA\Mcp\Access\Grants\GrantPolicy {
		return $this->sharePolicy;
	}

	/**
	 * @param \OCP\App\IAppManager $apps the installed and enabled apps
	 * @param \OCP\IUserManager $users the accounts
	 * @param \OCA\Mcp\Access\Guests\GuestRestrictions $guests Guests as {@see self::$guestApps} sets it
	 * @return ToolAccess the registry's own decision on grant, Guests, apps and the attachment capability
	 */
	protected function access(\OCP\App\IAppManager $apps, \OCP\IUserManager $users, \OCA\Mcp\Access\Guests\GuestRestrictions $guests): ToolAccess {
		return new ToolAccess($this->grants(), $apps, $users, $guests, MailTestModule::capabilities($apps));
	}

	/**
	 * @param \OCP\App\IAppManager $apps the installed and enabled apps
	 * @param \OCP\IUserManager $users the accounts
	 * @return array{CreateCardPreview, \OCA\Mcp\Modules\Deck\DeckExecutionContext, \OCA\Mcp\Modules\Deck\Integration\DeckBoardAudience} the work of deck_create_card over the gateway double
	 */
	private function deck(\OCP\App\IAppManager $apps, \OCP\IUserManager $users): array {
		$this->gateway = $this->createMock(DeckGatewayInterface::class);
		$this->gateway->method('stackOwnership')->willReturn(['owner' => 'alice', 'ownerDisplayName' => 'Alice Silva', 'name' => 'Compras']);
		foreach (['updateCard', 'moveCard', 'deleteCard', 'createBoard', 'createStack', 'deleteEmptyStack', 'deleteEmptyBoard', 'unassignCardUser', 'unlinkCardFile', 'changeCardLabel', 'changeDependency'] as $write) {
			$this->gateway->expects(self::never())->method($write);
		}
		$this->gateway->method('createCard')->willReturnCallback(function (string $user, int $stack, string $title, string $description): \OCA\Deck\Db\Card {
			$this->created[] = [$stack, $title, $description];
			return new \OCA\Deck\Db\Card(['id' => 9, 'stackId' => $stack, 'title' => $title, 'description' => $description, 'type' => 'plain', 'owner' => 'alice', 'order' => 0, 'archived' => false, 'deletedAt' => 0, 'lastModified' => 1]);
		});
		$module = DeckTestModule::build($this->createMock(\Psr\Container\ContainerInterface::class), $apps, new NullLogger(), $this->createMock(IURLGenerator::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), $users);
		DeckTestModule::setGateway($module, $this->gateway);
		$context = DeckTestModule::context($module);
		return [new CreateCardPreview($context), $context, $this->readers->audience()];
	}

	/**
	 * @param \OCP\App\IAppManager $apps installed Mail version
	 * @return MailSaveContext the save work of mail_save_attachment over a gate that reads {@see self::$items} as the Files scope
	 */
	private function saveContext(\OCP\App\IAppManager $apps): MailSaveContext {
		$root = $this->visibilityRoot();
		$scope = FilesScopes::over($this, new VisibilityGuard($this->config->mock($this), $this->tagMapper, $root), $root, fn (): ?array => $this->items);
		$visibility = new FilesAccessGuard($this->config->mock($this), $this->tagMapper, $root, null, null, null, $scope);
		$wiring = FilesCatalogWiring::of($this->module);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$access = $wiring->named('accessInfo');
		$creation = new FileCreation($access, new SharedWriteGuard($access), $wiring->named('checkout'), $this->filenames, $visibility, $this->creationLogger,
			$wiring->named('locks'), (new FileTypePolicyFixture())->guard($this), new BackupLocation($this->createMock(\OCA\Mcp\Platform\Backups\BackupSettingsDocument::class)));
		$files = new ProtectedFileDestination(new ActorRoot($root, $session), $creation, new SharedWriteGuard($access), $access, $visibility, $this->createMock(\Psr\Log\LoggerInterface::class));
		return new MailSaveContext($this->grants(), $this->mail, $apps, new MailMessageLocator(new MailGateway($this->mail), new MailMessageGateway($this->mail)),
			new MailSaveReader($this->mail, new MailSavePreflight()), new MailSaveBudget($this->config->mock($this)), $files, new MailReplacementFixture());
	}

	/**
	 * @param array<string, mixed> $extra arguments added or replaced
	 * @return array<string, mixed> the arguments of a mail card
	 */
	protected static function arguments(array $extra = []): array {
		return $extra + ['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/pedido.pdf', 'stackId' => 10, 'title' => 'Pedido 42'];
	}

	/**
	 * @param array<string, mixed> $extra arguments added or replaced
	 * @return array<string, mixed> the plan of the mail card for alice
	 */
	protected function cardPlan(array $extra = []): array {
		return $this->tool->preview(self::arguments($extra), new ToolContext('alice'));
	}

	/**
	 * Plans, then confirms with the plan_state of that plan, as a client that showed the plan does.
	 *
	 * @param array<string, mixed> $extra arguments added or replaced
	 * @return array{0: array<string, mixed>, 1: bool} the receipt and whether the result is an error
	 */
	protected function planAndConfirm(array $extra = []): array {
		$state = $this->cardPlan($extra)[PlanState::ARGUMENT];
		$result = $this->tool->execute(self::arguments($extra) + ['confirm' => true, PlanState::ARGUMENT => $state], new ToolContext('alice'));
		return [json_decode($result['content'][0]['text'], true), ($result['isError'] ?? false) === true];
	}

	/**
	 * Asserts that nothing was written: no Files operation, no attachment content fetched and no card.
	 *
	 * @return void
	 */
	protected function assertNothingWritten(): void {
		self::assertSame([], $this->tree->ops);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
		self::assertSame([], $this->created);
	}
}
