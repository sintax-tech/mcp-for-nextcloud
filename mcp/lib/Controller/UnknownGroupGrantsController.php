<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\Policy\UnknownGroupGrants;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Admin-only and CSRF-protected through framework defaults, with an additional explicit admin guard. */
final class UnknownGroupGrantsController extends Controller {
	/**
	 * @param string $appName App identity.
	 * @param IRequest $request Current request.
	 * @param UnknownGroupGrants $unknown Diagnosis and cleanup service.
	 * @param IUserSession $session Current authenticated session.
	 * @param IGroupManager $groups Native administrator check.
	 */
	public function __construct(string $appName, IRequest $request, private UnknownGroupGrants $unknown,
		private IUserSession $session, private IGroupManager $groups) {
		parent::__construct($appName, $request);
	}

	/** @return JSONResponse Fresh diagnosis; no resolver or directory enumeration required. */
	public function index(): JSONResponse {
		return $this->guard(false);
	}

	/** @return JSONResponse Explicit cleanup result or a generic validation/concurrency/storage error. */
	public function clear(): JSONResponse {
		return $this->guard(true);
	}

	/**
	 * @param bool $write Whether to execute the confirmed cleanup.
	 * @return JSONResponse Administrator-only response with no internal error details.
	 */
	private function guard(bool $write): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null || !$this->groups->isAdmin($user->getUID())) {
			return new JSONResponse(['error' => 'Forbidden'], 403);
		}
		try {
			$gid = $this->request->getParam('gid');
			if (!is_string($gid)) {
				throw new \InvalidArgumentException('Invalid group');
			}
			if (!$write) {
				return new JSONResponse($this->unknown->report($gid));
			}
			$expected = $this->request->getParam('expectedRevision');
			if ($expected === null) {
				return new JSONResponse(['error' => 'PreconditionRequired'], 428);
			}
			if (!is_string($expected)) {
				throw new \InvalidArgumentException('Invalid revision');
			}
			return new JSONResponse($this->unknown->clear($expected, $user->getUID(), $gid,
				$this->request->getParam('ids'), $this->request->getParam('grantSnapshot'), $this->request->getParam('confirm')));
		} catch (\InvalidArgumentException) {
			return new JSONResponse(['error' => 'Invalid request'], 400);
		} catch (PolicyConflict) {
			return new JSONResponse(['error' => 'Conflict'], 409);
		} catch (PolicyUnavailable $e) {
			return new JSONResponse(['error' => 'Unavailable', 'uncertain' => $e->uncertain], 503);
		}
	}
}
