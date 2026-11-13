<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Mcp\Resources\FileTextResource;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Platform\Dav\MetadataServer;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/** Registers the lazy Files resource text port and the embedded DAV server for comments and system tags. */
final class FilesRegistration implements ModuleRegistration {
	/** @param IRegistrationContext $context app registration context @return void */
	public function register(IRegistrationContext $context): void {
		$context->registerService(\OCA\Mcp\Platform\FileAccess\NewFileDestination::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\FileAccess\NewFileDestination => $c->get(Creation\ProtectedFileDestination::class));
		$context->registerService(FileTextResource::class, static fn (ContainerInterface $c): FileTextResource => $c->get(Extraction\ExtractedFileText::class));
		$context->registerService(MetadataServer::class, static fn (): MetadataServer => new Integration\EmbeddedMetadataDavServer());
	}
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> no installation probes */
	public function probes(): array { return []; }
}
