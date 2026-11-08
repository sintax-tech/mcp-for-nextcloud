<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use Throwable;
use LogicException;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Writing\FileWriteBoundary;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;

/** PUT/POST write boundaries preserving checks, consumption and backup. */
final class CheckoutWriter {
	/**
	 * @param CheckoutResolver $resolver Visible node resolution.
	 * @param CheckoutPolicy $policy Current capability admission.
	 * @param CheckoutLanguage $language Owner language.
	 * @param CheckoutRefusals $refusals Safe HTTP responses.
	 * @param IRootFolder $rootFolder Official root resolver.
	 * @param FileBackup $backup Internal recovery copy.
	 * @param SharedWriteGuard $guard Shared confirmation.
	 * @param NodeAccessInfo $accessInfo Current access information.
	 * @param LoggerInterface $logger Existing diagnostics.
	 * @param ?FileCreation $creation New-file service.
	 * @param ?FilesAccessGuard $visibilityGuard Existing scope.
	 * @param ?LockAwareWrite $locks Existing locks.
	 * @param ?FileTypeGuard $fileTypes Current type policy.
	 */
	public function __construct(
		private CheckoutResolver $resolver,
		private CheckoutPolicy $policy,
		private CheckoutLanguage $language,
		private CheckoutRefusals $refusals,
		private IRootFolder $rootFolder,
		private FileBackup $backup,
		private SharedWriteGuard $guard,
		private NodeAccessInfo $accessInfo,
		private LoggerInterface $logger,
		private ?FileCreation $creation = null,
		private ?FilesAccessGuard $visibilityGuard = null,
		private ?LockAwareWrite $locks = null,
		private ?FileTypeGuard $fileTypes = null,
	) {
	}

	/**
	 * The upload of a checkout, once the body is kept: the policy and the node, then the link is spent and the file
	 * is replaced.
	 *
	 * @param CheckoutToken $row the upload token, already found, of the right kind, unspent and of this session
	 * @param string $body temporary file holding the uploaded bytes
	 * @return Response the JSON receipt, the confirmation payload or a refusal
	 */
	public function replace(CheckoutToken $row, string $body): Response {
		$opened = $this->resolver->resolve($row, CheckoutToken::KIND_UPLOAD);
		if ($opened instanceof Response) {
			return $opened;
		}
		[$row, $file] = $opened;
		if (($failure = $this->policyRefusal($file->getName())) !== null) {
			return $failure;
		}
		// A lock the agent can wait out: refused before the link is spent, so the same link works once it ends.
		try {
			$this->locks?->assertWritable($file, $row->userId, $row->path, $this->policy->actsAsUser($row->userId));
		} catch (LockWriteFailure $e) {
			return $this->refusals->refuse(Http::STATUS_LOCKED, $e->getMessage());
		}
		// The link is spent here, immediately before anything that can change the file. Everything above is
		// a refusal the agent can fix without a new checkout; everything below may already have written.
		if ($failure = $this->policy->spend($row)) {
			return $failure;
		}
		return $this->write($row, $file, $body);
	}

