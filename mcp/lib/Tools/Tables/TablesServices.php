<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Resolves the services of the Nextcloud Tables app by class name, only after the app is confirmed enabled for the user
 * in a version MCP supports ({@see AppEnablement::MINIMUM_VERSIONS}), so nothing here references a Tables class the
 * installation may not have. Every Tables call made through them passes the authenticated user explicitly: the services
 * fall back to the user the container injected from the session otherwise.
 */
class TablesServices {
	/** App id of Nextcloud Tables. */
	public const APP_ID = 'tables';

	/** OCA\Tables\Service\TableService: the tables of a user. */
	private const TABLE_SERVICE_CLASS = 'OCA\\Tables\\Service\\TableService';

	/** OCA\Tables\Service\ViewService: the views of a table, and the views shared with a user. */
	private const VIEW_SERVICE_CLASS = 'OCA\\Tables\\Service\\ViewService';

	/** OCA\Tables\Service\ColumnService: the columns of a table or view. */
	private const COLUMN_SERVICE_CLASS = 'OCA\\Tables\\Service\\ColumnService';

	/** OCA\Tables\Service\PermissionsService: who may open what. */
	private const PERMISSIONS_SERVICE_CLASS = 'OCA\\Tables\\Service\\PermissionsService';

	/** OCA\Tables\Service\ContextService: the applications (contexts) a user belongs to. */
	private const CONTEXT_SERVICE_CLASS = 'OCA\\Tables\\Service\\ContextService';

	/** OCA\Tables\Db\Row2Mapper: the engine RowService itself reads rows with. */
	private const ROW_MAPPER_CLASS = 'OCA\\Tables\\Db\\Row2Mapper';

	/** OCA\Tables\Db\ColumnMapper: column metadata, read only to check saved view rules. */
	private const COLUMN_MAPPER_CLASS = 'OCA\\Tables\\Db\\ColumnMapper';

	/** OCA\Tables\Service\RowService: creates and updates rows, as the Tables controllers do. */
	private const ROW_SERVICE_CLASS = 'OCA\\Tables\\Service\\RowService';

	/** OCA\Tables\Db\ViewMapper: a view, as RowService::create() reads it under the create permission. */
	private const VIEW_MAPPER_CLASS = 'OCA\\Tables\\Db\\ViewMapper';

	/** OCA\Tables\Db\TableMapper: a table, as RowService::create() reads it under the create permission. */
	private const TABLE_MAPPER_CLASS = 'OCA\\Tables\\Db\\TableMapper';

	/** OCA\Tables\Helper\ColumnsHelper: resolves the values a view's saved rules add to a new row. */
	private const COLUMNS_HELPER_CLASS = 'OCA\\Tables\\Helper\\ColumnsHelper';

	/** OCA\Tables\AppInfo\Application: holds the node type constants. */
	private const APPLICATION_CLASS = 'OCA\\Tables\\AppInfo\\Application';

	/**
	 * Receives the app manager, the container the Tables services are resolved from and the user manager.
	 *
	 * @param IAppManager $appManager app enablement, checked per user on every call
	 * @param ContainerInterface $container resolves the Tables services lazily by class name
	 * @param IUserManager $userManager resolves the UID to the IUser that IAppManager::isEnabledForUser() expects
	 * @return void
	 */
	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Whether the Tables app is enabled for the user, in a version MCP supports, on this very call.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool true when the user may use the Tables app now
	 */
	public function isEnabledFor(string $userId): bool {
		$user = $this->userManager->get($userId);
		return $user !== null && AppEnablement::forUser($this->appManager, self::APP_ID, $user);
	}

	/**
	 * OCA\Tables\Service\TableService, used only through findAll().
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Service\TableService
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function tableService(string $userId): object {
		return $this->resolve(self::TABLE_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Service\ViewService, used only through findAll(), find() and findSharedViewsWithMe().
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Service\ViewService
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function viewService(string $userId): object {
		return $this->resolve(self::VIEW_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Service\ColumnService, used only through findAllByTable() and findAllByView().
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Service\ColumnService
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function columnService(string $userId): object {
		return $this->resolve(self::COLUMN_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Service\PermissionsService, used only through its read checks.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Service\PermissionsService
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function permissionsService(string $userId): object {
		return $this->resolve(self::PERMISSIONS_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Service\ContextService, used only through findAll().
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Service\ContextService
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function contextService(string $userId): object {
		return $this->resolve(self::CONTEXT_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Db\Row2Mapper, used only through findAll(), after the native row permission and the session guard.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Db\Row2Mapper
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function rowMapper(string $userId): object {
		return $this->resolve(self::ROW_MAPPER_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Db\ColumnMapper, used only through find(), to check which table a saved rule's column belongs to.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Db\ColumnMapper
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function columnMapper(string $userId): object {
		return $this->resolve(self::COLUMN_MAPPER_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Service\RowService, used only through create() and updateSet(), after every check of a row write and the
	 * session guard.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Service\RowService
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function rowService(string $userId): object {
		return $this->resolve(self::ROW_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Db\ViewMapper, used only through find(), after or for the native write permission.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Db\ViewMapper
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function viewMapper(string $userId): object {
		return $this->resolve(self::VIEW_MAPPER_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Db\TableMapper, used only through find(), after or for the native write permission.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Db\TableMapper
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function tableMapper(string $userId): object {
		return $this->resolve(self::TABLE_MAPPER_CLASS, $userId);
	}

	/**
	 * OCA\Tables\Helper\ColumnsHelper, used only through resolveSearchValue(), to check the values a view adds.
	 *
	 * @param string $userId authenticated user UID
	 * @return object OCA\Tables\Helper\ColumnsHelper
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function columnsHelper(string $userId): object {
		return $this->resolve(self::COLUMNS_HELPER_CLASS, $userId);
	}

	/**
	 * The installed Tables version.
	 *
	 * @return string the version, as the app manager reports it
	 */
	public function version(): string {
		return (string)$this->appManager->getAppVersion(self::APP_ID);
	}

	/**
	 * The node type of a view in a context, OCA\Tables\AppInfo\Application::NODE_TYPE_VIEW.
	 *
	 * @param string $userId authenticated user UID
	 * @return int the node type
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function viewNodeType(string $userId): int {
		$constant = self::APPLICATION_CLASS . '::NODE_TYPE_VIEW';
		if (!$this->isEnabledFor($userId) || !defined($constant)) {
			throw new TablesUnavailableException();
		}
		return (int)constant($constant);
	}

	/**
	 * Resolves a Tables class by name only after the app is confirmed enabled and the class exists.
	 *
	 * @param string $class fully qualified Tables class name
	 * @param string $userId authenticated user UID
	 * @return object the service
	 * @throws TablesUnavailableException when Tables is not available, the class is absent or the container gives none
	 */
	protected function resolve(string $class, string $userId): object {
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new TablesUnavailableException();
		}
		$service = $this->container->get($class);
		if (!is_object($service)) {
			throw new TablesUnavailableException();
		}
		return $service;
	}
}
