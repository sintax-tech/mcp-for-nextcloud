<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\Handler\AbstractHandler;
use OCA\Mcp\Tools\Deck\Handler\CreateCardHandler;
use OCA\Mcp\Tools\Deck\Handler\DeleteCardHandler;
use OCA\Mcp\Tools\Deck\Handler\EditCardHandler;
use OCA\Mcp\Tools\Deck\Handler\ListBoardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListCardsHandler;
use OCA\Mcp\Tools\Deck\Handler\ListStacksHandler;
use OCA\Mcp\Tools\Deck\Handler\MoveCardHandler;
use OCA\Mcp\Tools\Deck\Handler\ReadCardHandler;
use OCA\Mcp\Tools\ToolModule;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The Deck tool module: eight tools that read, create, edit, move and delete Deck cards.
 *
 * This class only declares the tools and routes a call to a handler. It holds no Deck logic and
 * resolves no Deck class at construction time, because the Deck app may not be installed: the
 * tools declare `app: 'deck'` so the registry hides them, and the handler is built per call.
 */
final class DeckToolModule implements ToolModule {
	/** Nextcloud app id whose presence the whole module depends on. */
	public const DECK_APP = 'deck';

	/** Grant module every Deck tool belongs to. */
	private const MODULE = 'deck';

	/** Maximum position accepted by `deck_move_card`, matching the Deck `order` column. */
	private const MAX_ORDER = 99999;

	/** Default page size of `deck_list_cards`. */
	private const DEFAULT_LIMIT = 50;

	private ?DeckGatewayInterface $gateway = null;

	/**
	 * @param ContainerInterface $container Nextcloud server container, resolves the Deck services lazily.
	 * @param IAppManager $appManager Tells whether the Deck app is enabled for the caller.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
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
		return [
			[
				'name' => ListBoardsHandler::TOOL,
				'description' => DeckMessages::TOOL_LIST_BOARDS_DESCRIPTION,
				'inputSchema' => $this->emptySchema(),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::DECK_APP,
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
				'description' => DeckMessages::TOOL_CREATE_CARD_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'stackId' => $this->integer(DeckMessages::PARAM_STACK_ID),
						'title' => $this->string(DeckMessages::PARAM_TITLE, 1, 255),
						'description' => $this->string(DeckMessages::PARAM_DESCRIPTION, 0, null, ''),
						'duedate' => $this->nullableString(DeckMessages::PARAM_DUEDATE, null),
					],
					['stackId', 'title'],
				),
				'module' => self::MODULE,
				'operation' => 'create',
				'app' => self::DECK_APP,
			],
			[
				'name' => EditCardHandler::TOOL,
				'description' => DeckMessages::TOOL_EDIT_CARD_DESCRIPTION,
				// `duedate` has no default here on purpose: absent means "keep the current date",
				// while an explicit null clears it.
				'inputSchema' => $this->schema(
					[
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'title' => $this->string(DeckMessages::PARAM_TITLE, 1, 255),
						'description' => $this->string(DeckMessages::PARAM_DESCRIPTION, 0),
						'duedate' => $this->nullableString(DeckMessages::PARAM_DUEDATE),
						'lastModified' => $this->integer(DeckMessages::PARAM_LAST_MODIFIED, 0),
					],
					['cardId'],
				),
				'module' => self::MODULE,
				'operation' => 'edit',
				'app' => self::DECK_APP,
			],
			[
				'name' => MoveCardHandler::TOOL,
				'description' => DeckMessages::TOOL_MOVE_CARD_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'stackId' => $this->integer(DeckMessages::PARAM_STACK_ID),
						'order' => $this->integer(DeckMessages::PARAM_ORDER, 0, self::MAX_ORDER),
					],
					['cardId', 'stackId'],
				),
				'module' => self::MODULE,
				'operation' => 'move',
				'app' => self::DECK_APP,
			],
			[
				'name' => DeleteCardHandler::TOOL,
				'description' => DeckMessages::TOOL_DELETE_CARD_DESCRIPTION,
				'inputSchema' => $this->schema(
					[
						'cardId' => $this->integer(DeckMessages::PARAM_CARD_ID),
						'confirm' => ['type' => 'boolean', 'const' => true, 'description' => DeckMessages::PARAM_CONFIRM],
					],
					['cardId', 'confirm'],
				),
				'module' => self::MODULE,
				'operation' => 'delete',
				'app' => self::DECK_APP,
			],
		];
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
		$handler = $this->handlerFor($name);
		if ($handler === null) {
			throw new InvalidArgumentException(DeckMessages::ERROR_UNKNOWN_TOOL);
		}

		// The registry already filters by app; this is the safety net for any other caller.
		if (!$this->appManager->isEnabledForUser(self::DECK_APP, $userId)) {
			throw new InvalidArgumentException(DeckMessages::ERROR_DECK_APP_UNAVAILABLE);
		}

		return $handler->handle($arguments, $userId);
	}

	/**
	 * Builds the handler of a tool, or null when the name is unknown.
	 *
	 * @param string $name Tool name as sent by the client.
	 * @return AbstractHandler|null Handler bound to this module's dependencies.
	 */
	private function handlerFor(string $name): ?AbstractHandler {
		$formatter = new CardFormatter();

		return match ($name) {
			ListBoardsHandler::TOOL => new ListBoardsHandler($this->gateway(), $this->logger, $formatter),
			ListStacksHandler::TOOL => new ListStacksHandler($this->gateway(), $this->logger, $formatter),
			ListCardsHandler::TOOL => new ListCardsHandler($this->gateway(), $this->logger, $formatter),
			ReadCardHandler::TOOL => new ReadCardHandler($this->gateway(), $this->logger, $formatter),
			CreateCardHandler::TOOL => new CreateCardHandler($this->gateway(), $this->logger, $formatter),
			EditCardHandler::TOOL => new EditCardHandler($this->gateway(), $this->logger, $formatter),
			MoveCardHandler::TOOL => new MoveCardHandler($this->gateway(), $this->logger, $formatter),
			DeleteCardHandler::TOOL => new DeleteCardHandler($this->gateway(), $this->logger, $formatter),
			default => null,
		};
	}

	/**
	 * Deck gateway of this request, created on first use.
	 *
	 * @return DeckGatewayInterface Gateway that resolves Deck services through the container.
	 */
	private function gateway(): DeckGatewayInterface {
		return $this->gateway ??= new DeckServiceGateway($this->container);
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
	 * @param array<string, array<string, mixed>> $properties Declared properties.
	 * @param list<string> $required Property names that must be present.
	 * @return array<string, mixed> JSON Schema for the tool arguments.
	 */
	private function schema(array $properties, array $required): array {
		return [
			'type' => 'object',
			'properties' => $properties,
			'required' => $required,
			'additionalProperties' => false,
		];
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
