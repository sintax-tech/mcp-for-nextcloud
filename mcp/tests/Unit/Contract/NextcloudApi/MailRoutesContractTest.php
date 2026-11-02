<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Modules\Mail\MailLinks;
use PHPUnit\Framework\TestCase;

/**
 * The Mail links point to routes every supported Mail release declares. mail-routes.json (generate-mail-routes.php)
 * records, from the official archives pinned by SHA256 (the releases of mail-sources.json, the attachment minimum and
 * the release of the integration cell), the web routes MailLinks uses and the API the Mail front end loads a linked
 * conversation from; the route names and parameters MailLinks passes must match them in every release.
 */
final class MailRoutesContractTest extends TestCase {
	/** Directory of the fixtures. */
	private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';

	/** The URL each route must have, with the parameters MailLinks passes. */
	private const EXPECTED = [
		'page#mailbox' => '/box/{id}',
		'page#thread' => '/box/{mailboxId}/thread/{id}',
		'messages#getThread' => '/api/messages/{id}/thread',
	];

	/**
	 * The checked releases, by version.
	 *
	 * @return array<string, array{url:string, sha256:string, routes?:array<string, array{url:string, verb:string}|null>}>
	 */
	private static function releases(): array {
		return json_decode((string)file_get_contents(self::FIXTURES . '/mail-routes.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The releases are the ones the other Mail contracts pin, with the same checksums, plus the integration cell's.
	 *
	 * @return void
	 */
	public function testTheReleasesAreTheOnesTheOtherContractsPin(): void {
		$releases = self::releases();
		$pins = json_decode((string)file_get_contents(self::FIXTURES . '/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($pins as $major => $pin) {
			$this->assertSame($pin['mail']['sha256'], $releases[$pin['mail']['version']]['sha256'] ?? null, "NC$major");
		}
		$peek = json_decode((string)file_get_contents(self::FIXTURES . '/mail-attachment-peek.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($peek['5.10.11']['sha256'], $releases['5.10.11']['sha256'] ?? null);
		$cell = (string)file_get_contents(__DIR__ . '/../../../Integration/ci/mail-cell.sh');
		$this->assertMatchesRegularExpression('/^MAIL_TAG=v5\.12\.5$/m', $cell);
		$this->assertStringContainsString('MAIL_SHA=' . ($releases['5.12.5']['sha256'] ?? 'missing'), $cell);
		foreach ($releases as $version => $release) {
			$this->assertStringEndsWith('/v' . $version . '/mail-v' . $version . '.tar.gz', $release['url']);
		}
	}

	/**
	 * Every release declares the routes with the URLs and verb the links rely on.
	 *
	 * @return void
	 */
	public function testEveryReleaseDeclaresTheLinkedRoutes(): void {
		foreach (self::releases() as $version => $release) {
			foreach (self::EXPECTED as $name => $url) {
				$this->assertSame(['url' => $url, 'verb' => 'GET'], $release['routes'][$name] ?? null, "Mail $version $name: run generate-mail-routes.php");
			}
		}
	}

	/**
	 * MailLinks names those very routes, as Nextcloud names app routes (app.controller.method), with the parameters
	 * of their URLs.
	 *
	 * @return void
	 */
	public function testTheLinksNameTheseRoutes(): void {
		$this->assertSame('mail.page.mailbox', MailLinks::MAILBOX_ROUTE);
		$this->assertSame('mail.page.thread', MailLinks::THREAD_ROUTE);
		$source = (string)file_get_contents((new \ReflectionClass(\OCA\Mcp\Modules\Mail\MailLinks::class))->getFileName());
		$this->assertStringContainsString("linkToRouteAbsolute(self::MAILBOX_ROUTE, ['id' => ", $source);
		$this->assertStringContainsString("linkToRouteAbsolute(self::THREAD_ROUTE, ['mailboxId' => ", $source);
		$this->assertStringContainsString("'id' => (int)\$message->getId()]", $source);
	}
}
