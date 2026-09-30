<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckResult;
use Psr\Log\LoggerInterface;

/**
 * Base of every Deck tool handler.
 *
 * A handler only decides what to ask the gateway for and how to present the answer; running it
 * inside {@see self::run()} is what turns any Deck failure into a generic error result.
 */
abstract class AbstractHandler {
	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives the tool name and the exception class, never its message.
	 */
	public function __construct(
		protected DeckGatewayInterface $gateway,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * Executes one tool call.
	 *
	 * @param array<string, mixed> $arguments Arguments already validated against the tool schema.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws InvalidArgumentException For semantic problems the JSON Schema cannot express, mapped to -32602.
	 */
	abstract public function handle(array $arguments, string $userId): array;

	/**
	 * Runs a Deck operation and wraps the outcome in an MCP result.
	 *
	 * `InvalidArgumentException` is rethrown because the registry turns it into a -32602 for the
	 * caller; every other exception becomes a generic error result and is logged by class only.
	 *
	 * @param callable(): mixed $operation Deck operation to run.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws InvalidArgumentException When the operation rejects its arguments.
	 */
	protected function run(callable $operation): array {
		try {
			return DeckResult::ok($operation());
		} catch (InvalidArgumentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$context = [
				'tool' => $this->toolName(),
				'exception' => $e::class,
			];

			if ($e instanceof DeckConflictException) {
				$this->logger->warning('MCP Deck tool conflicted: {tool}', $context);
			} else {
				$this->logger->error('MCP Deck tool failed: {tool} ({exception})', $context);
			}

			return DeckResult::error(DeckErrors::messageFor($e));
		}
	}

	/**
	 * Shared-resource gate every write tool runs before touching the Deck.
	 *
	 * A board owned by somebody else is written only when the call repeats with
	 * `confirm_shared: true`; otherwise the payload below is returned as a normal, non-error
	 * result, so the agent can read it, ask the user and call the tool again. The caller's own
	 * board never needs the flag.
	 *
	 * @param array<string, mixed> $arguments Arguments of the call, read for `confirm_shared`.
	 * @param string $userId UID of the authenticated caller.
	 * @param array{owner: string, ownerDisplayName: string, name: string} $ownership Board the write would touch.
	 * @return array<string, mixed>|null Payload to answer with instead of writing, or null to proceed.
	 */
	protected function confirmShared(array $arguments, string $userId, array $ownership): ?array {
		if ($ownership['owner'] === $userId || ($arguments['confirm_shared'] ?? null) === true) {
			return null;
		}

		return [
			'requiresConfirmation' => true,
			'scope' => 'shared',
			'owner' => $ownership['owner'],
			'ownerDisplayName' => $ownership['ownerDisplayName'],
			'resource' => $ownership['name'],
			'message' => DeckMessages::sharedConfirmation($ownership['name'], $ownership['ownerDisplayName']),
		];
	}

	/**
	 * Name of the tool this handler serves, used in the log line.
	 *
	 * @return string MCP tool name, for example `deck_read_card`.
	 */
	abstract protected function toolName(): string;
}
