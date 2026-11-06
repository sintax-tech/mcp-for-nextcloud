<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

use Closure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Attachments\CardFiles;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;
use Throwable;

/** Lists authorized existing file references, or unlinks exactly one without dispatching legacy deletion. */
final class CardFileHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	public const READ = 'deck_list_card_files';
	public const UNLINK = 'deck_unlink_card_file';
	private ?CardFiles $access = null;

	/**
	 * @param DeckGatewayInterface $gateway Native boundary for the shared-board gate.
	 * @param LoggerInterface $logger Receives exception classes only.
	 * @param Closure(): CardFiles $filesFactory Lazy Files grant/scope/exclusion/READ and association boundary.
	 * @param CardFormatter $formatter Safe source reference.
	 * @param Closure(): PlanState $stateFactory Instance-secret HMAC.
	 * @param bool $unlink False to list; true to unlink.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private Closure $filesFactory,
		private CardFormatter $formatter, private Closure $stateFactory, private bool $unlink) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * @param array<string, mixed> $arguments Card and association ID, without confirmation receipt.
	 * @param string $userId Authenticated caller.
	 * @return array<string, mixed> Only currently visible metadata and signed confirmation receipt.
	 * @throws Throwable On missing/invisible/legacy/folder association or denied source/grants.
	 */
	public function preview(array $arguments, string $userId): array {
		if (array_key_exists('file_state', $arguments)) {
			throw new ArgumentValidationException('Invalid arguments', 'file_state', 'only accepted with confirm:true');
		}
		return $this->plan($this->files()->state($userId, (int)$arguments['cardId'], (int)$arguments['attachmentId']), $userId);
	}

	/** {@inheritDoc} The registry owns the mandatory confirm:true gate. */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$cardId = (int)$arguments['cardId'];
			if (!$this->unlink) {
				return $this->files()->list($userId, $cardId);
			}
			PlanState::require($arguments);
			$receipt = $arguments['file_state'] ?? null;
			if (!is_array($receipt) || !isset($receipt['fileId'], $receipt['createdBy'], $receipt['createdAt'])) {
				throw CardFiles::unavailable();
			}
			// Schema validates types; normalize order so JSON object key order never changes the signature.
			$receipt = ['fileId' => $receipt['fileId'], 'createdBy' => $receipt['createdBy'], 'createdAt' => $receipt['createdAt']];
			$attachmentId = (int)$arguments['attachmentId'];
			$state = $this->files()->state($userId, $cardId, $attachmentId, $receipt);
			$plan = $this->plan($state, $userId);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				// Same refusal for altered receipt, replay, stale state or hidden/missing association.
				throw CardFiles::unavailable();
			}
			$shared = $this->confirmShared($arguments, $userId, $state['context']['board']);
			if ($shared !== null) {
				return $shared;
			}
			if (!$state['present']) {
				if ($this->files()->exists($userId, $cardId, $attachmentId, $state['node'])) {
					throw CardFiles::unavailable();
				}
				return ['confirmed' => true, 'unlinked' => true, 'changed' => false];
			}
			$error = null;
			try {
				$this->files()->unlink($userId, $cardId, $attachmentId, $state['snapshot']);
			} catch (DeckConflictException) {
				throw CardFiles::unavailable(); // Exclusive pre-write conflict: require a new preview, never retry.
			} catch (Throwable $e) {
				$error = $e;
			}
			try {
				$exists = $this->files()->exists($userId, $cardId, $attachmentId, $state['node']);
			} catch (Throwable $e) {
				$this->logger->warning('MCP Deck file unlink result unknown ({exception})', ['exception' => $e::class]);
				return ['confirmed' => false, 'message' => DeckMessages::fileUnconfirmed()];
			}
			if ($exists) {
				if ($error !== null) {
					throw $error;
				}
				return ['confirmed' => false, 'message' => DeckMessages::fileUnconfirmed()];
			}
			$result = ['confirmed' => true, 'unlinked' => true, 'changed' => true, 'verification' => 'observed-state'];
			if ($error !== null) {
				$this->logger->warning('MCP Deck file unlink observed after failure ({exception})', ['exception' => $error::class]);
				$result['warnings'] = [DeckMessages::fileUnlinkObserved()];
			}
			// No old file metadata is echoed: losing the last Deck mount is the expected native effect.
			return $result;
		});
	}

	/**
	 * @param array<string, mixed> $state Fresh source/file state; presence deliberately excluded from receipt signature.
	 * @param string $userId Authenticated caller whose identity the HMAC binds.
	 * @return array<string, mixed> Plan and receipt that remain verifiable for a still-visible file after unlink.
	 */
	private function plan(array $state, string $userId): array {
		$context = $state['context'];
		$board = $context['board'] + ['shared' => $context['board']['owner'] !== $userId];
		return ['action' => self::UNLINK, 'card' => $this->formatter->reference($context['card'], $context['boardId']),
			'board' => $board, 'file' => $state['file'], 'attachmentId' => $state['snapshot']['attachmentId'],
			'createdBy' => $state['file_state']['createdBy'], 'file_state' => $state['file_state'],
			'shared' => $board['shared'] ? [$board] : [], 'recoverable' => false,
			'message' => DeckMessages::fileUnlinkWarning(),
			PlanState::ARGUMENT => ($this->stateFactory)()->of(self::UNLINK, $userId, $state['snapshot'])];
	}

	/** @return CardFiles File access resolved only after the module app gate admits the actual operation. */
	private function files(): CardFiles {
		return $this->access ??= ($this->filesFactory)();
	}

	/** {@inheritDoc} */
	protected function toolName(): string {
		return $this->unlink ? self::UNLINK : self::READ;
	}
}
