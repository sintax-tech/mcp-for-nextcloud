<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\DeckResult;
use OCA\Mcp\Tools\Deck\DeckSessionException;
use OCA\Mcp\Tools\Deck\DeckUnconfirmedException;
use Psr\Log\LoggerInterface;

/**
 * Base of every Deck tool handler.
 *
 * A handler only decides what to ask the gateway for and how to present the answer; running it
 * inside {@see self::run()} is what turns any Deck failure into a generic error result.
 *
 * A write never answers with an error once it may have been saved (re-review of c06b2e0): Deck 1.17.5 writes first
 * and only then runs activity, events and notifications, which may throw. Every write call goes through
 * {@see self::afterWrite()}, which reads the real state again after an exception of the write itself.
 */
abstract class AbstractHandler {
	/**
	 * Exceptions this app raises itself before it calls Deck, or after it already read the state: they stay as they
	 * are and are not read back. Deck's own `NoPermissionException` and `BadRequestException` are deliberately not
	 * here (third round of the review): Deck dispatches events after it writes, and a listener of another app may throw
	 * those classes with the data already saved, so the class proves nothing and the state is read again.
	 */
	private const RAISED_BY_THE_APP = [
		DeckRefusalException::class,
		DeckSessionException::class,
		DeckConflictException::class,
		DeckUnconfirmedException::class,
		InvalidArgumentException::class,
	];

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
		} catch (DeckUnconfirmedException $e) {
			// Maybe saved: an answer that is not an error and says where to look, never one that invites a retry.
			$this->logger->warning('MCP Deck write not confirmed: {tool} ({exception})', [
				'tool' => $this->toolName(),
				'exception' => $e->getPrevious() === null ? $e::class : $e->getPrevious()::class,
			]);

			return DeckResult::ok(['confirmed' => false, 'message' => DeckMessages::writeUnconfirmed($e->readWith)]);
		} catch (\Throwable $e) {
			$context = [
				'tool' => $this->toolName(),
				'exception' => $e::class,
			];

			if ($e instanceof DeckRefusalException && $e->afterWrite()) {
				// The delete stays (the undo failed): partial, with the message that points to the Deck trash.
				$this->logger->warning('MCP Deck tool refused after the write: {tool}', $context);

				return DeckResult::ok(['partial' => true, 'message' => $e->getMessage()]);
			}

			if ($e instanceof DeckConflictException || $e instanceof DeckRefusalException) {
				$this->logger->warning('MCP Deck tool refused or conflicted: {tool}', $context);
			} else {
				$this->logger->error('MCP Deck tool failed: {tool} ({exception})', $context);
			}

			return DeckResult::error(DeckErrors::messageFor($e));
		}
	}

	/**
	 * Runs one Deck write and, when the write call itself throws, answers with what Deck really holds.
	 *
	 * A refusal of this app ({@see self::RAISED_BY_THE_APP}) is rethrown as it is. Any other exception, Deck's own
	 * `NoPermissionException` and `BadRequestException` included, is followed by `$verify`, which reads the state
	 * again by identifier: what it returns is the saved result, and the call goes on as a success with
	 * {@see DeckMessages::writtenThenFailed()} in `$warnings`; null means nothing was saved and the original exception
	 * goes on to become the usual error; an exception of `$verify` means nobody can tell, which becomes
	 * {@see DeckUnconfirmedException}. Only exception classes are logged.
	 *
	 * @template T
	 * @param callable(): T $write The Deck write.
	 * @param callable(): mixed $verify Read-back: the saved result, or null when the write did not happen.
	 * @param list<string> $warnings Receives the warning when the write was saved and Deck failed afterwards.
	 * @return mixed What the write returned, or what `$verify` found.
	 * @throws DeckUnconfirmedException When the read-back fails too.
	 * @throws \Throwable The original exception, when nothing was saved.
	 */
	protected function afterWrite(callable $write, callable $verify, array &$warnings): mixed {
		$probable = false;

		return $this->guardedWrite($write, $verify, $warnings, false, $probable);
	}

	/**
	 * Like {@see self::afterWrite()}, for a creation whose read-back only finds the item by likeness (owner, title and
	 * moment; a title that was not in the snapshot) and so cannot prove that this call made it.
	 *
	 * What `$verify` finds is only probably the item: `$probable` becomes true and `$warnings` gets
	 * {@see DeckMessages::probablyCreated()} in place of the "saved" warning. The caller must not write anything
	 * else on that item (no assignment, no list or card inside it).
	 *
	 * @template T
	 * @param callable(): T $write The Deck write.
	 * @param callable(): mixed $verify Read-back by likeness: the item found, or null when there is none.
	 * @param list<string> $warnings Receives the warning.
	 * @param bool $probable Set to true when the result came from the read-back by likeness.
	 * @return mixed What the write returned, or what `$verify` found.
	 * @throws DeckUnconfirmedException When the read-back fails too.
	 * @throws \Throwable The original exception, when nothing was found.
	 */
	protected function afterApproximateWrite(callable $write, callable $verify, array &$warnings, bool &$probable): mixed {
		return $this->guardedWrite($write, $verify, $warnings, true, $probable);
	}

	/**
	 * Shared body of {@see self::afterWrite()} and {@see self::afterApproximateWrite()}.
	 *
	 * @param callable(): mixed $write The Deck write.
	 * @param callable(): mixed $verify Read-back.
	 * @param list<string> $warnings Receives the warning.
	 * @param bool $approximate Whether the read-back only finds the item by likeness.
	 * @param bool $probable Set to true when `$approximate` and the read-back found something.
	 * @return mixed What the write returned, or what `$verify` found.
	 */
	private function guardedWrite(callable $write, callable $verify, array &$warnings, bool $approximate, bool &$probable): mixed {
		try {
			return $write();
		} catch (\Throwable $e) {
			foreach (self::RAISED_BY_THE_APP as $class) {
				if (is_a($e, $class)) {
					throw $e;
				}
			}
			try {
				$saved = $verify();
			} catch (\Throwable) {
				throw new DeckUnconfirmedException($this->readWith(), $e);
			}
			if ($saved === null) {
				throw $e;
			}
			$this->logger->warning('MCP Deck write saved, then Deck failed: {tool} ({exception})', [
				'tool' => $this->toolName(),
				'exception' => $e::class,
			]);
			$warning = $approximate ? DeckMessages::probablyCreated() : DeckMessages::writtenThenFailed();
			$probable = $approximate;
			if (!in_array($warning, $warnings, true)) {
				$warnings[] = $warning;
			}

			return $saved;
		}
	}

	/**
	 * The assignment of one account on a card, read again from Deck.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card to look at.
	 * @param string $uid Account assigned.
	 * @return Assignment|null The user assignment of that account, or null when it has none.
	 */
	protected function assignmentOf(string $userId, int $cardId, string $uid): ?Assignment {
		foreach ($this->gateway->cardAssignments($userId, $cardId) as $assignment) {
			// Type 0 is `Assignment::TYPE_USER` (Deck v1.17.5), the only kind these tools assign.
			if ((string)$assignment->getParticipant() === $uid && (int)$assignment->getType() === 0) {
				return $assignment;
			}
		}

		return null;
	}

	/**
	 * The list a creation added, among the lists read again after it threw.
	 *
	 * @param list<Stack> $stacks Active lists of the board now.
	 * @param string $title Title of the list asked for.
	 * @param list<int> $known Ids that existed before, or were created earlier in the same call.
	 * @return Stack|null The newest list with that title that is not one of `$known`, or null.
	 */
	protected static function newStack(array $stacks, string $title, array $known): ?Stack {
		$found = null;
		foreach ($stacks as $stack) {
			if (!in_array((int)$stack->getId(), $known, true) && (string)$stack->getTitle() === $title
				&& ($found === null || (int)$stack->getId() > (int)$found->getId())) {
				$found = $stack;
			}
		}

		return $found;
	}

	/**
	 * Tool the answer names when a write cannot be confirmed, so the client reads the item before retrying.
	 *
	 * @return string MCP tool name.
	 */
	protected function readWith(): string {
		return 'deck_read_card';
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
