<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use InvalidArgumentException;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tools\Deck\Handler\AbstractHandler;
use OCA\Mcp\Tools\Deck\Handler\CardFileHandler;
use OCA\Mcp\Tools\Deck\Handler\ChangeCardDependencyHandler;
use OCA\Mcp\Tools\Deck\Handler\CreateBoardHandler;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tools\Deck\Handler\CreateStackHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteBoardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteCardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteStackHandler;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tools\Deck\Handler\EditCardLabelsHandler;
use OCA\Mcp\Tools\Deck\Handler\FollowupCardsHandler;
use OCA\Mcp\Tools\Deck\Handler\LinkCardFileHandler;
use OCA\Mcp\Tools\Deck\Handler\ListBoardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListCardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListLabelsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListStacksHandler;
use OCA\Mcp\Tools\Deck\Handler\MoveCardHandler;
use OCA\Mcp\Tools\Deck\Handler\ReadCardDependenciesHandler;
use OCA\Mcp\Tools\Deck\Handler\ReadCardHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Deck card and board tools, with native dependency reads only where the compatible API exists.
 *
 * This class only declares the tools and routes a call to a handler. It holds no Deck logic and
 * resolves no Deck class at construction time, because the Deck app may not be installed: the
 * tools declare `app: 'deck'` so the registry hides them, and the handler is built per call.
 *
 * The writing tools are also described by {@see self::preview()}, which the registry asks for
 * whenever a write arrives without `confirm: true`; the plan it returns is what the agent shows the
 * user, and `confirm_shared` still guards a board of somebody else at the moment of the write.
 */
