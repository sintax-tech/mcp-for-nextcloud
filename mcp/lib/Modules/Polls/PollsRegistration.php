<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Polls\Contracts\PollWriteNative;
use OCA\Mcp\Modules\Polls\Contracts\PollWriteReader;
use OCA\Mcp\Modules\Polls\Integration\PollsNativeAdapter;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/** Binds the native mutation port and registers the installation-only lazy probe. */
final class PollsRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
		$context->registerService(PollWriteReader::class, static fn (ContainerInterface $c): PollWriteReader => $c->get(PollsNativeAdapter::class));
		$context->registerService(PollWriteNative::class, static fn (ContainerInterface $c): PollWriteNative => $c->get(PollsNativeAdapter::class));
	}
	/** {@inheritDoc} */
	public function probes(): array { return [PollsMutationProbe::class]; }
}
