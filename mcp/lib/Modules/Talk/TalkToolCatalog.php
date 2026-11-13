<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk;

use OCA\Mcp\Modules\Talk\Tools\ListConversations;
use OCA\Mcp\Modules\Talk\Tools\ListUnread;
use OCA\Mcp\Modules\Talk\Tools\ReadUnread;
use OCA\Mcp\Modules\Talk\Tools\ReadMessages;
use OCA\Mcp\Modules\Talk\Tools\Reply;
use OCA\Mcp\Modules\Talk\Tools\AttachFile;
use OCA\Mcp\Modules\Talk\Tools\QuoteFile;
use OCA\Mcp\Modules\Talk\Tools\MessageUser;
use OCA\Mcp\Modules\Talk\Tools\SendBatch;
use OCA\Mcp\Modules\Talk\Tools\CreateGroup;

/** Talk tools in their published order. talk_list_unread and talk_read_unread come last so the earlier eight stay in place. */
final class TalkToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> static discovery order */
	public const TOOLS = [
		ListConversations::class,
		ReadMessages::class,
		Reply::class,
		AttachFile::class,
		QuoteFile::class,
		MessageUser::class,
		SendBatch::class,
		CreateGroup::class,
		ListUnread::class,
		ReadUnread::class,
	];

	/** Static catalog only. */
	private function __construct() {
	}
}
