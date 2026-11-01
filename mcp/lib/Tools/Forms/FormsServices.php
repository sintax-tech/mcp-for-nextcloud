<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/** Resolves only enabled Forms services, then probes the exact instance that will perform FO1 reads. */
class FormsServices {
	public const APP_ID = 'forms';
	private const SERVICE = 'OCA\\Forms\\Service\\FormsService';
	private const MAPPER = 'OCA\\Forms\\Db\\FormMapper';

	/** Receives official app/user/container services and the captured-identity probe.
	 * @param IAppManager $apps Per-actor enablement and installed version.
	 * @param IUserManager $users Resolves the actor explicitly.
	 * @param ContainerInterface $container Native request/app container, never reset or modified.
	 * @param FormsIdentityProbe $identity Probes before domain reads.
	 */
	public function __construct(private IAppManager $apps, private IUserManager $users, private ContainerInterface $container, private FormsIdentityProbe $identity) {
	}

	/** Opens a fresh admission boundary without recreating the native service.
	 * @param string $uid Authenticated actor.
	 * @return array{service:object,mapper:object,user:IUser,version:string} Proven native read collaborators.
	 * @throws ToolFailure When enablement, session, identity or resolution is unavailable.
	 */
	public function open(string $uid): array {
		return FormsCalls::run(function () use ($uid): array {
			$user = $this->users->get($uid);
			if ($user === null || !AppEnablement::forUser($this->apps, self::APP_ID, $user) || !class_exists(self::SERVICE)) {
				throw new ToolFailure(FormsMessages::unavailable());
			}
			$service = $this->container->get(self::SERVICE);
			$this->identity->assertActor($service, $uid);
			return ['service' => $service, 'mapper' => $this->container->get(self::MAPPER), 'user' => $user,
				'version' => (string)$this->apps->getAppVersion(self::APP_ID)];
		});
	}
}
