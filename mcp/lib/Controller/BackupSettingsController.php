<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Service\BackupCopies;
use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Service\BackupSettingsWriter;
use OCA\Mcp\Service\Policy\DecimalCounter;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Uses framework admin/authentication/CSRF defaults and the existing policy revision admission. */
final class BackupSettingsController extends Controller {
	/**
	 * @param string $appName app id
	 * @param IRequest $request framework-authenticated request
	 * @param BackupLocation $location current document
	 * @param BackupCopies $copies effective per-user status
	 * @param BackupSettingsWriter $writer revisioned save gateway
	 * @param PolicyStateMapper $state existing global policy revision
	 * @param IUserSession $session administrator's identity for the effective status
	 */
	public function __construct(string $appName, IRequest $request, private BackupLocation $location,
		private BackupCopies $copies, private BackupSettingsWriter $writer, private PolicyStateMapper $state,
		private IUserSession $session) {
		parent::__construct($appName, $request);
	}

	/** @return JSONResponse current settings without inventing defaults after a backend failure */
	public function show(): JSONResponse {
		try {
			$revision = $this->state->state()['revision'];
			return new JSONResponse($this->data($revision));
		} catch (\Throwable) {
			return new JSONResponse(['error' => 'Unavailable'], 503);
		}
	}

	/** @return JSONResponse committed settings, validation error, stale revision or unavailable result */
	public function update(): JSONResponse {
		$expected = $this->request->getParam('expectedRevision');
		if ($expected === null) {
			return new JSONResponse(['error' => 'PreconditionRequired'], 428);
		}
		$folder = $this->request->getParam('folder');
		$copies = $this->request->getParam('copies');
		if (!is_string($expected) || !is_string($folder) || !is_bool($copies)) {
			return new JSONResponse(['error' => 'Invalid request'], 400);
		}
		try {
			DecimalCounter::validate($expected);
			$result = $this->writer->save($expected, $folder, $copies);
			// A later read can fail after commit: report uncertainty, never suggest retrying the same draft.
			try {
				return new JSONResponse($this->data($result['revision']));
			} catch (\Throwable) {
				return new JSONResponse(['error' => 'Unavailable', 'uncertain' => true], 503);
			}
		} catch (\InvalidArgumentException) {
			return new JSONResponse(['error' => 'Invalid backup settings'], 400);
		} catch (PolicyConflict) {
			return new JSONResponse(['error' => 'Conflict'], 409);
		} catch (PolicyUnavailable $e) {
			return new JSONResponse(['error' => 'Unavailable', 'uncertain' => $e->uncertain], 503);
		} catch (\Throwable) {
			return new JSONResponse(['error' => 'Unavailable'], 503);
		}
	}

	/**
	 * @param string $revision revision read before settings or returned by the just-committed command
	 * @return array<string, mixed> settings and effective copy state for the administrator's own account
	 */
	private function data(string $revision): array {
		$user = $this->session->getUser();
		if ($user === null) {
			throw new \RuntimeException('No actor');
		}
		return $this->location->current() + [
			'revision' => $revision,
			'versionsAvailable' => $this->copies->versionsAvailable(),
			'versionsForActor' => $this->copies->versionsAvailable($user->getUID()),
			'effectiveCopies' => $this->copies->required($user->getUID()),
		];
	}
}
