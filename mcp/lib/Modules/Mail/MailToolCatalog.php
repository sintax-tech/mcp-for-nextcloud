<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Modules\Mail\Tools\ListAccounts;
use OCA\Mcp\Modules\Mail\Tools\ListMailboxes;
use OCA\Mcp\Modules\Mail\Tools\ListMessages;
use OCA\Mcp\Modules\Mail\Tools\SearchMessages;
use OCA\Mcp\Modules\Mail\Tools\ReadMessage;
use OCA\Mcp\Modules\Mail\Tools\ReadThread;
use OCA\Mcp\Modules\Mail\Tools\ReadAttachment;
use OCA\Mcp\Modules\Mail\Tools\SetFlags;
use OCA\Mcp\Modules\Mail\Tools\MoveMessage;
use OCA\Mcp\Modules\Mail\Tools\CreateDraft;
use OCA\Mcp\Modules\Mail\Tools\SendMessage;

/** The twelve Mail tools in their published order, including the conditional attachment read. */
final class MailToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> published tool classes in tools/list order */
	public const TOOLS = [
		ListAccounts::class,
		ListMailboxes::class,
		ListMessages::class,
		SearchMessages::class,
		ReadMessage::class,
		ReadThread::class,
		ReadAttachment::class,
		SetFlags::class,
		MoveMessage::class,
		CreateDraft::class,
		SendMessage::class,
		\OCA\Mcp\Modules\Mail\Tools\SaveAttachment::class,
	];

	/** Static ordered catalog only. */
	private function __construct() {}
}
