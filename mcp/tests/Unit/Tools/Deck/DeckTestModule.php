<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Modules\Deck\{DeckModule, DeckExecutionContext, DeckToolCatalog};
use OCA\Mcp\Modules\Deck\Integration\{DeckGatewayInterface, DeckServiceGateway, DeckCapabilities};
use OCA\Mcp\Modules\Deck\Presentation\DeckGuideNotes;
use OCA\Mcp\Platform\Time\UserTimezone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\{IURLGenerator,IUserManager,IConfig,ISession};

/** Builds the actual catalog from the collaborators supplied to the old module constructor. */
final class DeckTestModule {
	private static ?\WeakMap $contexts = null;
	private static ?\WeakMap $capabilities = null;
	public static function build(ContainerInterface $native, IAppManager $apps, LoggerInterface $logger,
		IURLGenerator $urls, ITimeFactory $time, ?IUserManager $users = null, ?IConfig $config = null,
		?ISession $session = null, ?DeckCapabilities $capabilities = null): DeckModule {
		$capabilities ??= new DeckCapabilities($apps);
		$gateway = null;
		$container = new class($native, $time, $config, $session, $gateway) implements ContainerInterface {
			public array $tools = [];
			private ?DeckGatewayInterface $gateway = null;
			public function __construct(private ContainerInterface $native, private ITimeFactory $time, private ?IConfig $config, private ?ISession $session, mixed $unused) {}
			public function get(string $id): mixed {
				if (isset($this->tools[$id])) { return $this->tools[$id]; }
				if ($id === DeckGatewayInterface::class) {
					return $this->gateway ??= new DeckServiceGateway($this->native, $this->time, $this->config === null ? null : new UserTimezone($this->config), $this->session);
				}
				return $this->native->get($id);
			}
			public function has(string $id): bool { return isset($this->tools[$id]) || $id === DeckGatewayInterface::class || $this->native->has($id); }
		};
		$context = new DeckExecutionContext($container, $apps, $logger, $urls, $time, $users, $config, $session, $capabilities);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ListBoards::class] = new \OCA\Mcp\Modules\Deck\Tools\ListBoards($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ListLabels::class] = new \OCA\Mcp\Modules\Deck\Tools\ListLabels($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ListCardFiles::class] = new \OCA\Mcp\Modules\Deck\Tools\ListCardFiles($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\UnlinkCardFile::class] = new \OCA\Mcp\Modules\Deck\Tools\UnlinkCardFile($context, new \OCA\Mcp\Modules\Deck\Deletion\UnlinkCardFilePreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\LinkCardFile::class] = new \OCA\Mcp\Modules\Deck\Tools\LinkCardFile($context, new \OCA\Mcp\Modules\Deck\Attachments\LinkCardFilePreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\EditCardLabels::class] = new \OCA\Mcp\Modules\Deck\Tools\EditCardLabels($context, new \OCA\Mcp\Modules\Deck\Editing\EditCardLabelsPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ListStacks::class] = new \OCA\Mcp\Modules\Deck\Tools\ListStacks($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ListCards::class] = new \OCA\Mcp\Modules\Deck\Tools\ListCards($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ReadCard::class] = new \OCA\Mcp\Modules\Deck\Tools\ReadCard($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\CreateCard::class] = new \OCA\Mcp\Modules\Deck\Tools\CreateCard($context, new \OCA\Mcp\Modules\Deck\Creation\CreateCardPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\EditCard::class] = new \OCA\Mcp\Modules\Deck\Tools\EditCard($context, new \OCA\Mcp\Modules\Deck\Editing\EditCardPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\MoveCard::class] = new \OCA\Mcp\Modules\Deck\Tools\MoveCard($context, new \OCA\Mcp\Modules\Deck\Moving\MoveCardPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\DeleteCard::class] = new \OCA\Mcp\Modules\Deck\Tools\DeleteCard($context, new \OCA\Mcp\Modules\Deck\Deletion\DeleteCardPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\FollowupCards::class] = new \OCA\Mcp\Modules\Deck\Tools\FollowupCards($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\CreateBoard::class] = new \OCA\Mcp\Modules\Deck\Tools\CreateBoard($context, new \OCA\Mcp\Modules\Deck\Structure\CreateBoardPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\CreateStack::class] = new \OCA\Mcp\Modules\Deck\Tools\CreateStack($context, new \OCA\Mcp\Modules\Deck\Structure\CreateStackPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\DeleteStack::class] = new \OCA\Mcp\Modules\Deck\Tools\DeleteStack($context, new \OCA\Mcp\Modules\Deck\Structure\DeleteStackPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\DeleteBoard::class] = new \OCA\Mcp\Modules\Deck\Tools\DeleteBoard($context, new \OCA\Mcp\Modules\Deck\Structure\DeleteBoardPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\ReadCardDependencies::class] = new \OCA\Mcp\Modules\Deck\Tools\ReadCardDependencies($context);
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\AddCardDependency::class] = new \OCA\Mcp\Modules\Deck\Tools\AddCardDependency($context, new \OCA\Mcp\Modules\Deck\Dependencies\AddCardDependencyPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\RemoveCardDependency::class] = new \OCA\Mcp\Modules\Deck\Tools\RemoveCardDependency($context, new \OCA\Mcp\Modules\Deck\Dependencies\RemoveCardDependencyPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\SetCardDone::class] = new \OCA\Mcp\Modules\Deck\Tools\SetCardDone($context, new \OCA\Mcp\Modules\Deck\Editing\SetCardDonePreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\SetCardArchived::class] = new \OCA\Mcp\Modules\Deck\Tools\SetCardArchived($context, new \OCA\Mcp\Modules\Deck\Editing\SetCardArchivedPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\CreateLabel::class] = new \OCA\Mcp\Modules\Deck\Tools\CreateLabel($context, new \OCA\Mcp\Modules\Deck\Labels\CreateLabelPreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\UpdateLabel::class] = new \OCA\Mcp\Modules\Deck\Tools\UpdateLabel($context, new \OCA\Mcp\Modules\Deck\Labels\LabelChangePreview($context));
		$container->tools[\OCA\Mcp\Modules\Deck\Tools\DeleteLabel::class] = new \OCA\Mcp\Modules\Deck\Tools\DeleteLabel($context, new \OCA\Mcp\Modules\Deck\Labels\LabelChangePreview($context));
		$module = new DeckModule($container, new DeckGuideNotes($capabilities));
		self::$contexts ??= new \WeakMap();
		self::$contexts[$module] = $context;
		self::$capabilities ??= new \WeakMap();
		self::$capabilities[$module] = $capabilities;
		return $module;
	}
	public static function capability(DeckModule $module): DeckCapabilities { return self::$capabilities[$module]; }
	public static function context(DeckModule $module): DeckExecutionContext { return self::$contexts[$module]; }
	public static function setGateway(DeckModule $module, DeckGatewayInterface $gateway): void {
		(new \ReflectionProperty(DeckExecutionContext::class, 'gateway'))->setValue(self::context($module), $gateway);
	}
	/** Reproduces the registry capability step for old tests that inspected definitions directly. */
	public static function definitions(DeckModule $module): array {
		$probe = self::$capabilities[$module];
		$definitions = [];
		foreach ($module->definitions() as $definition) {
			if (($definition['requires'] ?? []) !== [] && !$probe->supports('deck.dependencies')) { continue; }
			unset($definition['requires']);
			$definitions[] = $definition;
		}
		return $definitions;
	}
}
