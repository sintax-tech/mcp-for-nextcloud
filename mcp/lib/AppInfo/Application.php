<?php
declare(strict_types=1);

namespace OCA\Mcp\AppInfo;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'mcp';

    /** Tool modules in tools/list order; registered explicitly, no autodiscovery. */
    public const MODULES = [
    ];

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Production dependencies (smalot/pdfparser) are bundled in the package's vendor/.
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        $context->registerService(ToolRegistry::class, static fn (ContainerInterface $c): ToolRegistry => new ToolRegistry(
            array_map(static fn (string $class) => $c->get($class), self::MODULES),
            $c->get(GrantPolicy::class),
            $c->get(IAppManager::class),
            $c->get(IUserManager::class),
            $c->get(LoggerInterface::class),
        ));
    }

    public function boot(IBootContext $context): void {
    }
}
