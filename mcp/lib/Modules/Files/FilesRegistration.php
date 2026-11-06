<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Mcp\Resources\FileTextResource;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/** Registers the lazy Files resource text port. */
final class FilesRegistration implements ModuleRegistration {
	/** @param IRegistrationContext $context app registration context @return void */
	public function register(IRegistrationContext $context): void {
		$context->registerService(FileTextResource::class, static fn (ContainerInterface $c): FileTextResource => $c->get(ExtractedFileText::class));
	}
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> no installation probes */
	public function probes(): array { return []; }
}
