<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail\Plans;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Mail\FakeMailMessageApp;
use OCA\Mcp\Tests\Unit\Tools\Mail\MailMessageToolsRegistryTest;
use OCA\Mcp\Tools\Mail\MailModule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../stubs/OCA/Mail/Events/MessageFlaggedEvent.php';
require_once __DIR__ . '/../../../../stubs/OCA/Mail/Events/MessageDeletedEvent.php';

/**
 * One Mail app in memory where every write of the Mail module has a plan to make, and the arguments of each write:
 * alice's account 11 with INBOX (message 7), Archive, Trash, Junk, Drafts and Sent, its settings naming them, and alice
 * allowed to read accounts and messages, so the plans show what identifies the message.
 */
final class MailPlanScene {
	/** Arguments of each write of the module, by tool. */
	public const ARGUMENTS = [
		'mail_set_flags' => ['message_id' => 7, 'seen' => true, 'flagged' => true],
		'mail_move_message' => ['message_id' => 7, 'mailbox_id' => 2],
		'mail_create_draft' => ['account_id' => 11, 'to' => ['bob@example.com'], 'subject' => 'Budget review', 'body' => "Hello Bob,\n\nthe numbers.\n"],
		'mail_send_message' => ['account_id' => 11, 'to' => ['bob@example.com'], 'subject' => 'Budget review', 'body' => "Hello Bob,\n\nthe numbers.\n"],
	];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The module over the scene, with the subject of message 7 as given.
	 *
	 * @param TestCase $test the running test
	 * @param string $subject subject of message 7 in the Mail cache
	 * @return MailModule the module
	 */
	public static function module(TestCase $test, string $subject = 'Budget review'): MailModule {
		$app = (new FakeMailMessageApp())->withAccount(11, 'alice', 'Work', 'alice@example.com');
		$app->accountSettings[11] = ['trash' => 3, 'junk' => 4, 'drafts' => 5, 'sent' => 6, 'retention' => 30];
		foreach ([1 => 'INBOX', 2 => 'Archive', 3 => 'Trash', 4 => 'Junk', 5 => 'Drafts', 6 => 'Sent'] as $id => $name) {
			$app->withFolder(11, ['id' => $id, 'name' => $name]);
		}
		$app->withMessage(1, ['id' => 7, 'subject' => $subject]);
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($test), new InMemoryOAuthStore());
		$policy->setGrant('alice', 'mail', 'messages', true);
		return MailMessageToolsRegistryTest::module($test, $app, '5.12.5', $policy);
	}

	/**
	 * The plan of each write, as the module produces it.
	 *
	 * @param MailModule $module the module
	 * @param array<string, array<string, mixed>> $arguments arguments by tool
	 * @return array<string, array<string, mixed>> plans by tool
	 */
	public static function plans(MailModule $module, array $arguments = self::ARGUMENTS): array {
		return array_map(static fn (string $tool): array => $module->preview($tool, $arguments[$tool], 'alice'), array_combine(array_keys($arguments), array_keys($arguments)));
	}

}
