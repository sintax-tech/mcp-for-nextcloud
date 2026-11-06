<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\{Creation, Editing, Moving, Sending, Reading, Integration, Extraction, Tools, MailModule, MailLinks};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCP\App\IAppManager;
use OCP\ITempManager;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/** Adapts the existing Mail fixtures to the real catalog with their original collaborators. */
final class MailTestModule {
	public static function build(Integration\MailServices $services, UserTimezone $timezones, MailLinks $links, PlanState $states, GrantPolicy $grants, IAppManager $apps, ITempManager $temp, IEventDispatcher $events): MailModule {
		$accounts = new Integration\MailGateway($services);
		$messages = new Integration\MailMessageGateway($services);
		$locator = new \OCA\Mcp\Modules\Mail\Locating\MailMessageLocator($accounts, $messages);
		$reads = new Reading\MailMessageReading($services, $accounts, $timezones, $links);
		$flags = new Editing\FlagMessageContext($services, $accounts, $timezones, $links, $states, $grants, $events);
		$moves = new Moving\MoveMessageContext($services, $accounts, $timezones, $links, $states, $grants, $events);
		$draftAccount = new Creation\MailDraftAccount($accounts, new Creation\MailDraftFolder($messages));
		$draftPlan = new Creation\MailDraftPlan($states, $grants);
		$draftHtml = new Creation\MailDraftHtml(new Integration\MailDraftHtmlEngine());
		$sendAccount = new Sending\MailSendAccount($accounts, $messages);
		$sendPlan = new Sending\MailSendPlan($states, $grants, $apps);
		$tools = [
			Tools\ListAccounts::class => new Tools\ListAccounts(new Reading\ListAccountsHandler($accounts)),
			Tools\ListMailboxes::class => new Tools\ListMailboxes(new Reading\ListMailboxesHandler($accounts, $links)),
			Tools\ListMessages::class => new Tools\ListMessages($reads),
			Tools\SearchMessages::class => new Tools\SearchMessages($reads),
			Tools\ReadMessage::class => new Tools\ReadMessage($reads),
			Tools\ReadThread::class => new Tools\ReadThread($reads),
			Tools\ReadAttachment::class => new Tools\ReadAttachment(new Reading\ReadAttachmentHandler($locator, new Integration\MailAttachmentReader($services), new Extraction\MailAttachmentText(new Extraction\MailAttachmentDocument($temp)))),
			Tools\SetFlags::class => new Tools\SetFlags(new Editing\SetFlagsHandler($flags), new Editing\SetFlagsPreview($flags)),
			Tools\MoveMessage::class => new Tools\MoveMessage(new Moving\MoveMessageHandler($moves), new Moving\MoveMessagePreview($moves)),
			Tools\CreateDraft::class => new Tools\CreateDraft(new Creation\CreateDraftHandler($draftAccount, $draftPlan, new Integration\MailDraftStore($services), $links, $apps, $draftHtml), new Creation\CreateDraftPreview($draftAccount, $draftPlan, $apps, $draftHtml)),
			Tools\SendMessage::class => new Tools\SendMessage(new Sending\SendMessageHandler($sendAccount, $sendPlan, new Integration\MailSendOutbox($services), $services), new Sending\SendMessagePreview($sendAccount, $sendPlan)),
		];
		return new MailModule(new class($tools) implements ContainerInterface {
			public function __construct(private array $tools) {}
			public function get(string $id): mixed { return $this->tools[$id] ?? throw new \LogicException('No Mail test tool: ' . $id); }
			public function has(string $id): bool { return isset($this->tools[$id]); }
		});
	}

	public static function capabilities(IAppManager $apps): CapabilityRegistry {
		return new CapabilityRegistry([new Integration\MailAttachmentProbe($apps)], new NullLogger());
	}
}
