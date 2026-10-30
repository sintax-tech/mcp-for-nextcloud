<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\Polls\PollsSurface;
use PHPUnit\Framework\TestCase;

/** Exact declared native dependencies, now including the creation service constructor. */
final class NativeCreateTestFactory {
	/**
	 * Builds the old read services and the additional constructor dependencies, without changing their predicates.
	 * @param TestCase $test mock factory
	 * @param object $native initialized native session
	 * @return array<string,object> declared dependency map
	 */
	public static function services(TestCase $test, object $native): array {
		require_once __DIR__.'/NativeCreateDouble.php';
		NativeScene::$services = [PollsSurface::SESSION=>$native,
			'OCP\EventDispatcher\IEventDispatcher'=>$test->getMockBuilder(\OCP\EventDispatcher\IEventDispatcher::class)->getMock(),
			'Psr\Log\LoggerInterface'=>$test->getMockBuilder(\Psr\Log\LoggerInterface::class)->getMock(),
			'OCA\Polls\Db\OptionMapper'=>new \OCA\Polls\Db\OptionMapper(),
			'OCA\Polls\Db\PollMapper'=>new \OCA\Polls\Db\PollMapper(),
			'OCA\Polls\Db\UserMapper'=>new \OCA\Polls\Db\UserMapper(),
			'OCA\Polls\Db\VoteMapper'=>new \OCA\Polls\Db\VoteMapper(),
			'OCA\Polls\Model\Settings\AppSettings'=>new \OCA\Polls\Model\Settings\AppSettings(),
			'OCA\Polls\Service\VoteService'=>new \OCA\Polls\Service\VoteService()];
		NativeScene::$services[PollsSurface::POLLS] = self::polls();
		return NativeScene::$services;
	}
	/**
	 * Calls the actual native9 constructor layout on a fresh prototype, as production does.
	 * @return \OCA\Polls\Service\PollService strict native fixture service
	 */
	public static function polls(): \OCA\Polls\Service\PollService {
		return new \OCA\Polls\Service\PollService(NativeScene::$services['OCA\Polls\Model\Settings\AppSettings'],
			NativeScene::$services['OCP\EventDispatcher\IEventDispatcher'],new \OCA\Polls\Db\Poll(),
			NativeScene::$services['OCA\Polls\Db\PollMapper'],NativeScene::$services['OCA\Polls\Db\UserMapper'],
			NativeScene::session(),NativeScene::$services['OCA\Polls\Db\VoteMapper']);
	}
}
