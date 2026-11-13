<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Status\Presentation\StatusMessageRequest;
use OCA\UserStatus\Exception\InvalidClearAtException;
use OCA\UserStatus\Exception\InvalidMessageIdException;
use OCA\UserStatus\Exception\InvalidStatusIconException;
use OCA\UserStatus\Exception\InvalidStatusTypeException;
use OCA\UserStatus\Exception\StatusMessageTooLongException;
use Psr\Container\ContainerInterface;

/** The status menu's own type and message changes, for the actor only. */
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

	/**
	 * @param string $actor authenticated owner
	 * @param StatusMessageRequest $request one native message change already checked by the handler
	 * @return bool whether a temporary backup row was removed
	 * @throws PolicyUnavailable when the native write is not the pinned one
	 * @throws \InvalidArgumentException when the service rejects the message or its expiration
	 */
	public function setOwnMessage(string $actor, StatusMessageRequest $request): bool {
		if ($actor === '' || !$this->surface->messageSupported()) {
			throw new PolicyUnavailable(Translator::t('The status snapshot is unavailable.'));
		}
		$service = $this->container->get(StatusWriteSurface::SERVICE);
		try {
			if ($request->mode === StatusMessageRequest::MODE_CLEAR) {
				$service->clearMessage($actor);
				return false;
			}
			if ($request->mode === StatusMessageRequest::MODE_PREDEFINED) {
				$service->setPredefinedMessage($actor, $request->messageId, $request->clearAt);
			} else {
				$service->setCustomMessage($actor, null, $request->message, $request->clearAt);
			}
		} catch (InvalidClearAtException) {
			throw new \InvalidArgumentException(Translator::t('The status message expiration must be a unix time in the future.'));
		} catch (InvalidMessageIdException) {
			throw new \InvalidArgumentException(Translator::t('The status message is not supported.'));
		} catch (StatusMessageTooLongException) {
			throw new \InvalidArgumentException(Translator::t('The status message can have at most %d characters.', [StatusMessageRequest::MAX]));
		} catch (InvalidStatusIconException) {
			throw new \InvalidArgumentException(Translator::t('The status icon is not supported.'));
		}
		return $service->removeBackupUserStatus($actor) === true;
	}
}
