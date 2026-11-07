<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Attachments;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Deck\Attachments\LinkCardFileHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceContext;
use OCA\Mcp\Modules\Deck\Integration\NativeCardFileLink;

/**
 * Focused schema and lazy composition for the file-link tool; the large Deck module only delegates.
 */
final class CardLinkTool {
	/**
	 * Describe the strict sharing consequence before a client can invoke a mutation.
	 *
	 * @return string Fixed English description, matching the published Deck schema convention.
	 */
	public static function description(): string {
		return 'Links one existing visible file to a Deck card by a native Deck file share, with read-only access for current and future board members. Requires the three grants deck.edit, files.read and files.share, native EDIT/READ/SHARE, a complete recipient preview and confirm:true with its plan_state. The plan names the file, its owner, whether it is a reshare, the current recipient total, a limited name list and the shares you created. Refuses Guests, group-only sharing, a group or team on the board when group sharing is off, mandatory internal expiry, a recipient that is not a fully resolved local user, group or team, folders, archived cards or boards, and any failure to read those facts. Available on Nextcloud 32 through 35. No upload, copy or file deletion. Does not suggest changing a sharing policy or signing in another way. A policy enabled later, a membership change, and the gap between the last check and the native share are outside this check: there is no continuous protection and no automatic revocation.';
	}

	/**
	 * Publish the schema; central WriteGate adds the optional confirm flag.
	 *
	 * @return array<string, mixed> Writing Deck tool definition with no mandatory approval state on preview.
	 */
	public static function definition(): array {
		return ['name' => LinkCardFileHandler::TOOL, 'description' => self::description(),
			'module' => 'deck', 'operation' => 'edit', 'app' => 'deck',
			'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['cardId', 'fileId'],
				'properties' => ['cardId' => ['type' => 'integer', 'minimum' => 1, 'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_CARD_ID],
					'fileId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the existing visible File in your Files scope. Folders are refused.'],
					'confirm_shared' => ['type' => 'boolean', 'description' => 'Set to true only after the user confirms linking a shared file or changing another user\'s board.'],
					PlanState::ARGUMENT => PlanState::property()]]];
	}

	/**
	 * Compose the operation lazily after the module's optional Deck/app authorization gate.
	 *
	 * @param ContainerInterface $container Injected native container, never a global service locator.
	 * @param DeckGatewayInterface $gateway Existing facade and native source authorization.
	 * @param LoggerInterface $logger Class-only error logging.
	 * @param ISession|null $session Native Deck session already used by the facade.
	 * @return LinkCardFileHandler Handler whose factory does not run at tools/list time.
	 */
	public static function handler(ContainerInterface $container, DeckGatewayInterface $gateway,
		LoggerInterface $logger, ?ISession $session): LinkCardFileHandler {
		return new LinkCardFileHandler($gateway, $logger, self::factory($container, $gateway, $logger, $session));
	}
	/**
	 * @param ContainerInterface $container existing lazy container
	 * @param DeckGatewayInterface $gateway registered native boundary
	 * @param LoggerInterface $logger class-only logger
	 * @param ISession|null $session existing caller binding
	 * @return \Closure():array<string,mixed> unchanged operation service composition
	 */
	public static function factory(ContainerInterface $container, DeckGatewayInterface $gateway, LoggerInterface $logger, ?ISession $session): \Closure {
		return static function () use ($container, $gateway, $logger, $session): array {
			$services = new DeckServiceContext($container, $session ?? $container->get(ISession::class));
			$grants = $container->get(GrantPolicy::class);
			$guard = $container->get(FilesAccessGuard::class);
			$shares = $container->get(IManager::class);
			$config = $container->get(IConfig::class);
			$apps = $container->get(IAppManager::class);
			$users = $container->get(IUserManager::class);
			$plans = new PlanState($config);
			$native = new NativeCardFileLink($shares, $services);
			$policy = new \OCA\Mcp\Modules\Deck\Integration\CardLinkPolicy($shares, $config, $apps, $users, $container->get(IUserSession::class), $container);
			$recipients = new \OCA\Mcp\Modules\Deck\Integration\BoardRecipientResolver($gateway, $grants,
				new \OCA\Mcp\Modules\Deck\Integration\DeckRecipientDirectory($services, $users, $container->get(IGroupManager::class), $apps), $plans, $logger);
			$prepare = new \OCA\Mcp\Modules\Deck\Attachments\CardFileLinkPreparation($gateway, $grants, $guard,
				new \OCA\Mcp\Modules\Deck\Attachments\CardFileNodeAccess($guard, $container->get(IRootFolder::class)), $policy, $native, $container->get(NodeAccessInfo::class), $recipients);
			return ['prepare' => $prepare, 'native' => $native, 'plans' => new \OCA\Mcp\Modules\Deck\Attachments\CardLinkPlan($plans),
				'shared' => $container->get(SharedWriteGuard::class)];
		};
	}
}
