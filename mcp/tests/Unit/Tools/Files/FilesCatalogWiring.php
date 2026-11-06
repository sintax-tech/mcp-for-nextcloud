<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Modules\Files\FilesModule;
use Psr\Container\ContainerInterface;

/** Autowires catalog work using exactly the legacy test's supplied services. */
final class FilesCatalogWiring implements ContainerInterface {
	private static ?\WeakMap $modules = null;
	private array $cache = [];
	private const TYPES = [
		'rootFolder' => \OCP\Files\IRootFolder::class,
		'extractor' => \OCA\Mcp\Modules\Files\TextExtractor::class,
		'backup' => \OCA\Mcp\Modules\Files\FileBackup::class,
		'userManager' => \OCP\IUserManager::class,
		'db' => \OCP\IDBConnection::class,
		'accessInfo' => \OCA\Mcp\Platform\FileAccess\NodeAccessInfo::class,
		'guard' => \OCA\Mcp\Platform\FileAccess\SharedWriteGuard::class,
		'checkout' => \OCA\Mcp\Modules\Files\Checkout\CheckoutService::class,
		'versions' => \OCA\Mcp\Modules\Files\VersionTools::class,
		'reorganization' => \OCA\Mcp\Modules\Files\Reorganization::class,
		'planner' => \OCA\Mcp\Modules\Files\MovePlanner::class,
		'batches' => \OCA\Mcp\Modules\Files\BatchStore::class,
		'time' => \OCP\AppFramework\Utility\ITimeFactory::class,
		'images' => \OCA\Mcp\Modules\Files\Images\ImageTools::class,
		'ocr' => \OCA\Mcp\Modules\Files\Images\OcrSupport::class,
		'visibilityGuard' => \OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class,
		'appManager' => \OCP\App\IAppManager::class,
		'ftsManager' => \OCP\FullTextSearch\IFullTextSearchManager::class,
		'timezone' => \OCA\Mcp\Platform\Time\UserTimezone::class,
		'shareLister' => \OCA\Mcp\Modules\Files\Sharing\ShareLister::class,
		'shareWriter' => \OCA\Mcp\Modules\Files\Sharing\ShareWriter::class,
		'shareRemover' => \OCA\Mcp\Modules\Files\Sharing\ShareRemover::class,
		'creation' => \OCA\Mcp\Modules\Files\FileCreation::class,
		'locks' => \OCA\Mcp\Platform\FileAccess\LockAwareWrite::class,
		'fileTypes' => \OCA\Mcp\Access\FileTypes\FileTypeGuard::class,
		'editFile' => \OCA\Mcp\Modules\Files\Handler\EditFileHandler::class,
		'replaceFile' => \OCA\Mcp\Modules\Files\Handler\ReplaceFileHandler::class,
		'restoreVersion' => \OCA\Mcp\Modules\Files\Handler\RestoreVersionHandler::class,
		'backupLocation' => \OCA\Mcp\Platform\Backups\BackupLocation::class,
		'backupPlan' => \OCA\Mcp\Modules\Files\BackupPlan::class,
		'deleteFile' => \OCA\Mcp\Modules\Files\Handler\DeleteFileHandler::class,
		'actorRoot' => \OCA\Mcp\Platform\FileAccess\ActorRoot::class,
	];
	private function __construct(private array $services) {
	}

	public static function module(
		\OCP\Files\IRootFolder $rootFolder,
		\OCA\Mcp\Modules\Files\TextExtractor $extractor,
		\OCA\Mcp\Modules\Files\FileBackup $backup,
		\OCP\IUserManager $userManager,
		\OCP\IDBConnection $db,
		\OCA\Mcp\Platform\FileAccess\NodeAccessInfo $accessInfo,
		\OCA\Mcp\Platform\FileAccess\SharedWriteGuard $guard,
		\OCA\Mcp\Modules\Files\Checkout\CheckoutService $checkout,
		\OCA\Mcp\Modules\Files\VersionTools $versions,
		\OCA\Mcp\Modules\Files\Reorganization $reorganization,
		\OCA\Mcp\Modules\Files\MovePlanner $planner,
		\OCA\Mcp\Modules\Files\BatchStore $batches,
		\OCP\AppFramework\Utility\ITimeFactory $time,
		\OCA\Mcp\Modules\Files\Images\ImageTools $images,
		\OCA\Mcp\Modules\Files\Images\OcrSupport $ocr,
		?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
		?\OCP\App\IAppManager $appManager = null,
		?\OCP\FullTextSearch\IFullTextSearchManager $ftsManager = null,
		?\OCA\Mcp\Platform\Time\UserTimezone $timezone = null,
		?\OCA\Mcp\Modules\Files\Sharing\ShareLister $shareLister = null,
		?\OCA\Mcp\Modules\Files\Sharing\ShareWriter $shareWriter = null,
		?\OCA\Mcp\Modules\Files\Sharing\ShareRemover $shareRemover = null,
		?\OCA\Mcp\Modules\Files\FileCreation $creation = null,
		?\OCA\Mcp\Platform\FileAccess\LockAwareWrite $locks = null,
		?\OCA\Mcp\Access\FileTypes\FileTypeGuard $fileTypes = null,
		?\OCA\Mcp\Modules\Files\Handler\EditFileHandler $editFile = null,
		?\OCA\Mcp\Modules\Files\Handler\ReplaceFileHandler $replaceFile = null,
		?\OCA\Mcp\Modules\Files\Handler\RestoreVersionHandler $restoreVersion = null,
		?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation = null,
		?\OCA\Mcp\Modules\Files\BackupPlan $backupPlan = null,
		?\OCA\Mcp\Modules\Files\Handler\DeleteFileHandler $deleteFile = null,
		?\OCA\Mcp\Platform\FileAccess\ActorRoot $actorRoot = null
	): FilesModule {
		$values = get_defined_vars();
		$services = [];
		foreach (self::TYPES as $name => $type) { $services[$type] = $values[$name]; }
		return (new self($services))->buildModule();
	}
	private function buildModule(): FilesModule {
		$module = new FilesModule($this);
		self::$modules ??= new \WeakMap();
		self::$modules[$module] = $this;
		return $module;
	}
	public static function of(FilesModule $module): self { return self::$modules[$module]; }
	public function named(string $name): mixed { return $this->get(self::TYPES[$name]); }
	public static function replacing(FilesModule $module, array $overrides): FilesModule {
		return (new self($overrides + self::of($module)->services))->buildModule();
	}
	public function get(string $id): mixed {
		if (array_key_exists($id, $this->services)) { return $this->services[$id]; }
		if (isset($this->cache[$id])) { return $this->cache[$id]; }
		if (!$this->has($id)) { throw new \LogicException('Unexpected Files test service ' . $id); }
		$r = new \ReflectionClass($id);
		$args = [];
		foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
			$t = $p->getType();
			if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) { $args[] = $this->get($t->getName()); }
			elseif ($p->isDefaultValueAvailable()) { $args[] = $p->getDefaultValue(); }
			else { throw new \LogicException('Unresolved Files argument ' . $id . '::' . $p->getName()); }
		}
		return $this->cache[$id] = $r->newInstanceArgs($args);
	}
	public function has(string $id): bool {
		return array_key_exists($id, $this->services) || (str_starts_with($id, 'OCA\\Mcp\\Modules\\Files\\') && class_exists($id) && (new \ReflectionClass($id))->isInstantiable());
	}
}
