<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Create;

use OCA\Mcp\Tools\Polls\{PollsSurface,PollsRefusal};

/** Gates exactly the public creation mechanisms; shapes alone never prove future semantic equivalence. */
final class CreateSurface {
	/** Native settings reader. */
	public const SETTINGS = 'OCA\Polls\Model\Settings\AppSettings';
	/** Native value object, always new per use. */
	public const SIMPLE = 'OCA\Polls\Model\SimpleOption';
	/** Explicit native PollService constructor order in all four pinned releases. */
	public const DEPS = [self::SETTINGS,'OCP\EventDispatcher\IEventDispatcher',PollsSurface::POLL,
		'OCA\Polls\Db\PollMapper','OCA\Polls\Db\UserMapper',PollsSurface::SESSION,'OCA\Polls\Db\VoteMapper'];
	/**
	 * Checks signatures and chooses the real 8/9 public argument layout.
	 * @return bool whether add has the 9.x timezone argument
	 * @throws PollsRefusal for an unknown mechanism
	 */
	public function check(): bool {
		$s = new PollsSurface();
		$s->assertMethod(PollsSurface::POLLS, '__construct', self::DEPS, null);
		$s->assertMethod(self::SETTINGS, 'getPollCreationAllowed', [], 'bool');
		$s->assertMethod(self::SIMPLE, 'fromArray', ['array'], ['self',self::SIMPLE], true);
		$s->assertMethod(PollsSurface::OPTIONS, 'add', ['int',self::SIMPLE,'bool'], PollsSurface::OPTION);
		$s->assertMethod(PollsSurface::OPTIONS, 'get', ['int'], PollsSurface::OPTION);
		$s->assertMethod(PollsSurface::POLL, 'getDescription', [], 'string');
		$nine = (new \ReflectionMethod(PollsSurface::POLLS, 'add'))->getNumberOfParameters() === 4;
		$s->assertMethod(PollsSurface::POLLS, 'add', $nine ? ['string','string','?string','string'] : ['string','string','string'], PollsSurface::POLL);
		$s->assertMethod(PollsSurface::OPTION, 'setFromSimpleOption', $nine ? [self::SIMPLE,'string'] : [self::SIMPLE], $nine ? ['self',PollsSurface::OPTION] : 'void');
		if ($nine) {
			$s->assertMethod(PollsSurface::OPTION, 'setPoll', ['int'], 'void');
			$s->assertMethod(PollsSurface::POLL, 'getTimezoneName', [], '?string');
		}
		foreach (['PERMISSION_POLL_EDIT','PERMISSION_OPTION_ADD'] as $key) {
			if (!defined(PollsSurface::POLL.'::'.$key) || !is_string(constant(PollsSurface::POLL.'::'.$key))) { throw PollsRefusal::unavailable(); }
		}
		return $nine;
	}
}