final class DeckToolModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
	/** Nextcloud app id whose presence the whole module depends on. */
	public const DECK_APP = 'deck';

	/** Grant module every Deck tool belongs to. */
	private const MODULE = 'deck';

	/** Maximum position accepted by `deck_move_card`, matching the Deck `order` column. */
	private const MAX_ORDER = 99999;

	/** Default page size of `deck_list_cards`. */
	private const DEFAULT_LIMIT = 50;

	private ?DeckGatewayInterface $gateway = null;
	private ?CardFiles $cardFiles = null;

	/**
	 * @param ContainerInterface $container Nextcloud server container, resolves the Deck services lazily.
	 * @param IAppManager $appManager Tells whether the Deck app is enabled for the caller.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param IURLGenerator $urlGenerator Builds the absolute link to a card, which every card payload carries.
	 * @param ITimeFactory $timeFactory Clock the `overdue` flag of a card is read from.
	 * @param IUserManager|null $userManager Resolves the UID to the IUser that IAppManager::isEnabledForUser() expects.
	 * @param IConfig|null $config Reads the caller's timezone, so due dates speak the day they see.
	 * @param \OCP\ISession|null $session Caller binding used by the native Deck services.
	 * @param DeckCapabilities|null $capabilities Optional native capability probe; default reads current installation.
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
		private ?DeckCapabilities $capabilities = null,
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * How a card is found and where the writes stop: the three ids, the shared board and the field limits.
	 *
	 * @return list<string>
	 */
	public function guideNotes(): array {
		$notes = [
			'deck_edit_card_labels changes only add/remove label IDs on a card, preserving all other labels. '
				. 'Use deck_list_labels on its board first. Preview before confirm and repeat plan_state; a changed state needs a new approval.',
			'A card is addressed by three ids: deck_list_boards gives the board, deck_list_stacks the lists of a '
				. 'board, and deck_list_cards the cards of a list. Read them in that order before writing anything.',
			'deck_read_card returns the card with its comments; deck_followup_cards lists the cards that are due or '
				. 'overdue, per board.',
			'A board owned by somebody else is refused on a write until the user has been asked and the call repeats '
				. 'with confirm_shared: true. The refusal is a normal result that says what is missing, not an error.',
			'The caller owns a created card. Explicit assignments are checked before creation and shown in the plan. '
				. 'Assignment changes during creation can produce a created card with warnings; read it before retrying in Deck. '
				. 'deck_edit_card changes assignees with assign and unassign (lists of account IDs, at most 100 each); '
				. 'an account cannot be in both, accounts are checked before anything is written, and a failure midway '
				. 'leaves the result listing assigned, unassigned and failed.',
			'To set up a project from scratch use one deck_create_board call: it creates the board, its lists and their first '
				. 'cards after a single confirmation (at most ' . BoardBlueprint::MAX_STACKS . ' lists and ' . BoardBlueprint::MAX_CARDS . ' cards). '
				. 'The board is open only to its owner, so share it in Deck before assigning anybody else. deck_create_stack adds a '
				. 'list to an existing board. deck_delete_stack and deck_delete_board work only on something empty: they refuse, '
				. 'saying how many cards are left, while any card (active or archived) remains, and what they delete goes to the Deck trash. A card created by somebody else right after the second count '
				. 'they take before finishing may go to the trash with the list or board; it is recoverable there.',
			CardDueDateMessages::GUIDE_NOTE . ' A card description is capped '
				. 'at ' . CardInput::MAX_DESCRIPTION_LENGTH . ' characters and a title at 255.',
			'deck_move_card moves a card to another list, including one on another board. Existing permissions apply to '
				. 'both ends; a shared origin or destination requires confirm_shared: true after asking the user.',
		];
		foreach (DeckGuideNotes::lines() as $note) {
			$notes[] = $note;
		}
		if ($this->dependencyCapabilities()->dependencies()) {
			$notes[] = DeckMessages::TOOL_READ_DEPENDENCIES_DESCRIPTION;
		}
		return $notes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every tool declares `module: 'deck'`, its operation and `app: 'deck'`, so the registry hides
	 * them from users without the Deck app and re-checks the grant on every call.
	 *
	 * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, module: string, operation: string, app: string}>
	 */
	public function definitions(): array {
		$definitions = [
			[
				'name' => ListBoardsHandler::TOOL,
				'description' => DeckMessages::TOOL_LIST_BOARDS_DESCRIPTION,
				'inputSchema' => $this->emptySchema(),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
			],
			[
				'name' => ListLabelsHandler::TOOL,
				'description' => DeckMessages::TOOL_LIST_LABELS_DESCRIPTION,
				'inputSchema' => $this->schema([
					'boardId' => $this->integer(DeckMessages::PARAM_BOARD_ID),
					'limit' => $this->integer(DeckMessages::PARAM_LABEL_LIMIT, 1, 200, ListLabelsHandler::DEFAULT_LIMIT),
					'offset' => $this->integer(DeckMessages::PARAM_LABEL_OFFSET, 0),
				], ['boardId']),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
			],
			[
				'name' => CardFileHandler::READ, 'description' => DeckMessages::TOOL_CARD_FILES_DESCRIPTION,
				'inputSchema' => $this->schema(['cardId' => $this->integer(DeckMessages::PARAM_CARD_ID)], ['cardId']),
				'module' => self::MODULE, 'operation' => 'read', 'app' => self::DECK_APP,
			],
			[
				'name' => CardFileHandler::UNLINK, 'description' => DeckMessages::TOOL_UNLINK_FILE_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => $this->schema([
					'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
					'attachmentId' => $this->integer(DeckMessages::PARAM_ATTACHMENT_ID),
					'file_state' => $this->schema([
						'fileId' => $this->integer('Original Files node ID from the plan receipt.'),
						'createdBy' => $this->string('Native share initiator from the plan receipt.', 1, 255),
						'createdAt' => $this->integer('Native share creation timestamp from the plan receipt.', 0),
					], ['fileId', 'createdBy', 'createdAt']),
					'confirm_shared' => $this->confirmShared(), PlanState::ARGUMENT => PlanState::property(),
				], ['cardId', 'attachmentId']),
				'module' => self::MODULE, 'operation' => 'edit', 'app' => self::DECK_APP,
			],
			CardLinkTool::definition(),
			[
				'name' => EditCardLabelsHandler::TOOL,
				'description' => DeckMessages::TOOL_EDIT_LABELS_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => $this->schema([
					'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
					'add' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
						'maxItems' => CardLabelChanges::MAX_LABELS, 'description' => DeckMessages::PARAM_LABEL_ADD],
					'remove' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
						'maxItems' => CardLabelChanges::MAX_LABELS, 'description' => DeckMessages::PARAM_LABEL_REMOVE],
					'confirm_shared' => $this->confirmShared(),
					PlanState::ARGUMENT => PlanState::property(),
				], ['cardId']),
				'module' => self::MODULE, 'operation' => 'edit', 'app' => self::DECK_APP,
			],
			[
				'name' => ListStacksHandler::TOOL,
				'description' => DeckMessages::TOOL_LIST_STACKS_DESCRIPTION,
				'inputSchema' => $this->schema(
					['boardId' => $this->integer(DeckMessages::PARAM_BOARD_ID)],
					['boardId'],
				),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
			],
			[
				'name' => ListCardsHandler::TOOL,
				'description' => DeckMessages::TOOL_LIST_CARDS_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'stackId' => $this->integer(DeckMessages::PARAM_STACK_ID),
						'limit' => $this->integer(DeckMessages::PARAM_LIMIT, 1, 200, self::DEFAULT_LIMIT),
						'offset' => $this->integer(DeckMessages::PARAM_OFFSET, 0),
					],
					['stackId'],
				),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
			],
			[
				'name' => ReadCardHandler::TOOL,
				'description' => DeckMessages::TOOL_READ_CARD_DESCRIPTION,
				'inputSchema' => $this->schema(
					['cardId' => $this->integer(DeckMessages::PARAM_CARD_ID)],
					['cardId'],
				),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
			],
			[
				'name' => CreateCardHandler::TOOL,
				'description' => DeckMessages::TOOL_CREATE_CARD_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'stackId' => $this->integer(DeckMessages::PARAM_STACK_ID),
						'title' => $this->string(DeckMessages::PARAM_TITLE, 1, 255),
						'description' => $this->string(DeckMessages::PARAM_DESCRIPTION, 0, null, ''),
						'duedate' => $this->nullableString(DeckMessages::PARAM_DUEDATE, null),
						'assignees' => $this->accountList(DeckMessages::PARAM_ASSIGNEES),
						'confirm_shared' => $this->confirmShared(),
					],
					['stackId', 'title'],
				),
				'module' => self::MODULE,
				'operation' => 'create',
				'app' => self::DECK_APP,
			],
			[
				'name' => EditCardHandler::TOOL,
				'description' => DeckMessages::TOOL_EDIT_CARD_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				// `duedate` has no default here on purpose: absent means "keep the current date",
				// while an explicit null clears it.
				'inputSchema' => $this->schema(
					[
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'title' => $this->string(DeckMessages::PARAM_TITLE, 1, 255),
						'description' => $this->string(DeckMessages::PARAM_DESCRIPTION, 0),
						'duedate' => $this->nullableString(DeckMessages::PARAM_DUEDATE),
						'assign' => $this->accountList(DeckMessages::PARAM_ASSIGN),
						'unassign' => $this->accountList(DeckMessages::PARAM_UNASSIGN),
						'lastModified' => $this->integer(DeckMessages::PARAM_LAST_MODIFIED, 0),
						'confirm_shared' => $this->confirmShared(),
					],
					['cardId'],
				),
				'module' => self::MODULE,
				'operation' => 'edit',
				'app' => self::DECK_APP,
			],
			[
				'name' => MoveCardHandler::TOOL,
				'description' => DeckMessages::TOOL_MOVE_CARD_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'stackId' => $this->integer(DeckMessages::PARAM_STACK_ID),
						'order' => $this->integer(DeckMessages::PARAM_ORDER, 0, self::MAX_ORDER),
						'confirm_shared' => $this->confirmShared(),
					],
					['cardId', 'stackId'],
				),
				'module' => self::MODULE,
				'operation' => 'move',
				'app' => self::DECK_APP,
			],
			[
				'name' => DeleteCardHandler::TOOL,
				'description' => DeckMessages::TOOL_DELETE_CARD_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				// `confirm` is not declared here: the registry publishes it on every write tool, so the
				// same wording and the same rule apply to all of them.
				'inputSchema' => $this->schema(
					[
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'confirm_shared' => $this->confirmShared(),
					],
					['cardId'],
				),
				'module' => self::MODULE,
				'operation' => 'delete',
				'app' => self::DECK_APP,
			],
			[
				'name' => FollowupCardsHandler::TOOL,
				'description' => DeckMessages::TOOL_FOLLOWUP_CARDS_DESCRIPTION,
				// Every argument is optional: the defaults answer the question the tool exists for.
				'inputSchema' => $this->schema(
					[
						'status' => [
							'type' => 'string',
							'enum' => CardCriteria::STATUSES,
							'default' => CardCriteria::STATUS_OVERDUE,
							'description' => DeckMessages::PARAM_STATUS,
						],
						'assignee' => $this->string(DeckMessages::PARAM_ASSIGNEE, 1, 64),
						'boardId' => $this->integer(DeckMessages::PARAM_BOARD_ID),
						'dueBefore' => ['type' => 'string', 'description' => DeckMessages::PARAM_DUE_BEFORE],
						'limit' => $this->integer(
							DeckMessages::PARAM_LIMIT,
							1,
							FollowupCardsHandler::MAX_LIMIT,
							FollowupCardsHandler::DEFAULT_LIMIT,
						),
					],
					[],
				),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
			],
			[
				'name' => CreateBoardHandler::TOOL,
				'description' => DeckMessages::TOOL_CREATE_BOARD_DESCRIPTION,
				// A board that does not exist yet cannot be somebody else's, so there is no `confirm_shared`.
				'inputSchema' => $this->schema(
					[
						'title' => $this->string(DeckMessages::PARAM_BOARD_TITLE, 1, BoardBlueprint::MAX_TITLE_LENGTH),
						'color' => $this->string(DeckMessages::PARAM_BOARD_COLOR, 6, 7),
						'stacks' => [
							'type' => 'array',
							'maxItems' => BoardBlueprint::MAX_STACKS,
							'description' => DeckMessages::PARAM_BOARD_STACKS,
							'items' => $this->schema(
								[
									'title' => $this->string(DeckMessages::PARAM_STACK_TITLE, 1, BoardBlueprint::MAX_TITLE_LENGTH),
									'cards' => [
										'type' => 'array',
										'maxItems' => BoardBlueprint::MAX_CARDS,
										'description' => DeckMessages::PARAM_STACK_CARDS,
										'items' => $this->schema(
											[
												'title' => $this->string(DeckMessages::PARAM_TITLE, 1, 255),
												'description' => $this->string(DeckMessages::PARAM_DESCRIPTION, 0),
												'duedate' => $this->nullableString(DeckMessages::PARAM_DUEDATE),
												'assignees' => $this->accountList(DeckMessages::PARAM_ASSIGNEES),
											],
											['title'],
										),
									],
								],
								['title'],
							),
						],
					],
					['title'],
				),
				'module' => self::MODULE,
				'operation' => 'create',
				'app' => self::DECK_APP,
			],
			[
				'name' => CreateStackHandler::TOOL,
				'description' => DeckMessages::TOOL_CREATE_STACK_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'boardId' => $this->integer(DeckMessages::PARAM_BOARD_ID),
						'title' => $this->string(DeckMessages::PARAM_STACK_TITLE, 1, BoardBlueprint::MAX_TITLE_LENGTH),
						'order' => $this->integer(DeckMessages::PARAM_STACK_ORDER, 0, self::MAX_ORDER),
						'confirm_shared' => $this->confirmShared(),
					],
					['boardId', 'title'],
				),
				'module' => self::MODULE,
				'operation' => 'create',
				'app' => self::DECK_APP,
			],
			[
				'name' => DeleteStackHandler::TOOL,
				'description' => DeckMessages::TOOL_DELETE_STACK_DESCRIPTION . DeckMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'stackId' => $this->integer(DeckMessages::PARAM_STACK_ID),
						'confirm_shared' => $this->confirmShared(),
					],
					['stackId'],
				),
				'module' => self::MODULE,
				'operation' => 'delete',
				'app' => self::DECK_APP,
			],
			[
				'name' => DeleteBoardHandler::TOOL,
				// Only the owner deletes a board, so `confirm_shared` does not exist here: a board of somebody else is refused.
				'description' => DeckMessages::TOOL_DELETE_BOARD_DESCRIPTION,
				'inputSchema' => $this->schema(
					['boardId' => $this->integer(DeckMessages::PARAM_BOARD_ID)],
					['boardId'],
				),
				'module' => self::MODULE,
				'operation' => 'delete',
				'app' => self::DECK_APP,
			],
		];
		if ($this->dependencyCapabilities()->dependencies()) {
			$definitions[] = [
				'name' => ReadCardDependenciesHandler::TOOL,
				'description' => DeckMessages::TOOL_READ_DEPENDENCIES_DESCRIPTION,
				'inputSchema' => $this->schema(['cardId' => $this->integer(DeckMessages::PARAM_CARD_ID)], ['cardId']),
				'module' => self::MODULE, 'operation' => 'read', 'app' => self::DECK_APP,
			];
			foreach ([ChangeCardDependencyHandler::ADD, ChangeCardDependencyHandler::REMOVE] as $tool) {
				$definitions[] = ['name' => $tool,
					'description' => ($tool === ChangeCardDependencyHandler::ADD
						? DeckMessages::TOOL_ADD_DEPENDENCY_DESCRIPTION : DeckMessages::TOOL_REMOVE_DEPENDENCY_DESCRIPTION)
						. DeckMessages::CONFIRM_SHARED_DESCRIPTION,
					'inputSchema' => $this->schema([
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'targetId' => $this->integer(DeckMessages::PARAM_DEPENDENCY_TARGET),
						'confirm_shared' => $this->confirmShared(), PlanState::ARGUMENT => PlanState::property(),
					], ['cardId', 'targetId']),
					'module' => self::MODULE, 'operation' => 'edit', 'app' => self::DECK_APP];
			}
		}
		return $definitions;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name One of the names returned by {@see self::definitions()}.
	 * @param array<string, mixed> $arguments Arguments already validated by the registry.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws InvalidArgumentException For an unknown tool or when the Deck app is unavailable, mapped to -32602.
	 */
	public function call(string $name, array $arguments, string $userId): array {
		if (in_array($name, [ReadCardDependenciesHandler::TOOL, ChangeCardDependencyHandler::ADD, ChangeCardDependencyHandler::REMOVE], true)
			&& !$this->dependencyCapabilities()->dependencies()) {
			throw new InvalidArgumentException(DeckMessages::dependenciesUnavailable());
		}
		$handler = $this->handlerFor($name, $userId);
		if ($handler === null) {
			throw new InvalidArgumentException(DeckMessages::errorUnknownTool());
		}

		// The registry already filters by app; this is the safety net for any other caller.
		// isEnabledForUser() takes an IUser, never the UID string: passing the string breaks at runtime.
		$user = $this->userManager?->get($userId);
		if (($this->userManager !== null && $user === null) || !AppEnablement::forUser($this->appManager, self::DECK_APP, $user)) {
			throw new InvalidArgumentException(DeckMessages::errorDeckAppUnavailable());
		}

		return $handler->handle($arguments, $userId);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The plan of a Deck write: the card as it is now and as the call would leave it, the lists and
	 * boards on both ends of a move, and who owns them. Everything here is read through the same
	 * gateway the handler uses, so the preview applies the Deck ACL and says "not found" where the
	 * write would; nothing is written and nothing is stored.
	 *
	 * @param string $name One of the writing tool names of {@see self::definitions()}.
	 * @param array<string, mixed> $arguments Arguments already validated by the registry.
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed> The plan, as the structured content of a non-error result.
	 * @throws InvalidArgumentException For an unknown tool or an argument the write would refuse (-32602).
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure When the Deck refuses the same read the write would do.
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		if (in_array($name, [ChangeCardDependencyHandler::ADD, ChangeCardDependencyHandler::REMOVE], true)
			&& !$this->dependencyCapabilities()->dependencies()) {
			throw new InvalidArgumentException(DeckMessages::dependenciesUnavailable());
		}
		try {
			$plan = match ($name) {
				LinkCardFileHandler::TOOL => CardLinkTool::handler($this->container, $this->gateway(), $this->logger, $this->session)->preview($arguments, $userId),
				CardFileHandler::UNLINK => $this->fileHandler($name, $userId)->preview($arguments, $userId),
				ChangeCardDependencyHandler::ADD, ChangeCardDependencyHandler::REMOVE => $this->dependencyHandler($name, $userId)->preview($arguments, $userId),
				EditCardLabelsHandler::TOOL => $this->labelHandler($userId)->preview($arguments, $userId),
				CreateCardHandler::TOOL => $this->planCreate($arguments, $userId),
				EditCardHandler::TOOL => $this->planEdit($arguments, $userId),
				MoveCardHandler::TOOL => $this->planMove($arguments, $userId),
				DeleteCardHandler::TOOL => $this->planDelete($arguments, $userId),
				CreateBoardHandler::TOOL => $this->planCreateBoard($arguments, $userId),
				CreateStackHandler::TOOL => $this->planCreateStack($arguments, $userId),
				DeleteStackHandler::TOOL => $this->planDeleteStack($arguments, $userId),
				DeleteBoardHandler::TOOL => $this->planDeleteBoard($arguments, $userId),
				default => throw new InvalidArgumentException(DeckMessages::errorUnknownTool()),
			};
			$plan['timezone'] = $this->zones()?->forUser($userId)->getName();

			return $plan;
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (InvalidArgumentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// A preview that fails has to say the same thing the write would say, so the model is not
			// sent to ask the user about something that cannot happen.
			DeckFailureLog::plan($this->logger, $name, $e);

			throw new ToolFailure(DeckErrors::messageFor($e));
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new DeckPlanRenderer())->render($tool, $plan);
	}

	/**
	 * @param array{stackId: int, title: string, description?: string, duedate?: string|null, assignees?: list<string>} $arguments
	 * @param string $userId UID of the authenticated caller, also the owner of the card to be created.
	 * @return array<string, mixed>
	 */
	private function planCreate(array $arguments, string $userId): array {
		$place = $this->place($userId, (int)$arguments['stackId']);
		$assignees = array_key_exists('assignees', $arguments)
			? $this->gateway()->validateAssignees($userId, (int)$arguments['stackId'], CardInput::assignees($arguments['assignees']))
			: null;

		return [
			'action' => CreateCardHandler::TOOL,
			'card' => [
				'title' => CardInput::requireTitle((string)$arguments['title']),
				'description' => CardInput::requireDescription((string)($arguments['description'] ?? '')),
				'dueDate' => CardInput::duedate($arguments['duedate'] ?? null, $this->formatter($userId)->timezone()),
				'owner' => $userId,
			] + ($assignees === null ? [] : ['assignees' => $assignees]),
			'destination' => $place,
			'shared' => $place['shared'] ? [$place] : [],
			'recoverable' => true,
			'message' => DeckMessages::planCreate(),
		];
	}

	/**
	 * @param array{cardId: int, title?: string, description?: string, duedate?: string|null, assign?: list<string>, unassign?: list<string>, lastModified?: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function planEdit(array $arguments, string $userId): array {
		$given = array_intersect_key($arguments, array_flip(['title', 'description', 'duedate']));
		$changes = AssigneeChanges::fromArguments($arguments);
		$editsAssignees = $changes['assign'] !== [] || $changes['unassign'] !== [];
		if ($given === [] && !$editsAssignees) {
			throw new InvalidArgumentException(DeckMessages::errorNoFieldToEdit());
		}
		$card = $this->gateway()->findCard($userId, (int)$arguments['cardId']);
		// Both dates are instants in the caller's zone, the day the write turns into local midnight
		// included, so the same date is never reported as a change only because it is spelled otherwise.
		$zone = $this->zones()?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
		$before = $this->fields($card, $zone);
		$after = [
			'title' => array_key_exists('title', $given) ? CardInput::requireTitle((string)$given['title']) : $before['title'],
			'description' => array_key_exists('description', $given)
				? CardInput::requireDescription((string)$given['description'])
				: $before['description'],
			// Absent keeps the date as it is; an explicit null clears it, exactly as the write reads it.
			'duedate' => array_key_exists('duedate', $given)
				? CardDueDateView::planValue($given['duedate'], $zone)
				: $before['duedate'],
		];
		$assignees = $editsAssignees ? $this->planAssignees($userId, $card, $changes) : null;
		$board = $this->gateway()->cardOwnership($userId, (int)$arguments['cardId']);

		$changed = array_values(array_filter(
			['title', 'description', 'duedate'],
			static fn (string $field): bool => $before[$field] !== $after[$field],
		));
		if ($assignees !== null && ($assignees['added'] !== [] || $assignees['removed'] !== [])) {
			$changed[] = 'assignees';
		}

		return [
			'action' => EditCardHandler::TOOL,
			'card' => ['id' => (int)$card->getId(), 'before' => $before, 'after' => $after],
			'board' => $this->board($board, $userId),
			'changed' => $changed,
			'lastModified' => ['current' => (int)$card->getLastModified(), 'sent' => $arguments['lastModified'] ?? null],
			'shared' => $board['owner'] === $userId ? [] : [$this->board($board, $userId)],
			'recoverable' => true,
			'message' => DeckMessages::planEdit(),
		] + ($assignees === null ? [] : ['assignees' => $assignees]);
	}

	/**
	 * Who is responsible for the card now, who joins, who leaves and what is ignored.
	 *
	 * Accounts to add go through the same board check the write runs, so a refusal comes with the plan
	 * and nothing is written to find it out.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param \OCA\Deck\Db\Card $card Card as read, with its assignments.
	 * @param array{assign: list<string>, unassign: list<string>} $changes Validated lists of the call.
	 * @return array{before: list<array{uid: string, displayName: string}>, after: list<array{uid: string, displayName: string}>, added: list<array{uid: string, displayName: string}>, removed: list<array{uid: string, displayName: string}>, notAssigned: list<string>, alreadyAssigned: list<string>}
	 */
	private function planAssignees(string $userId, \OCA\Deck\Db\Card $card, array $changes): array {
		$before = $this->formatter($userId)->card($card)['assignedUsers'];
		$resolved = AssigneeChanges::resolve($changes['assign'], $changes['unassign'], array_column($before, 'uid'));
		$added = $resolved['add'] === [] ? [] : $this->gateway()->validateAssignees($userId, (int)$card->getStackId(), $resolved['add']);
		$removed = array_values(array_filter($before, static fn (array $user): bool => in_array($user['uid'], $resolved['remove'], true)));
		$kept = array_values(array_filter($before, static fn (array $user): bool => !in_array($user['uid'], $resolved['remove'], true)));

		return [
			'before' => $before,
			'after' => [...$kept, ...$added],
			'added' => $added,
			'removed' => $removed,
			'notAssigned' => $resolved['notAssigned'],
			'alreadyAssigned' => $resolved['alreadyAssigned'],
		];
	}

	/**
	 * @param array{cardId: int, stackId: int, order?: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function planMove(array $arguments, string $userId): array {
		$cardId = (int)$arguments['cardId'];
		$card = $this->gateway()->findCard($userId, $cardId);
		$origin = $this->place($userId, (int)$card->getStackId());
		$destination = $this->place($userId, (int)$arguments['stackId']);

		return [
			'action' => MoveCardHandler::TOOL,
			'card' => ['id' => $cardId] + $this->fields($card),
			'origin' => $origin,
			'destination' => $destination,
			'order' => array_key_exists('order', $arguments) ? (int)$arguments['order'] : null,
			'sameList' => $origin['stackId'] === $destination['stackId'],
			'shared' => array_values(array_filter([$origin, $destination], static fn (array $place): bool => $place['shared'])),
			'recoverable' => true,
			'message' => DeckMessages::planMove(),
		];
	}

	/**
	 * @param array{cardId: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function planDelete(array $arguments, string $userId): array {
		$cardId = (int)$arguments['cardId'];
		$card = $this->gateway()->findCard($userId, $cardId);
		$board = $this->gateway()->cardOwnership($userId, $cardId);

		return [
			'action' => DeleteCardHandler::TOOL,
			'card' => ['id' => $cardId] + $this->fields($card),
			'board' => $this->board($board, $userId),
			'shared' => $board['owner'] === $userId ? [] : [$this->board($board, $userId)],
			// Deck soft-deletes, but no tool of this app brings a card back, so the plan says so instead of
			// leaving the user to discover it.
			'recoverable' => false,
			'consequence' => DeckMessages::planDeleteConsequence(),
			'message' => DeckMessages::planDelete(),
		];
	}

	/**
	 * The tree of a board as it will be built: the board, its lists and their cards, validated by the same
	 * {@see BoardBlueprint} the execution reads, so anything the write would refuse is refused here.
	 *
	 * @param array<string, mixed> $arguments Arguments of `deck_create_board`.
	 * @param string $userId UID of the authenticated caller, the owner of the board.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException Naming the field when the structure is refused.
	 */
	private function planCreateBoard(array $arguments, string $userId): array {
		$blueprint = BoardBlueprint::parse($arguments, $userId, $this->formatter($userId)->timezone());
		// Only the owner can be assigned on a new board, and BoardBlueprint has already refused anybody else.
		$self = ['uid' => $userId, 'displayName' => $this->userManager?->get($userId)?->getDisplayName() ?: $userId];

		return [
			'action' => CreateBoardHandler::TOOL,
			'board' => ['title' => $blueprint['title'], 'color' => $blueprint['color'], 'colorGiven' => $blueprint['colorGiven']],
			'owner' => $userId,
			'stacks' => array_map(static fn (array $stack): array => [
				'title' => $stack['title'],
				'cards' => array_map(static fn (array $card): array => [
					'title' => $card['title'],
					'description' => $card['description'],
					'duedate' => $card['duedate'],
					'assignees' => array_map(static fn (string $uid): array => $self, $card['assignees']),
				], $stack['cards']),
			], $blueprint['stacks']),
			'totals' => ['stacks' => count($blueprint['stacks']), 'cards' => $blueprint['cardCount']],
			'limits' => ['stacks' => BoardBlueprint::MAX_STACKS, 'cards' => BoardBlueprint::MAX_CARDS],
			'shared' => [],
			'recoverable' => true,
			'message' => DeckMessages::planCreateBoard(),
		];
	}

	/**
	 * @param array{boardId: int, title: string, order?: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function planCreateStack(array $arguments, string $userId): array {
		$title = BoardBlueprint::title((string)$arguments['title'], 'title');
		$board = $this->board($this->gateway()->boardOwnership($userId, (int)$arguments['boardId']), $userId);

		return [
			'action' => CreateStackHandler::TOOL,
			'stack' => ['title' => $title, 'order' => array_key_exists('order', $arguments) ? (int)$arguments['order'] : null],
			'board' => $board,
			'shared' => $board['shared'] ? [$board] : [],
			'recoverable' => true,
			'message' => DeckMessages::planCreateStack(),
		];
	}

	/**
	 * A list can only be deleted empty, so the cards are counted for the plan and a list that holds any has no
	 * plan at all: the refusal is the answer, as the write would give it.
	 *
	 * @param array{stackId: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 * @throws DeckRefusalException When the list holds any card.
	 */
	private function planDeleteStack(array $arguments, string $userId): array {
		$stackId = (int)$arguments['stackId'];
		$place = $this->place($userId, $stackId);
		$cards = $this->gateway()->stackCardCount($userId, $stackId);
		if ($cards > 0) {
			throw DeckRefusalException::stackNotEmpty($cards);
		}
		$board = ['board' => $place['board'], 'owner' => $place['owner'], 'ownerDisplayName' => $place['ownerDisplayName'], 'shared' => $place['shared']];

		return [
			'action' => DeleteStackHandler::TOOL,
			'stack' => ['id' => $stackId, 'title' => $place['list'], 'cards' => $cards],
			'board' => $board,
			'shared' => $place['shared'] ? [$board] : [],
			'recoverable' => true,
			'consequence' => DeckMessages::planDeleteStackConsequence(),
			'message' => DeckMessages::planDeleteStack(),
		];
	}

	/**
	 * A board can only be deleted by its owner and empty, so a board of somebody else or one that holds a card has
	 * no plan: the refusal is the answer, as the write would give it.
	 *
	 * @param array{boardId: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 * @throws DeckRefusalException When the caller is not the owner or any list holds a card.
	 */
	private function planDeleteBoard(array $arguments, string $userId): array {
		$boardId = (int)$arguments['boardId'];
		$ownership = $this->gateway()->boardOwnership($userId, $boardId);
		if ($ownership['owner'] !== $userId) {
			throw DeckRefusalException::boardNotOwned();
		}
		$cards = $this->gateway()->boardCardCount($userId, $boardId);
		if ($cards > 0) {
			throw DeckRefusalException::boardNotEmpty($cards);
		}

		return [
			'action' => DeleteBoardHandler::TOOL,
			'board' => ['id' => $boardId, 'title' => $ownership['name'], 'cards' => $cards],
			'stacks' => array_map(
				static fn (\OCA\Deck\Db\Stack $stack): string => (string)$stack->getTitle(),
				$this->gateway()->listStacks($userId, $boardId),
			),
			'shared' => [],
			'recoverable' => true,
			'consequence' => DeckMessages::planDeleteBoardConsequence(),
			'message' => DeckMessages::planDeleteBoard(),
		];
	}

	/**
	 * Where a card lands or comes from: the list, the board behind it and who owns that board.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId Deck list the plan is about.
	 * @return array{stackId: int, list: string|null, board: string, owner: string, ownerDisplayName: string, shared: bool}
	 */
	private function place(string $userId, int $stackId): array {
		$ownership = $this->gateway()->stackOwnership($userId, $stackId);

		return [
			'stackId' => $stackId,
			'list' => $this->listTitle($userId, $stackId),
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
	private function board(array $ownership, string $userId): array {
		return [
			'board' => $ownership['name'],
			'owner' => $ownership['owner'],
			'ownerDisplayName' => $ownership['ownerDisplayName'],
			'shared' => $ownership['owner'] !== $userId,
		];
	}

	/**
	 * Title of the Deck list a stack id names, or null when it cannot be read.
	 *
	 * The Deck gateway has no single-stack query, and a plan is not a hot path: the visible boards are
	 * walked once and the first list that matches wins.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId Deck list to name.
	 * @return string|null The list title, or null when no visible board holds it.
	 */
	private function listTitle(string $userId, int $stackId): ?string {
		foreach ($this->gateway()->listBoards($userId) as $board) {
			foreach ($this->gateway()->listStacks($userId, (int)$board->getId()) as $stack) {
				if ((int)$stack->getId() === $stackId) {
					return (string)$stack->getTitle();
				}
			}
		}

		return null;
	}

	/**
	 * The fields a write can change, as the plan shows them.
	 *
	 * @param \OCA\Deck\Db\Card $card Card as the gateway read it.
	 * @param \DateTimeZone|null $zone Zone the due date is shown in, or null to keep the stored one.
	 * @return array{title: string, description: string, duedate: string|null}
	 */
	private function fields(\OCA\Deck\Db\Card $card, ?\DateTimeZone $zone = null): array {
		return [
			'title' => (string)$card->getTitle(),
			'description' => (string)$card->getDescription(),
			'duedate' => $this->instant($card->getDuedate(), $zone),
		];
	}

	/**
	 * @param mixed $value Value of a Deck `datetime` column.
	 * @param \DateTimeZone|null $zone Zone the instant is shown in, or null to keep the stored one.
	 * @return string|null ISO 8601 instant, or null when the card has no date.
	 */
	private function instant(mixed $value, ?\DateTimeZone $zone = null): ?string {
		if (!$value instanceof \DateTimeInterface) {
			return null;
		}

		return ($zone === null ? $value : \DateTimeImmutable::createFromInterface($value)->setTimezone($zone))
			->format(\DateTimeInterface::ATOM);
	}

	/**
	 * Builds the handler of a tool, or null when the name is unknown.
	 *
	 * @param string $name Tool name as sent by the client.
	 * @param string $userId UID of the authenticated caller, whose timezone the payload speaks.
	 * @return AbstractHandler|null Handler bound to this module's dependencies.
	 */
	private function handlerFor(string $name, string $userId): ?AbstractHandler {
		$formatter = $this->formatter($userId);

		return match ($name) {
			LinkCardFileHandler::TOOL => CardLinkTool::handler($this->container, $this->gateway(), $this->logger, $this->session),
			CardFileHandler::READ, CardFileHandler::UNLINK => $this->fileHandler($name, $userId),
			ChangeCardDependencyHandler::ADD, ChangeCardDependencyHandler::REMOVE => $this->dependencyHandler($name, $userId),
			EditCardLabelsHandler::TOOL => $this->labelHandler($userId),
			ListLabelsHandler::TOOL => new ListLabelsHandler($this->gateway(), $this->logger),
			ListBoardsHandler::TOOL => new ListBoardsHandler($this->gateway(), $this->logger, $formatter),
			ListStacksHandler::TOOL => new ListStacksHandler($this->gateway(), $this->logger, $formatter),
			ListCardsHandler::TOOL => new ListCardsHandler($this->gateway(), $this->logger, $formatter),
			FollowupCardsHandler::TOOL => new FollowupCardsHandler($this->gateway(), $this->logger, $formatter),
			ReadCardDependenciesHandler::TOOL => new ReadCardDependenciesHandler($this->gateway(), $this->logger, $formatter),
			ReadCardHandler::TOOL => new ReadCardHandler($this->gateway(), $this->logger, $formatter),
			CreateCardHandler::TOOL => new CreateCardHandler($this->gateway(), $this->logger, $formatter),
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
	 * @param string $name File read or unlink tool.
	 * @param string $userId Authenticated caller.
	 * @return CardFileHandler Handler with Files guards, opened only when the tool actually runs.
	 */
	private function fileHandler(string $name, string $userId): CardFileHandler {
		$files = fn (): CardFiles => $this->cardFiles ??= new CardFiles($this->gateway(), $this->container->get(GrantPolicy::class),
			$this->container->get(FilesAccessGuard::class), $this->container->get(IRootFolder::class));
		return new CardFileHandler($this->gateway(), $this->logger, $files, $this->formatter($userId),
			fn (): PlanState => new PlanState($this->config ?? $this->container->get(IConfig::class)), $name === CardFileHandler::UNLINK);
	}

	/**
	 * @param string $name Native relation tool.
	 * @param string $userId Authenticated caller.
	 * @return ChangeCardDependencyHandler Handler bound to one direction and lazy plan fingerprint.
	 */
	private function dependencyHandler(string $name, string $userId): ChangeCardDependencyHandler {
		return new ChangeCardDependencyHandler($this->gateway(), $this->logger, $this->formatter($userId),
			fn (): PlanState => new PlanState($this->config ?? $this->container->get(IConfig::class)),
			$name === ChangeCardDependencyHandler::ADD);
	}

	/**
	 * @param string $userId UID of the authenticated caller, whose timezone the payload speaks.
	 * @return EditCardLabelsHandler Handler with a lazy fingerprint service.
	 */
	private function labelHandler(string $userId): EditCardLabelsHandler {
		return new EditCardLabelsHandler($this->gateway(), $this->logger, $this->formatter($userId),
			fn (): PlanState => new PlanState($this->config ?? $this->container->get(IConfig::class)));
	}

	/**
	 * @param string $userId Authenticated caller whose timezone the payload uses.
	 * @return CardFormatter Formatter bound to the module dependencies.
	 */
	private function formatter(string $userId): CardFormatter {
		return new CardFormatter($this->urlGenerator, $this->timeFactory, $this->userManager, $this->zones()?->forUser($userId));
	}

	/** @return DeckCapabilities Fresh version/native-shape gate, with no Deck DI resolution. */
	private function dependencyCapabilities(): DeckCapabilities {
		return $this->capabilities ??= new DeckCapabilities($this->appManager);
	}

	/**
	 * Deck gateway of this request, created on first use.
	 *
	 * @return DeckGatewayInterface Gateway that resolves Deck services through the container.
	 */
	private function gateway(): DeckGatewayInterface {
		return $this->gateway ??= new DeckServiceGateway($this->container, $this->timeFactory, $this->zones(), $this->session);
	}

	/**
	 * @return UserTimezone|null Resolver of the caller's timezone, or null when no config was given.
	 */
	private function zones(): ?UserTimezone {
		return $this->config === null ? null : new UserTimezone($this->config);
	}

	/**
	 * Schema of a tool that takes no arguments.
	 *
	 * @return array<string, mixed> Object schema with no properties.
	 */
	private function emptySchema(): array {
		return ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false];
	}

	/**
	 * Builds an object schema that rejects unknown properties.
	 *
	 * A tool whose arguments are all optional leaves `required` out entirely: the MCP contract test
	 * refuses an empty array there, and "absent" is the correct way to say "nothing is demanded".
	 *
	 * @param array<string, array<string, mixed>> $properties Declared properties.
	 * @param list<string> $required Property names that must be present.
	 * @return array<string, mixed> JSON Schema for the tool arguments.
	 */
	private function schema(array $properties, array $required): array {
		$schema = [
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => false,
		];

		if ($required !== []) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * @param string $description Parameter description.
	 * @param int|null $minimum Inclusive lower bound.
	 * @param int|null $maximum Inclusive upper bound.
	 * @param int|null $default Value applied by the registry when the argument is absent.
	 * @return array<string, mixed> Integer property definition.
	 */
	private function integer(string $description, ?int $minimum = null, ?int $maximum = null, ?int $default = null): array {
		$property = ['type' => 'integer', 'description' => $description];
		if ($minimum !== null) {
			$property['minimum'] = $minimum;
		}
		if ($maximum !== null) {
			$property['maximum'] = $maximum;
		}
		if ($default !== null) {
			$property['default'] = $default;
		}

		return $property;
	}

	/**
	 * @param string $description Parameter description.
	 * @return array<string, mixed> Array of at most 100 account IDs.
	 */
	private function accountList(string $description): array {
		return ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255], 'description' => $description];
	}

	/**
	 * @return array<string, mixed> Optional shared-resource confirmation property of a write tool.
	 */
	private function confirmShared(): array {
		return ['type' => 'boolean', 'description' => DeckMessages::PARAM_CONFIRM_SHARED];
	}

	/**
	 * @param string $description Parameter description.
	 * @param int $minimum Inclusive minimum length.
	 * @param int|null $maximum Inclusive maximum length.
	 * @param string|null $default Value applied by the registry when the argument is absent.
	 * @return array<string, mixed> String property definition.
	 */
	private function string(string $description, int $minimum, ?int $maximum = null, ?string $default = null): array {
		$property = ['type' => 'string', 'description' => $description, 'minLength' => $minimum];
		if ($maximum !== null) {
			$property['maxLength'] = $maximum;
		}
		if ($default !== null) {
			$property['default'] = $default;
		}

		return $property;
	}

	/**
	 * Builds a string property that also accepts null, used by the optional dates.
	 *
	 * @param string $description Parameter description.
	 * @param string|null $default Value applied by the registry when the argument is absent, or null for none.
	 * @return array<string, mixed> Property definition accepting a string or null.
	 */
	private function nullableString(string $description, ?string $default = null): array {
		$property = ['type' => ['string', 'null'], 'description' => $description];
		if ($default !== null) {
			$property['default'] = $default;
		}

		return $property;
	}
}
