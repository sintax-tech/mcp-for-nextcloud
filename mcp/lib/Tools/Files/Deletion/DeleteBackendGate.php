<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\Encryption\IManager as EncryptionManager;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use Psr\Container\ContainerInterface;

/** Pre-effect eligibility only; native transfer/metadata failures remain possible after admission. */
class DeleteBackendGate {
	/**
	 * @param IAppManager $apps Native per-actor application availability.
	 * @param IUserManager $users Resolve the operation actor without an owner fallback.
	 * @param EncryptionManager $encryption Native encryption enablement.
	 * @param IRequest $request Detect the native skip-trash header.
	 * @param IAppConfig $appConfig Official explicit trash-size setting reader.
	 * @param IConfig $config Official per-user trash-size and file locking setting reader, supported on NC32–35.
	 * @param ILockingProvider $locking Actual server locking provider.
	 * @param ContainerInterface $container Resolve the optional trash app only after its admission.
	 */
	public function __construct(
		private IAppManager $apps,
		private IUserManager $users,
		private EncryptionManager $encryption,
		private IRequest $request,
		private IAppConfig $appConfig,
		private IConfig $config,
		private ILockingProvider $locking,
		private ContainerInterface $container,
	) {
	}

	/**
	 * Checks the selected backend and explicit trash-size limit; never calls a backend mutation API.
	 * Native user-quota expiration does not provide a guaranteed future recovery allowance.
	 * NC32–35 expose getBackendForStorage on TrashManager, with a non-strict bool transfer contract.
	 * @param Node $file Already visible, readable actor node with a verified canonical Local home origin.
	 * @param string $uid Authenticated actor.
	 * @return void
	 * @throws ToolFailure When a known opt-out, unsupported backend or unreadable gate refuses this call.
	 */
	public function check(Node $file, string $uid): void {
		try {
			$user = $this->users->get($uid);
			$owner = $file->getOwner()?->getUID();
			$ownerUser = is_string($owner) && $owner !== '' ? $this->users->get($owner) : null;
			if ($user === null || $ownerUser === null || !$this->apps->isEnabledForUser('files_trashbin', $user)
				|| !$this->apps->isEnabledForUser('files_trashbin', $ownerUser)
				|| $this->encryption->isEnabled() || !$this->config->getSystemValueBool('filelocking.enabled', true)
				|| $this->locking instanceof \OC\Lock\NoopLockingProvider
				|| pathinfo($file->getName(), PATHINFO_EXTENSION) === 'part'
				|| $this->request->getHeader('X-NC-Skip-Trashbin') === 'true') {
				throw new \RuntimeException('Ineligible');
			}
			$manager = $this->container->get('OCA\\Files_Trashbin\\Trash\\ITrashManager');
			$backend = $manager->getBackendForStorage($file->getStorage());
			if (get_class($backend) !== 'OCA\\Files_Trashbin\\Trash\\LegacyTrashBackend'
				|| !$file->getStorage()->instanceOfStorage('OCA\\Files_Trashbin\\Storage')) {
				throw new \RuntimeException('Unsupported backend');
			}
			$userLimit = $this->config->getUserValue($owner, 'files_trashbin', 'trashbin_size', '-1');
			$limit = is_numeric($userLimit) && (float)$userLimit >= 0
				? $userLimit : $this->appConfig->getValueString('files_trashbin', 'trashbin_size', '-1');
			if (!is_numeric($limit) || $file->getSize(false) < 0 || ((float)$limit >= 0 && $file->getSize(false) >= (float)$limit)) {
				throw new \RuntimeException('Unknown or insufficient trash allowance');
			}
		} catch (\Throwable) {
			throw new ToolFailure(DeleteMessages::unavailable());
		}
	}
}
