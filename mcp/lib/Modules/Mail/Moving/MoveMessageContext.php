<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Moving;

use OCA\Mcp\Modules\Mail\Presentation\MailMoveMessages;
use OCA\Mcp\Modules\Mail\Integration\MailMoveStore;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Mail\Moving\MoveMessageHandler;
use OCA\Mcp\Modules\Mail\MailLinks;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\EventDispatcher\IEventDispatcher;

/** Builds the Mail move collaborators anew for each actor call. */
final class MoveMessageContext {
	/**
	 * Receives the Mail classes, the account boundary and what the plan and the move need.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailGateway $accounts read-only access to the accounts and folders of the Mail app
	 * @param UserTimezone $timezones time zone of each user, for the date in the plan
	 * @param MailLinks $links direct links into the Mail app
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the plan
	 * @param IEventDispatcher $events event dispatcher, for the Mail app's event
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private UserTimezone $timezones,
		private MailLinks $links,
		private PlanState $states,
		private GrantPolicy $grants,
		private IEventDispatcher $events,
	) {
	}

	/**
	 * @param string $userId authenticated actor whose timezone is resolved now
	 * @return array{MailMessageLocator, MailMoveDestination, MailMovePlan, MailMoveStore, MailLinks, IEventDispatcher, MailServices} collaborators in the handler constructor order
	 */
	public function forUser(string $userId): array {
		$messages = new MailMessageGateway($this->services);
		$locator = new MailMessageLocator($this->accounts, $messages);
		$plans = new MailMovePlan($this->states, $this->grants, new MailMessageRows($this->timezones->forUser($userId), $this->links));
		return [$locator, new MailMoveDestination($messages), $plans, new MailMoveStore($this->services), $this->links, $this->events, $this->services];
	}
}