	/**
	 * The write itself, after the token and the policy were already accepted.
	 *
	 * @param CheckoutToken $row the spent token, carrying the user, path and ETag to enforce
	 * @param File $file the node the token points at
	 * @param string $body temporary file holding the uploaded bytes
	 * @return Response the JSON receipt, the confirmation payload or a refusal
	 */
	public function write(CheckoutToken $row, File $file, string $body): Response {
		if (($failure = $this->policyRefusal($file->getName())) !== null) {
			return $failure;
		}
		$root = $this->rootFolder->getUserFolder($row->userId);
		$access = $this->accessInfo->describe($file, $row->userId);
		// The scope recorded at checkout can be stale: a file may have moved into a share in between.
		if ($access['scope'] !== NodeAccessInfo::PERSONAL && !$row->sharedConfirmed) {
			return $this->refusals->json(Http::STATUS_CONFLICT, $this->guard->request($access, $row->path, CommonMessages::confirmAdviceCheckout()));
		}
		if (!$file->isUpdateable()) {
			return $this->refusals->refuse(Http::STATUS_FORBIDDEN, CommonMessages::forbidden());
		}
		$copy = '';
		$copied = false;
		try {
			NodeAccess::checkEtag($file, $row->etag);
			$asUser = $this->policy->actsAsUser($row->userId);
			$copy = $this->backup->prepare($root, $file, $row->path, $row->userId, $row->etag, $asUser);
			$copied = true;
			$this->visibilityGuard?->assertVisible($file);
			FileWriteBoundary::check($this->fileTypes, $file->getName());
			$this->backup->assertWritablePath($row->path);
			$write = static fn () => $file->putContent((string)file_get_contents($body));
			// Checked again right before the write: the backup took time, and files_lock does not refuse every lock.
			NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->run($write, $file, $row->userId, $row->path, $asUser), $this->visibilityGuard);
			$node = NodeAccess::requireFile(NodeAccess::get($root, $row->path, $this->visibilityGuard));
			// Built inside the block: metadata that cannot be read after the write still names the copy.
			return $this->refusals->json(Http::STATUS_OK, [
				'path' => $row->path,
				'size' => (int)filesize($body),
				'etag' => (string)$node->getEtag(),
				'access' => $this->accessInfo->describe($node, $row->userId),
				'backup' => $copy,
			]);
		} catch (Throwable $e) {
			return (new CheckoutUploadFailure($this->refusals, $this->logger, $this->visibilityGuard))->response($e, $file, $copy, $copied);
		}
	}

	/**
	 * Creates the new file a files_upload link names, after the body was kept.
	 *
	 * The same order as an upload: the files.create grant, then where the file goes — the name is still one Nextcloud
	 * accepts, the folder still exists, is visible, still lets the user add a file and the name is still free — then
	 * the shared scope and the size the plan declared. None of these writes anything, so none of them costs the link.
	 * Only then is the link spent and the file written, never over a file with content that appeared in the meantime.
	 *
	 * @param CheckoutToken $row the create token, already found, unspent and of this session
	 * @param string $body temporary file holding the uploaded bytes, possibly empty
	 * @return Response 201 with the receipt, 409 for a taken name or a missing shared confirmation, or a refusal
	 */
	public function create(CheckoutToken $row, string $body): Response {
		$uid = $row->userId;
		if (($refusal = $this->policy->admit($uid, CheckoutPolicy::OPERATION_CREATE) ?? $this->policy->scopeReadable()) !== null) {
			return $refusal;
		}
		$this->language->forOwner($uid);
		$creation = $this->creation ?? throw new LogicException('FileCreation is not wired');
		try {
			$target = NodeAccess::run(fn (): array => $creation->target($this->rootFolder->getUserFolder($uid), $row->path));
			$access = $this->accessInfo->describe($target['folder'], $uid);
			if ($access['permissions']['update'] === false) {
				throw new ToolFailure(CommonMessages::forbidden());
			}
			FileCreation::assertCreatable($target['folder']);
		} catch (Throwable $e) {
			return $this->refusals->createRefusal($e);
		}
		// The scope recorded with the link can be stale: the folder may have been shared in between.
		if ($access['scope'] !== NodeAccessInfo::PERSONAL && !$row->sharedConfirmed) {
			return $this->refusals->json(Http::STATUS_CONFLICT, $this->guard->request($access, $row->path, CommonMessages::confirmAdviceUpload()));
		}
		$size = (int)filesize($body);
		$declared = $row->declaredSize();
		if ($size === 0 && $declared !== null && $declared > 0) {
			return $this->refusals->refuse(Http::STATUS_BAD_REQUEST, \OCA\Mcp\Modules\Files\Presentation\CreationMessages::uploadEmptyDeclared($declared));
		}
		if ($failure = $this->policy->spend($row, \OCA\Mcp\Modules\Files\Presentation\CreationMessages::createTokenSpent())) {
			return $failure;
		}
		$handle = null;
		try {
			$content = $size === 0 ? '' : ($handle = fopen($body, 'rb'));
			if ($content === false) {
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\CreationMessages::createFailed());
			}
			$file = NodeAccess::run(fn (): File => $creation->write($target['folder'], $target['name'], $content, $uid, $this->policy->actsAsUser($uid)));
			return $this->refusals->json(Http::STATUS_CREATED, $creation->receipt($target, $file, $uid));
		} catch (Throwable $e) {
			return $this->refusals->createRefusal($e);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	/**
	 * @param string $name Current resolved final basename.
	 * @return Response|null 403 on a typed policy refusal, null when admitted.
	 */
	private function policyRefusal(string $name): ?Response {
		try {
			FileWriteBoundary::check($this->fileTypes, $name);
			return null;
		} catch (FileTypeDenied|FileWritePolicyUnavailable $e) {
			return $this->refusals->refuse(Http::STATUS_FORBIDDEN, $e->getMessage());
		}
	}
}
