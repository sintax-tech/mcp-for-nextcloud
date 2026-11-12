<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use InvalidArgumentException;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Deck\AbstractHandler;
use OCA\Mcp\Modules\Deck\Structure\CreateBoardHandler;
use OCA\Mcp\Modules\Deck\Creation\CreateCardHandler;
use OCA\Mcp\Modules\Deck\Structure\CreateStackHandler;
use OCA\Mcp\Modules\Deck\Structure\DeleteBoardHandler;
use OCA\Mcp\Modules\Deck\Deletion\DeleteCardHandler;
use OCA\Mcp\Modules\Deck\Structure\DeleteStackHandler;
use OCA\Mcp\Modules\Deck\Editing\EditCardHandler;
use OCA\Mcp\Modules\Deck\Editing\EditCardLabelsHandler;
use OCA\Mcp\Modules\Deck\Reading\FollowupCardsHandler;
use OCA\Mcp\Modules\Deck\Attachments\LinkCardFileHandler;
use OCA\Mcp\Modules\Deck\Reading\ListBoardsHandler;
use OCA\Mcp\Modules\Deck\Reading\ListCardsHandler;
use OCA\Mcp\Modules\Deck\Reading\ListLabelsHandler;
use OCA\Mcp\Modules\Deck\Reading\ListStacksHandler;
use OCA\Mcp\Modules\Deck\Moving\MoveCardHandler;
use OCA\Mcp\Modules\Deck\Reading\ReadCardDependenciesHandler;
use OCA\Mcp\Modules\Deck\Reading\ReadCardHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Composes the existing handlers per caller and preserves their admission and plan failures. */
final class DeckExecutionContext {
	private ?\OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface $gateway = null;
	private ?\OCA\Mcp\Modules\Deck\Attachments\CardFiles $cardFiles = null;

