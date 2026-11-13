<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Integration;

use ReflectionMethod;
use Throwable;

/** Lazy signature guard for the native read shared by the NC34/35 distribution pins. */
final class NotificationsSurface {
	/** Native storage owner; never instantiated during discovery. */
	public const HANDLER = 'OCA\\Notifications\\Handler';
	/** @param string $handler native class override for source-shape tests */
	public function __construct(private string $handler = self::HANDLER) {}
	/** @return bool exact read prefix, without calling constructors, notifiers or the database */
	public function supported(): bool {
		try {
			if (!class_exists($this->handler)) return false;
			$get = new ReflectionMethod($this->handler, 'get');
			if (!$get->isPublic() || $get->isStatic() || $get->returnsReference() || (string)$get->getReturnType() !== 'array'
				|| $get->getNumberOfRequiredParameters() !== 1 || count($get->getParameters()) !== 2) return false;
			foreach (['OCP\\Notification\\INotification', 'int'] as $index => $type) {
				$p = $get->getParameters()[$index];
				if ((string)$p->getType() !== $type || $p->isPassedByReference() || $p->isVariadic()) return false;
			}
			return true;
		} catch (Throwable) { return false; }
	}
}
