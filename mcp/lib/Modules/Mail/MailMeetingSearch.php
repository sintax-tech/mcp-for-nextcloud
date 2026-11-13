<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Locating\MailMessageScope;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCA\Mcp\Modules\Mail\Reading\MailSearchListing;
use OCA\Mcp\Modules\Mail\Tools\SearchMessages;
use OCA\Mcp\Platform\Meetings\MeetingMailRead;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;

/**
 * The mail read of a meeting dossier, through the search of mail_search_messages and nothing else.
 *
 * The registry's own check (grant, Guests and the Mail app) runs again, then the published schema. The search does
 * not fetch a body and does not change flags, so a read marker of the mailbox stays where the search leaves it.
 */
final class MailMeetingSearch implements MeetingMailRead {
	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param MailSearchListing $search the read of mail_search_messages
	 * @param MailGateway $accounts the accounts of the actor
	 * @param MailMessageGateway $messages the cached folders of those accounts
	 * @param MailRows $rows the order the Mail reader shows, inbox first
	 */
	public function __construct(
		private ToolAccess $access,
		private MailSearchListing $search,
		private MailGateway $accounts,
		private MailMessageGateway $messages,
		private MailRows $rows = new MailRows(),
	) {
	}

	/** {@inheritDoc} */
	public function search(string $userId, array $arguments): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(SearchMessages::definition()->name));
		}
		return $this->search->searchMessages(ArgumentValidator::validate(SearchMessages::definition()->inputSchema, $arguments), $userId);
	}

	/** {@inheritDoc} */
	public function folders(string $userId): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(SearchMessages::definition()->name));
		}
		$ids = [];
		$now = time();
		foreach ($this->accounts->accounts($userId) as $account) {
			$owned = $this->accounts->ownedAccount($userId, (int)$account['id']);
			if ($owned === null) {
				continue;
			}
			$shaped = [];
			foreach ($this->messages->mailboxes($userId, $owned) as $mailbox) {
				if (MailMessageScope::unreadable($mailbox, $now) !== null) {
					continue;
				}
				$shaped[] = $this->rows->mailbox([
					'id' => (int)$mailbox->getId(),
					'name' => (string)$mailbox->getName(),
					'delimiter' => $mailbox->getDelimiter() === null ? null : (string)$mailbox->getDelimiter(),
					'specialUse' => (string)($mailbox->getSpecialUse() ?? '[]'),
					'unread' => null,
					'total' => null,
					'selectable' => true,
				]);
			}
			foreach ($this->rows->sorted($shaped) as $folder) {
				$ids[] = (int)$folder['id'];
			}
		}
		return $ids;
	}

	/**
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call mail_search_messages now
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(string $userId): bool {
		return $this->access->allowed(SearchMessages::definition()->toArray(), $userId);
	}
}
