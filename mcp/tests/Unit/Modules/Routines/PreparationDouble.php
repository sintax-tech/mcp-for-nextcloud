<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Platform\Meetings\MeetingDeckRead;
use OCA\Mcp\Platform\Meetings\MeetingEventRead;
use OCA\Mcp\Platform\Meetings\MeetingFileRead;
use OCA\Mcp\Platform\Meetings\MeetingMailRead;

/** The four read ports, recording every call and answering from the closures the test supplies. */
final class PreparationDouble implements MeetingEventRead, MeetingMailRead, MeetingDeckRead, MeetingFileRead {
	/** @var list<array{0: string, 1: array<string, mixed>}> calls in order, name then arguments */
	public array $calls = [];

	/** @var list<int> mailbox ids the mail port offers, inbox first */
	public array $folders = [3];

	/**
	 * @param array<string, mixed> $events the calendar answer
	 * @param \Closure(string, array<string, mixed>): array<string, mixed>|null $search mail answer
	 * @param \Closure(string, array<string, mixed>): array<string, mixed>|null $card Deck card answer
	 * @param \Closure(string, array<string, mixed>): array<string, mixed>|null $links Deck links answer
	 * @param \Closure(string, array<string, mixed>): array<string, mixed>|null $read file answer
	 * @param \Closure(string, string): list<int>|null $assigned card ids of one account
	 * @param \Closure(string, string): string|null|null $locate path of one file reference
	 */
	public function __construct(
		private array $events = ['content' => [['type' => 'text', 'text' => '[]']]],
		private ?\Closure $search = null,
		private ?\Closure $card = null,
		private ?\Closure $links = null,
		private ?\Closure $read = null,
		private ?\Closure $assignedCards = null,
		private ?\Closure $locatePath = null,
	) {
	}

	/**
	 * @param mixed $data a JSON payload
	 * @return array{content: list<array{type: string, text: string}>} one MCP text block
	 */
	public static function json(mixed $data): array {
		return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_THROW_ON_ERROR)]]];
	}

	/** {@inheritDoc} */
	public function events(string $userId, array $arguments): array {
		$this->calls[] = ['events', $arguments];
		return $this->events;
	}

	/** {@inheritDoc} */
	public function search(string $userId, array $arguments): array {
		return $this->answer('search', $this->search, $userId, $arguments);
	}

	/** {@inheritDoc} */
	public function folders(string $userId): array {
		$this->calls[] = ['folders', []];
		return $this->folders;
	}

	/** {@inheritDoc} */
	public function card(string $userId, array $arguments): array {
		return $this->answer('card', $this->card, $userId, $arguments);
	}

	/** {@inheritDoc} */
	public function links(string $userId, array $arguments): array {
		return $this->answer('links', $this->links, $userId, $arguments);
	}

	/** {@inheritDoc} */
	public function read(string $userId, array $arguments): array {
		return $this->answer('read', $this->read, $userId, $arguments);
	}

	/** {@inheritDoc} */
	public function assigned(string $userId, string $participantUid): array {
		$this->calls[] = ['assigned', ['participant' => $participantUid]];
		if ($this->assignedCards === null) {
			return [];
		}
		$ids = ($this->assignedCards)($userId, $participantUid);
		return is_array($ids) ? array_values(array_filter($ids, static fn (mixed $id): bool => is_int($id))) : [];
	}

	/** {@inheritDoc} */
	public function locate(string $userId, string $reference): ?string {
		$placed = $this->placed($userId, $reference);
		return $placed['path'] ?? null;
	}

	/** {@inheritDoc} */
	public function placed(string $userId, string $reference): ?array {
		$this->calls[] = ['placed', ['reference' => $reference]];
		if ($this->locatePath === null) {
			return null;
		}
		$path = ($this->locatePath)($userId, $reference);
		if (!is_string($path) || $path === '') {
			return null;
		}
		return ['path' => $path, 'id' => 15, 'mtime' => 1710000000, 'url' => 'https://cloud.example/f/15'];
	}

	/**
	 * @param string $name the port method
	 * @param \Closure(string, array<string, mixed>): array<string, mixed>|null $next the test answer
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of the call
	 * @return array<string, mixed> the answer
	 */
	private function answer(string $name, ?\Closure $next, string $userId, array $arguments): array {
		$this->calls[] = [$name, $arguments];
		if ($next === null) {
			return self::json([]);
		}
		return $next($userId, $arguments);
	}
}
