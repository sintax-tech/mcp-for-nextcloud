<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\Modules\Files\Presentation\SharingMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/** Writes prepared shares through the public core API. */
final class ShareCoreWrite {
	/**
	 * Constructs the sharing collaborator.
	 *
	 * @param IShareManager $shareManager Core sharing API.
	 * @param LoggerInterface $logger Safe exception-class logging.
	 */
	public function __construct(
		private IShareManager $shareManager,
		private LoggerInterface $logger,
	) {}

	/**
	 * The write itself, through IShareManager so the administrator's rules apply (invariant 3). A refusal of the core
	 * becomes a translated message; the log keeps the exception class only, never its message, which can name paths or
	 * carry the new link password. Any Throwable counts, an Error included, so none of them reaches the registry's log.
	 *
	 * The plain link password is handed to setPassword() only: the core validates it with the password policy and
	 * stores its hash (Manager::createShare and updateSharePasswordIfNeeded). Without a new password an update leaves
	 * the stored hash as it is.
	 *
	 * @param array<string, mixed> $change the prepared share change, action create or update
	 * @param string $uid authenticated user, the sharer
	 * @param string|null $password new plain password of a link, null to set none (create) or keep the current one (update)
	 * @return IShare the share as the core stored it
	 * @throws ToolFailure when the core refuses
	 */
	public function write(array $change, string $uid, ?string $password = null): IShare {
		try {
			if ($change['action'] === ShareWriter::UPDATE) {
				$share = $change['existing'];
				$share->setPermissions($change['bits']);
				if ($password !== null) {
					$share->setPassword($password);
				}
				if ($change['expires'] !== null) {
					$share->setExpirationDate($change['expires']);
				}
				if ($change['note'] !== null) {
					$share->setNote($change['note']);
				}
				return $this->shareManager->updateShare($share);
			}
			$share = $this->shareManager->newShare();
			$share->setNode($change['node'])
				->setShareType($change['recipient']->shareType())
				->setSharedBy($uid)
				->setPermissions($change['bits']);
			if ($change['recipient']->kind !== ShareRecipient::LINK) {
				$share->setSharedWith($change['recipient']->id);
			}
			if ($password !== null) {
				$share->setPassword($password);
			}
			if ($change['expires'] !== null) {
				$share->setExpirationDate($change['expires']);
			}
			if ($change['note'] !== null && $change['note'] !== '') {
				$share->setNote($change['note']);
			}
			return $this->shareManager->createShare($share);
		} catch (\Throwable $e) {
			ShareFailureLog::record($this->logger, 'MCP share refused by Nextcloud', $e);
			throw new ToolFailure($e instanceof AlreadySharedException ? SharingMessages::shareAlreadyHasAccess() : SharingMessages::shareRefused());
		}
	}
}
