<?php
declare(strict_types=1);

namespace OCA\Mcp\AppInfo;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Calendar\CalendarModule;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\DavCalendarStore;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** App bootstrap: loads the bundled production vendor/ and wires the tool registry with its explicit module list. */
class Application extends App implements IBootstrap {
    /** App id, equal to the app directory name. */
    public const APP_ID = 'mcp';

    /** Tool modules in tools/list order; registered explicitly, no autodiscovery. */
    public const MODULES = [
        FilesModule::class,
        NotesModule::class,
        CalendarModule::class,
        DeckToolModule::class,
    ];

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    /** @param IRegistrationContext $context registration context of the app */
    public function register(IRegistrationContext $context): void {
        // Production dependencies (smalot/pdfparser) are bundled in the package's vendor/.
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        // DavCalendarStore resolves CalDavBackend lazily (the app container falls back to the server one) on first use.
        $context->registerService(CalendarStore::class, static fn (ContainerInterface $c): CalendarStore => new DavCalendarStore($c));
        $context->registerService(ToolRegistry::class, static fn (ContainerInterface $c): ToolRegistry => new ToolRegistry(
            array_map(static fn (string $class) => $c->get($class), self::MODULES),
            $c->get(GrantPolicy::class),
            $c->get(IAppManager::class),
            $c->get(IUserManager::class),
            $c->get(LoggerInterface::class),
        ));
    }

    /** @param IBootContext $context unused; the app needs no boot-time work */
    public function boot(IBootContext $context): void {
    }
}
