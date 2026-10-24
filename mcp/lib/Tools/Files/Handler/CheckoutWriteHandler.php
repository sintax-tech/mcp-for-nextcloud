<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Controller\CheckoutController;
use OCA\Mcp\Service\FileWritePolicyUnavailable;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\Files\FileWriteBoundary;
use OCA\Mcp\Tools\Common\FileTypeDenied;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use LogicException;
use Throwable;
use Closure;
use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\LockMessages;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FileCreation;
use OCA\Mcp\Tools\Files\FileExists;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** PUT/POST write boundaries; rechecks policy before spending, backup and actual storage writes. */
final class CheckoutWriteHandler {
	/**
	 * @param IRootFolder $rootFolder Official root resolver.
	 * @param FileCreation|null $creation New-file service.
	 * @param FileBackup $backup Mandatory internal recovery copy; never receives the policy.
	 * @param SharedWriteGuard $guard Existing shared confirmation.
	 * @param NodeAccessInfo $accessInfo Existing access information.
	 * @param LoggerInterface $logger Client-safe diagnostics.
	 * @param FilesAccessGuard|null $visibilityGuard Existing exclusions/scope.
	 * @param LockAwareWrite|null $locks Existing lock enforcement.
	 * @param UserL10n|null $l10n Owner language.
	 * @param IUserManager|null $userManager Owner resolver.
	 * @param FileTypeGuard|null $fileTypes Current type policy.
	 * @param Closure $resolve Existing token path+id resolution.
	 * @param Closure $admit Existing grant admission.
	 * @param Closure $scopeReadable Existing scope availability.
	 * @param Closure $actsAsUser Existing owner/session comparison.
	 * @param Closure $spend Atomic single-use token consumption.
	 * @param Closure $json Existing JSON response adapter.
	 * @param Closure $refuse Existing safe HTTP refusal adapter.
	 * @param Closure $lockRefusal Existing lock response adapter.
	 */
	public function __construct(private IRootFolder $rootFolder, private ?FileCreation $creation,
		private FileBackup $backup, private SharedWriteGuard $guard, private NodeAccessInfo $accessInfo,
		private LoggerInterface $logger, private ?FilesAccessGuard $visibilityGuard,
		private ?LockAwareWrite $locks, private ?UserL10n $l10n, private ?IUserManager $userManager,
		private ?FileTypeGuard $fileTypes,
		private Closure $resolve, private Closure $admit, private Closure $scopeReadable,
		private Closure $actsAsUser, private Closure $spend, private Closure $json,
		private Closure $refuse, private Closure $lockRefusal) {
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
		$opened = ($this->resolve)($row, CheckoutToken::KIND_UPLOAD);
		if ($opened instanceof Response) {
			return $opened;
		}
		[$row, $file] = $opened;
		if (($failure = $this->policyRefusal($file->getName())) !== null) {
			return $failure;
		}
		// A lock the agent can wait out: refused before the link is spent, so the same link works once it ends.
		try {
			$this->locks?->assertWritable($file, $row->userId, $row->path, ($this->actsAsUser)($row->userId));
		} catch (LockWriteFailure $e) {
			return ($this->refuse)(Http::STATUS_LOCKED, $e->getMessage());
		}
		// The link is spent here, immediately before anything that can change the file. Everything above is
		// a refusal the agent can fix without a new checkout; everything below may already have written.
		if ($failure = ($this->spend)($row)) {
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
			return ($this->json)(Http::STATUS_CONFLICT, $this->guard->request($access, $row->path, CommonMessages::confirmAdviceCheckout()));
		}
		if (!$file->isUpdateable()) {
			return ($this->refuse)(Http::STATUS_FORBIDDEN, CommonMessages::forbidden());
		}
		$copy = '';
		try {
			NodeAccess::checkEtag($file, $row->etag);
			$asUser = ($this->actsAsUser)($row->userId);
			$copy = $this->backup->prepare($root, $file, $row->path, $row->userId, $row->etag, $asUser);
			$this->visibilityGuard?->assertVisible($file);
			FileWriteBoundary::check($this->fileTypes, $file->getName());
			\OCA\Mcp\Tools\Files\BackupWriteBoundary::afterCopy($this->backup, $row->path, $copy);
			$write = static fn () => $file->putContent((string)file_get_contents($body));
			// Checked again right before the write: the backup took time, and files_lock does not refuse every lock.
			NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->run($write, $file, $row->userId, $row->path, $asUser), $this->visibilityGuard);
			$node = NodeAccess::requireFile(NodeAccess::get($root, $row->path, $this->visibilityGuard));
		} catch (FileTypeDenied|FileWritePolicyUnavailable $e) {
			return ($this->refuse)(Http::STATUS_FORBIDDEN, $e->getMessage());
		} catch (LockWriteFailure $e) {
			// The link is spent all the same; a backup taken before the refusal holds the untouched original.
			return ($this->lockRefusal)($e, $copy);
		} catch (LockedException $e) {
			$failure = NodeAccess::lockFailure($e);
			return $failure instanceof LockWriteFailure
				? ($this->lockRefusal)($failure, $copy)
				: ($this->refuse)(Http::STATUS_LOCKED, $failure->getMessage() . ' ' . LockMessages::newCheckoutNeeded());
		} catch (ToolFailure $e) {
			$conflict = $e->getMessage() === CommonMessages::conflict();
			$message = $conflict ? FilesMessages::uploadConflict() : $e->getMessage();
			return ($this->refuse)($conflict ? Http::STATUS_CONFLICT : Http::STATUS_BAD_REQUEST, $message);
		} catch (Throwable) {
			$this->logger->error('MCP checkout upload failed', ['app' => 'mcp']);
			// The copy exists by now, so the message can point at the one thing that still holds the original.
			return ($this->refuse)(Http::STATUS_INTERNAL_SERVER_ERROR, FilesMessages::uploadFailed($copy));
		}
		return ($this->json)(Http::STATUS_OK, [
			'path' => $row->path,
			'size' => (int)filesize($body),
			'etag' => (string)$node->getEtag(),
			'access' => $this->accessInfo->describe($node, $row->userId),
			'backup' => $copy,
		]);
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
		if (($refusal = ($this->admit)($uid, CheckoutController::OPERATION_CREATE) ?? ($this->scopeReadable)()) !== null) {
			return $refusal;
		}
		if ($this->l10n !== null && $this->userManager !== null && ($user = $this->userManager->get($uid)) !== null) {
			Translator::use($this->l10n->forUser($user));
		}
		$creation = $this->creation ?? throw new LogicException('FileCreation is not wired');
		try {
			$target = NodeAccess::run(fn (): array => $creation->target($this->rootFolder->getUserFolder($uid), $row->path));
			$access = $this->accessInfo->describe($target['folder'], $uid);
			if ($access['permissions']['update'] === false) {
				throw new ToolFailure(CommonMessages::forbidden());
			}
			FileCreation::assertCreatable($target['folder']);
		} catch (Throwable $e) {
			return $this->createRefusal($e);
		}
		// The scope recorded with the link can be stale: the folder may have been shared in between.
		if ($access['scope'] !== NodeAccessInfo::PERSONAL && !$row->sharedConfirmed) {
			return ($this->json)(Http::STATUS_CONFLICT, $this->guard->request($access, $row->path, CommonMessages::confirmAdviceUpload()));
		}
		$size = (int)filesize($body);
		$declared = $row->declaredSize();
		if ($size === 0 && $declared !== null && $declared > 0) {
			return ($this->refuse)(Http::STATUS_BAD_REQUEST, FilesMessages::uploadEmptyDeclared($declared));
		}
		if ($failure = ($this->spend)($row, FilesMessages::createTokenSpent())) {
			return $failure;
		}
		$handle = null;
		try {
			$content = $size === 0 ? '' : ($handle = fopen($body, 'rb'));
			if ($content === false) {
				throw new ToolFailure(FilesMessages::createFailed());
			}
			$file = NodeAccess::run(fn (): File => $creation->write($target['folder'], $target['name'], $content, $uid, ($this->actsAsUser)($uid)));
			return ($this->json)(Http::STATUS_CREATED, $creation->receipt($target, $file, $uid));
		} catch (Throwable $e) {
			return $this->createRefusal($e);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	/**
	 * The status a refusal of a new file maps to, so the agent knows whether to rename, ask or give up.
	 *
	 * @param Throwable $e what target() or write() raised
	 * @return Response 409 for a taken name, 400 for a name or a path Nextcloud refuses, 403 for a denied folder,
	 *         404 for a missing or hidden one, 423 for a lock, 507 for a full quota and 500 for a write that failed
	 */
	public function createRefusal(Throwable $e): Response {
		if ($e instanceof FileTypeDenied || $e instanceof FileWritePolicyUnavailable) {
			return ($this->refuse)(Http::STATUS_FORBIDDEN, $e->getMessage());
		}
		if ($e instanceof FileExists) {
			return ($this->refuse)(Http::STATUS_CONFLICT, $e->getMessage());
		}
		if ($e instanceof ArgumentValidationException) {
			return ($this->refuse)(Http::STATUS_BAD_REQUEST, $e->clientMessage());
		}
		if ($e instanceof LockWriteFailure) {
			return ($this->refuse)(Http::STATUS_LOCKED, $e->getMessage());
		}
		if (!$e instanceof ToolFailure) {
			$this->logger->error('MCP create upload failed', ['app' => 'mcp', 'exception_class' => $e::class]);
			return ($this->refuse)(Http::STATUS_INTERNAL_SERVER_ERROR, FilesMessages::createFailed());
		}
		return match ($e->getMessage()) {
			CommonMessages::forbidden() => ($this->refuse)(Http::STATUS_FORBIDDEN, $e->getMessage()),
			FilesMessages::backupPath() => ($this->refuse)(Http::STATUS_BAD_REQUEST, $e->getMessage()),
			CommonMessages::locked() => ($this->refuse)(Http::STATUS_LOCKED, $e->getMessage()),
			CommonMessages::insufficientQuota() => ($this->refuse)(Http::STATUS_INSUFFICIENT_STORAGE, $e->getMessage()),
			FilesMessages::createFailed() => ($this->refuse)(Http::STATUS_INTERNAL_SERVER_ERROR, $e->getMessage()),
			default => ($this->refuse)(Http::STATUS_NOT_FOUND, $e->getMessage()),
		};
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
			return ($this->refuse)(Http::STATUS_FORBIDDEN, $e->getMessage());
		}
	}
}
