<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Modules\Polls\Integration\PollsContentSurface;
use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\Presentation\PollsAnonymousIdentity;
use OCA\Mcp\Modules\Polls\Presentation\PollsLinkShapes;
use OCA\Mcp\Modules\Polls\Presentation\PollsResultShapes;
use OCA\Mcp\Modules\Polls\Presentation\PollsVoteShapes;
use OCA\Mcp\Modules\Polls\Reading\ListLinksHandler;
use OCA\Mcp\Modules\Polls\Reading\ListVotesHandler;
use OCA\Mcp\Modules\Polls\Reading\PollsContentPage;
use OCA\Mcp\Modules\Polls\Reading\PollsContentPolicy;
use OCA\Mcp\Modules\Polls\Reading\PollsContext;
use OCA\Mcp\Modules\Polls\Reading\PollsCursor;
use OCA\Mcp\Modules\Polls\Reading\ReadResultsHandler;
use PHPUnit\Framework\TestCase;

/** Adds the real protected-read handlers without rewriting the existing PO-1 rig behavior. */
final class PollsContentTestFactory {
	/**
	 * Builds handlers with the real context, policy, projection and MAC, on independent strict native doubles.
	 * @param TestCase $test mock factory
	 * @param PollsContext $context actual context
	 * @param PollsServices $services actual resolver
	 * @param PollsSessionGuard $guard actual guard
	 * @param PollsCursor $cursor actual cursor
	 * @return array{ListVotesHandler,ReadResultsHandler,ListLinksHandler} handlers
	 */
	public static function handlers(TestCase $test, PollsContext $context, PollsServices $services, PollsSessionGuard $guard, PollsCursor $cursor): array {
		require_once __DIR__ . '/NativeProtectedDouble.php';
		NativeProtectedScene::reset();
		NativeScene::$services[PollsContentSurface::SHARES] = new \OCA\Polls\Service\ShareService();
		$config = $test->getMockBuilder(\OCP\IConfig::class)->getMock();
		$config->method('getSystemValueString')->willReturnCallback(static fn (): string => NativeProtectedScene::$secret);
		$reader = new PollsContentPage($services, $guard, new PollsContentSurface(), new PollsContentPolicy($services, $guard),
			new PollsVoteShapes(new PollsAnonymousIdentity($config)), new PollsResultShapes(), new PollsLinkShapes(), $cursor);
		return [new ListVotesHandler($context, $reader), new ReadResultsHandler($context, $reader), new ListLinksHandler($context, $reader)];
	}
}
