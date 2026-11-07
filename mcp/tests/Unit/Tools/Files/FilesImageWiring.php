<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Modules\Files\Images\ImageTools;
use OCA\Mcp\Modules\Files\Images\ImageView;
use OCA\Mcp\Modules\Files\Images\ImageBatch;
use OCA\Mcp\Modules\Files\Images\ImageSearchHandler;
use OCA\Mcp\Modules\Files\Images\ImageMetadata;
use OCA\Mcp\Modules\Files\Images\ImagePreview;
use OCA\Mcp\Modules\Files\Images\ImageSelection;
use OCA\Mcp\Modules\Files\Images\ImageFileAccess;
use OCA\Mcp\Modules\Files\Images\ImageTagSearch;
use OCA\Mcp\Modules\Files\Images\ImageQuerySearch;
use OCP\IPreview;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Composes the real image work classes with the same doubles as the existing Files fixtures. */
final class FilesImageWiring {
	/**
	 * @param IPreview $preview Existing preview double.
	 * @param NodeAccessInfo $accessInfo Existing access metadata collaborator.
	 * @param IConfig $config Existing configuration double.
	 * @param ISystemTagManager $tagManager Existing tags double.
	 * @param ISystemTagObjectMapper $tagMapper Existing tag mapping double.
	 * @param IUserManager $userManager Existing user double.
	 * @param LoggerInterface $logger Existing logger double.
	 * @param ContainerInterface $container Existing optional metadata container.
	 * @param IDBConnection $db Existing database double.
	 * @param FilesAccessGuard|null $visibilityGuard Existing Files guard, including null fixtures.
	 * @return ImageTools Real image composition.
	 */
	public static function build(IPreview $preview, NodeAccessInfo $accessInfo, IConfig $config, ISystemTagManager $tagManager, ISystemTagObjectMapper $tagMapper, IUserManager $userManager, LoggerInterface $logger, ContainerInterface $container, IDBConnection $db, ?FilesAccessGuard $visibilityGuard = null): ImageTools {
		$metadata = new ImageMetadata($accessInfo, $container, $tagManager, $tagMapper, $userManager);
		$bytes = new ImagePreview($preview, $config, $logger);
		$view = new ImageView(new ImageFileAccess($visibilityGuard), $bytes, $metadata);
		$batch = new ImageBatch(new ImageSelection($userManager, $visibilityGuard), $bytes, $metadata);
		$search = new ImageSearchHandler(new ImageTagSearch($tagManager, $tagMapper, $userManager, $logger, $visibilityGuard), new ImageQuerySearch($db, $userManager, $visibilityGuard), $logger, $visibilityGuard);
		return new ImageTools($view, $batch, $search, $metadata);
	}
}
