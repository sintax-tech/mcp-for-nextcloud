<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCA\UserStatus\Exception\InvalidStatusTypeException;
use Psr\Container\ContainerInterface;

/** The status menu's type change: setStatus then removeBackupUserStatus, for the actor only. */
final class StatusEditor {
	/**
	 * @param ContainerInterface $container lazy native service
	 * @param StatusWriteSurface $surface fail-closed write signature
	 */
	public function __construct(private ContainerInterface $container, private StatusWriteSurface $surface) {
	}

	/**
	 * @param string $actor authenticated owner
	 * @param string $type native status type already checked by the handler
	 * @return bool whether a temporary backup row was removed
	 * @throws PolicyUnavailable when the native write is not the pinned one
	 * @throws \InvalidArgumentException when the service rejects the type
	 */
	public function setOwnType(string $actor, string $type): bool {
		if ($actor === '' || !$this->surface->supported()) {
			throw new PolicyUnavailable(Translator::t('The status snapshot is unavailable.'));
		}
		$service = $this->container->get(StatusWriteSurface::SERVICE);
		try {
			$service->setStatus($actor, $type, null, true);
		} catch (InvalidStatusTypeException) {
			throw new \InvalidArgumentException(Translator::t('The status type is not supported.'));
		}
		return $service->removeBackupUserStatus($actor) === true;
	}
}
