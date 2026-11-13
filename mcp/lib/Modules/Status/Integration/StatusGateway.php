<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerInterface;

/** One select of the actor's own row. A missing row is absence, not an insert. */
final class StatusGateway {
	/**
	 * @param ContainerInterface $container lazy native mapper
	 * @param StatusSurface $surface fail-closed select signature
	 */
	public function __construct(private ContainerInterface $container, private StatusSurface $surface) {
	}

	/**
	 * @param string $actor authenticated owner
	 * @return StatusRow|null the stored row, or null when that user has none
	 * @throws PolicyUnavailable when the surface or the row cannot be read safely
	 */
	public function find(string $actor): ?StatusRow {
		if ($actor === '' || !$this->surface->supported()) {
			throw new PolicyUnavailable(Translator::t('The status snapshot is unavailable.'));
		}
		$mapper = $this->container->get(StatusSurface::MAPPER);
		try {
			$entity = $mapper->findByUserId($actor, false);
		} catch (DoesNotExistException) {
			return null;
		} catch (MultipleObjectsReturnedException) {
			throw new PolicyUnavailable(Translator::t('The status snapshot is unavailable.'));
		}
		return StatusRow::from($entity, $actor);
	}
}
