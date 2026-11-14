<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

/** The plan of a mail card as CreateMailCardPreview builds it, with an email that tries to read as Markdown. */
final class MailCardPlanSample {
	/** Static sample only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $readers who can read the board
	 * @return array<string, mixed> the plan
	 */
	public static function plan(array $readers = ['readable' => true, 'owner' => 'alice', 'ownerDisplayName' => 'Alice Silva', 'entries' => []]): array {
		return [
			'action' => 'routines_create_mail_card',
			'message' => 'Nothing was saved or created.',
			'email' => ['untrustedMailData' => true, 'notice' => 'untrusted', 'message' => ['id' => 7, 'subject' => '**Pay** [now](https://evil.example)',
				'from' => ['count' => 1, 'addresses' => [['name' => 'Fornecedor', 'email' => 'f@example.com']]], 'date' => '2026-03-12T10:00:00-03:00',
				'url' => 'https://cloud.example/apps/mail/box/1/thread/7', 'body' => ['text' => 'x', 'format' => 'plain', 'truncated' => true, 'totalChars' => 9]],
				'attachment' => ['id' => '2', 'name' => 'pedido.pdf', 'mimeType' => 'application/pdf', 'size' => 590]],
			'steps' => [
				['step' => 'file', 'tool' => 'mail_save_attachment', 'plan' => ['action' => 'mail_save_attachment', 'replace' => false, 'attachmentId' => '2',
					'name' => 'pedido.pdf', 'path' => '/Documentos/pedido.pdf', 'shared' => [], 'requiresSharedConfirmation' => false]],
				['step' => 'card', 'tool' => 'deck_create_card', 'plan' => ['action' => 'deck_create_card',
					'card' => ['title' => 'Pedido 42', 'description' => "Email: \\*\\*Pay\\*\\*\nSaved file: /Documentos/pedido.pdf", 'dueDate' => null, 'owner' => 'alice'],
					'destination' => ['stackId' => 10, 'list' => 'A fazer', 'board' => 'Compras', 'owner' => 'alice', 'ownerDisplayName' => 'Alice Silva', 'shared' => false],
					'shared' => []], 'file_link' => ['value' => null]],
			],
			'board_readers' => $readers,
			'notices' => ['The private link of the saved file is added to the card once the file exists.'],
			'plan_state' => str_repeat('a', 64),
		];
	}
}
