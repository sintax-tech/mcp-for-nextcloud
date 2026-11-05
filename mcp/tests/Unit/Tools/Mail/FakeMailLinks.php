<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\MailLinks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * MailLinks over a URL generator that knows only the two Mail web routes, as appinfo/routes.php of the Mail app
 * declares them, under https://cloud.example; any other route is an error, so a test never accepts an invented one.
 */
final class FakeMailLinks {
	/** Base URL of the fake server. */
	public const BASE = 'https://cloud.example/index.php/apps/mail';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The links for a test.
	 *
	 * @param TestCase $test the test that needs them, for its stubs
	 * @return MailLinks the links
	 */
	public static function create(TestCase $test): MailLinks {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		$urls = $stub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $parameters): string {
			return match ($route) {
				'mail.page.mailbox' => self::BASE . '/box/' . $parameters['id'],
				'mail.page.thread' => self::BASE . '/box/' . $parameters['mailboxId'] . '/thread/' . $parameters['id'],
				default => throw new \LogicException('No such route: ' . $route),
			};
		});
		return new MailLinks($urls);
	}
}
