<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Resources\NoteResource;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Notes\Locating\NotesFolderResolver;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Reading\NoteResourceReader;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * The DI of the Notes module. Its tools and work classes are built by the container from their constructors; only
 * the reader of `nc://notes` resources needs a binding, because Mcp declares it and its repository is built
 * without the visibility guard. The module has no capability probe.
 */
final class NotesRegistration implements ModuleRegistration {
	/**
	 * {@inheritDoc}
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(NoteResource::class, static fn (ContainerInterface $c): NoteResource => new NoteResourceReader(
			new NotesRepository($c->get(IRootFolder::class), $c->get(IConfig::class), null, $c->get(NotesFolderResolver::class)),
			$c->get(VisibilityGuard::class),
		));
	}

	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> none: every Notes tool exists wherever the app does
	 */
	public function probes(): array {
		return [];
	}
}