	/**
	 * @param ContainerInterface $container lazy services of this request
	 * @param IAppManager $appManager existing per-user app gate
	 * @param LoggerInterface $logger exception classes only
	 * @param IURLGenerator $urlGenerator absolute Deck links
	 * @param ITimeFactory $timeFactory payload clock
	 * @param IUserManager|null $userManager resolves authenticated users
	 * @param IConfig|null $config account timezones and plan secret
	 * @param \OCP\ISession|null $session native caller binding
	 * @param \OCA\Mcp\Modules\Deck\Integration\DeckCapabilities|null $capabilities unchanged dependency condition
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
		private ITimeFactory $timeFactory,
		private ?IUserManager $userManager = null,
		private ?IConfig $config = null,
		private ?\OCP\ISession $session = null,
		private ?\OCA\Mcp\Modules\Deck\Integration\DeckCapabilities $capabilities = null,
	) {
	}

	/**
	 * @param string $name explicit handler tool name
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged result
	 * @throws InvalidArgumentException when the app or tool is unavailable
	 */
	public function execute(string $name, array $arguments, string $userId): array {
		if (in_array($name, [ReadCardDependenciesHandler::TOOL, \OCA\Mcp\Modules\Deck\Dependencies\AddCardDependencyHandler::TOOL, \OCA\Mcp\Modules\Deck\Dependencies\RemoveCardDependencyHandler::TOOL], true)
			&& !$this->dependencyCapabilities()->dependencies()) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependenciesUnavailable());
		}
		$handler = $this->handlerFor($name, $userId);
		if ($handler === null) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorUnknownTool());
		}

		// The registry already filters by app; this is the safety net for any other caller.
		// isEnabledForUser() takes an IUser, never the UID string: passing the string breaks at runtime.
		$user = $this->userManager?->get($userId);
		if (($this->userManager !== null && $user === null) || !AppEnablement::forUser($this->appManager, 'deck', $user)) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorDeckAppUnavailable());
		}

		return $handler->handle($arguments, $userId);
	}

	/**
	 * @param string $name explicit preview tool name
	 * @param callable():array<string,mixed> $operation read-only plan body
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged plan carrying its caller timezone
	 * @throws \Throwable unchanged semantic or mapped preview failure
	 */
	public function preview(string $name, callable $operation, string $userId): array {
		if (in_array($name, [\OCA\Mcp\Modules\Deck\Dependencies\AddCardDependencyHandler::TOOL, \OCA\Mcp\Modules\Deck\Dependencies\RemoveCardDependencyHandler::TOOL], true)
			&& !$this->dependencyCapabilities()->dependencies()) {
			throw new InvalidArgumentException(\OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependenciesUnavailable());
		}
		try {
			$plan = $operation();
			$plan['timezone'] = $this->zones()?->forUser($userId)->getName();

			return $plan;
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (InvalidArgumentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// A preview that fails has to say the same thing the write would say, so the model is not
			// sent to ask the user about something that cannot happen.
			\OCA\Mcp\Modules\Deck\Integration\DeckFailureLog::plan($this->logger, $name, $e);

			throw new ToolFailure(\OCA\Mcp\Modules\Deck\Integration\DeckErrors::messageFor($e));
		}
	}

	/**
	 * @param string $name tool name
	 * @param string $userId caller UID
	 * @return AbstractHandler|null lazily composed handler
	 */
	private function handlerFor(string $name, string $userId): ?AbstractHandler {
		$formatter = $this->formatter($userId);

		return match ($name) {
			\OCA\Mcp\Modules\Deck\Editing\SetCardDoneHandler::TOOL => new \OCA\Mcp\Modules\Deck\Editing\SetCardDoneHandler($this->gateway(), $this->logger, fn (): \OCA\Mcp\Modules\Deck\Integration\CardStatusGatewayInterface => $this->statusGateway(), $formatter, fn (): PlanState => $this->planState()),
			\OCA\Mcp\Modules\Deck\Editing\SetCardArchivedHandler::TOOL => new \OCA\Mcp\Modules\Deck\Editing\SetCardArchivedHandler($this->gateway(), $this->logger, fn (): \OCA\Mcp\Modules\Deck\Integration\CardStatusGatewayInterface => $this->statusGateway(), $formatter, fn (): PlanState => $this->planState()),
			LinkCardFileHandler::TOOL => \OCA\Mcp\Modules\Deck\Attachments\CardLinkTool::handler($this->container, $this->gateway(), $this->logger, $this->session),
			\OCA\Mcp\Modules\Deck\Reading\ListCardFilesHandler::TOOL, \OCA\Mcp\Modules\Deck\Deletion\UnlinkCardFileHandler::TOOL => $this->fileHandler($name, $userId),
			\OCA\Mcp\Modules\Deck\Dependencies\AddCardDependencyHandler::TOOL, \OCA\Mcp\Modules\Deck\Dependencies\RemoveCardDependencyHandler::TOOL => $this->dependencyHandler($name, $userId),
			EditCardLabelsHandler::TOOL => $this->labelHandler($userId),
			\OCA\Mcp\Modules\Deck\Labels\CreateLabelHandler::TOOL => new \OCA\Mcp\Modules\Deck\Labels\CreateLabelHandler($this->gateway(), $this->logger),
			\OCA\Mcp\Modules\Deck\Labels\UpdateLabelHandler::TOOL => new \OCA\Mcp\Modules\Deck\Labels\UpdateLabelHandler($this->gateway(), $this->logger, fn (): PlanState => $this->planState()),
			\OCA\Mcp\Modules\Deck\Labels\DeleteLabelHandler::TOOL => new \OCA\Mcp\Modules\Deck\Labels\DeleteLabelHandler($this->gateway(), $this->logger, fn (): PlanState => $this->planState()),
			ListLabelsHandler::TOOL => new ListLabelsHandler($this->gateway(), $this->logger),
			ListBoardsHandler::TOOL => new ListBoardsHandler($this->gateway(), $this->logger, $formatter),
			ListStacksHandler::TOOL => new ListStacksHandler($this->gateway(), $this->logger, $formatter),
			ListCardsHandler::TOOL => new ListCardsHandler($this->gateway(), $this->logger, $formatter),
			FollowupCardsHandler::TOOL => new FollowupCardsHandler($this->gateway(), $this->logger, $formatter),
			ReadCardDependenciesHandler::TOOL => new ReadCardDependenciesHandler($this->gateway(), $this->logger, $formatter),
			ReadCardHandler::TOOL => new ReadCardHandler($this->gateway(), $this->logger, $formatter),
			CreateCardHandler::TOOL => new CreateCardHandler($this->gateway(), $this->logger, $formatter, fn (): bool => $this->labelsGranted($userId)),
			EditCardHandler::TOOL => new EditCardHandler($this->gateway(), $this->logger, $formatter),
			MoveCardHandler::TOOL => new MoveCardHandler($this->gateway(), $this->logger, $formatter),
			DeleteCardHandler::TOOL => new DeleteCardHandler($this->gateway(), $this->logger, $formatter),
			CreateBoardHandler::TOOL => new CreateBoardHandler($this->gateway(), $this->logger, $this->formatter($userId)->timezone()),
			CreateStackHandler::TOOL => new CreateStackHandler($this->gateway(), $this->logger),
			DeleteStackHandler::TOOL => new DeleteStackHandler($this->gateway(), $this->logger),
			DeleteBoardHandler::TOOL => new DeleteBoardHandler($this->gateway(), $this->logger),
			default => null,
		};
	}

	/**
	 * @param string $name file tool name
	 * @param string $userId caller UID
	 * @return AbstractHandler file handler with lazy access guards
	 */
	private function fileHandler(string $name, string $userId): AbstractHandler {
		$files = fn (): \OCA\Mcp\Modules\Deck\Attachments\CardFiles => $this->cardFiles ??= new \OCA\Mcp\Modules\Deck\Attachments\CardFiles($this->gateway(), $this->container->get(GrantPolicy::class),
			$this->container->get(FilesAccessGuard::class), $this->container->get(IRootFolder::class));
		$class = $name === \OCA\Mcp\Modules\Deck\Deletion\UnlinkCardFileHandler::TOOL ? \OCA\Mcp\Modules\Deck\Deletion\UnlinkCardFileHandler::class : \OCA\Mcp\Modules\Deck\Reading\ListCardFilesHandler::class;
		return new $class($this->gateway(), $this->logger, $files, $this->formatter($userId),
			fn (): PlanState => new PlanState($this->config ?? $this->container->get(IConfig::class)));
	}

	/**
	 * @param string $name relation tool name
	 * @param string $userId caller UID
	 * @return AbstractHandler one direction with lazy fingerprint
	 */
	private function dependencyHandler(string $name, string $userId): AbstractHandler {
		$class = $name === \OCA\Mcp\Modules\Deck\Dependencies\AddCardDependencyHandler::TOOL ? \OCA\Mcp\Modules\Deck\Dependencies\AddCardDependencyHandler::class : \OCA\Mcp\Modules\Deck\Dependencies\RemoveCardDependencyHandler::class;
		return new $class($this->gateway(), $this->logger, $this->formatter($userId),
			fn (): PlanState => new PlanState($this->config ?? $this->container->get(IConfig::class)));
	}

	/**
	 * @param string $userId caller UID
	 * @return EditCardLabelsHandler label effect with lazy fingerprint
	 */
	private function labelHandler(string $userId): EditCardLabelsHandler {
		return new EditCardLabelsHandler($this->gateway(), $this->logger, $this->formatter($userId),
			fn (): PlanState => new PlanState($this->config ?? $this->container->get(IConfig::class)));
	}

	/**
	 * @param string $userId caller UID
	 * @return \OCA\Mcp\Modules\Deck\Integration\CardFormatter formatter newly bound to this caller
	 */
	public function formatter(string $userId): \OCA\Mcp\Modules\Deck\Integration\CardFormatter {
		return new \OCA\Mcp\Modules\Deck\Integration\CardFormatter($this->urlGenerator, $this->timeFactory, $this->userManager, $this->zones()?->forUser($userId));
	}

	/**
	 * @return \OCA\Mcp\Modules\Deck\Integration\DeckCapabilities unchanged native-shape condition
	 */
	private function dependencyCapabilities(): \OCA\Mcp\Modules\Deck\Integration\DeckCapabilities {
		return $this->capabilities ??= new \OCA\Mcp\Modules\Deck\Integration\DeckCapabilities($this->appManager);
	}

	/**
	 * @return \OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface the single registered gateway
	 */
	public function gateway(): \OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface {
		return $this->gateway ??= $this->container->get(\OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface::class);
	}

	/**
	 * @return UserTimezone|null existing account timezone resolver
	 */
	public function zones(): ?UserTimezone {
		return $this->config === null ? null : new UserTimezone($this->config);
	}

	/**
	 * Where a card lands or comes from: the list, the board behind it and who owns that board.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId Deck list the plan is about.
	 * @return array{stackId: int, list: string|null, board: string, owner: string, ownerDisplayName: string, shared: bool}
	 */
	public function place(string $userId, int $stackId): array {
		$ownership = $this->gateway()->stackOwnership($userId, $stackId);

		return [
			'stackId' => $stackId,
			'list' => (new \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection($this->gateway()))->listTitle($userId, $stackId),
			'board' => $ownership['name'],
			'owner' => $ownership['owner'],
			'ownerDisplayName' => $ownership['ownerDisplayName'],
			'shared' => $ownership['owner'] !== $userId,
		];
	}

	/**
	 * @param array{owner: string, ownerDisplayName: string, name: string} $ownership
	 * @param string $userId UID of the authenticated caller.
	 * @return array{board: string, owner: string, ownerDisplayName: string, shared: bool}
	 */
	public function board(array $ownership, string $userId): array {
		return [
			'board' => $ownership['name'],
			'owner' => $ownership['owner'],
			'ownerDisplayName' => $ownership['ownerDisplayName'],
			'shared' => $ownership['owner'] !== $userId,
		];
	}

	/** @param string $userId account UID @return string current display name or the UID */
	public function displayName(string $userId): string {
		return $this->userManager?->get($userId)?->getDisplayName() ?: $userId;
	}

	/** @return \OCA\Mcp\Modules\Deck\Attachments\CardFiles lazy unchanged Files boundary */
	public function files(): \OCA\Mcp\Modules\Deck\Attachments\CardFiles {
		return $this->cardFiles ??= new \OCA\Mcp\Modules\Deck\Attachments\CardFiles($this->gateway(), $this->container->get(GrantPolicy::class), $this->container->get(FilesAccessGuard::class), $this->container->get(IRootFolder::class));
	}
	/**
	 * deck_create_card creates a missing label only for a caller who could create it with deck_create_label.
	 *
	 * @param string $userId caller UID
	 * @return bool whether the caller holds the deck.edit grant
	 */
	public function labelsGranted(string $userId): bool {
		return $this->container->get(GrantPolicy::class)->granted($userId, 'deck', 'edit');
	}
	/** @return PlanState unchanged lazy instance-secret service */
	public function planState(): PlanState {
		return new PlanState($this->config ?? $this->container->get(IConfig::class));
	}
	/** @return \OCA\Mcp\Modules\Deck\Integration\CardStatusGatewayInterface lazy focused native status port */
	public function statusGateway(): \OCA\Mcp\Modules\Deck\Integration\CardStatusGatewayInterface {
		return $this->container->get(\OCA\Mcp\Modules\Deck\Integration\CardStatusGatewayInterface::class);
	}
	/** @return array<string,mixed> original per-operation file link collaborators */
	public function linkServices(): array {
		return (\OCA\Mcp\Modules\Deck\Attachments\CardLinkTool::factory($this->container, $this->gateway(), $this->logger, $this->session))();
	}
}
