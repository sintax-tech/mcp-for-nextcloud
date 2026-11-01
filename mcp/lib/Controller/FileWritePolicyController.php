<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Admin-only, CSRF-protected settings endpoint using the unmodified Nextcloud defaults. */
final class FileWritePolicyController extends Controller {
	/**
	 * @param string $appName Application identifier.
	 * @param IRequest $request Authenticated framework request.
	 * @param FileWritePolicy $policy Canonical validation, persistence and verified read-back.
	 */
	public function __construct(string $appName, IRequest $request, private FileWritePolicy $policy) {
		parent::__construct($appName, $request);
	}

	/** @return JSONResponse Current policy, or an explicit unavailable state without a default policy. */
	public function show(): JSONResponse {
		try {
			return new JSONResponse($this->policy->current());
		} catch (FileWritePolicyUnavailable) {
			return $this->unavailable();
		}
	}

	/** @return JSONResponse Verified complete policy, a field error (400), or unavailable state (503). */
	public function update(): JSONResponse {
		$mode = $this->request->getParam('mode');
		$extensions = $this->request->getParam('extensions');
		$allowExtensionless = $this->request->getParam('allowExtensionless');
		if (!is_string($mode) || !in_array($mode, ['off', 'allowlist'], true)) {
			return $this->invalid('mode');
		}
		if (!is_bool($allowExtensionless)) {
			return $this->invalid('allowExtensionless');
		}
		if (!is_string($extensions) && !is_array($extensions)) {
			return $this->invalid('extensions');
		}
		try {
			return new JSONResponse($this->policy->save($mode, $extensions, $allowExtensionless));
		} catch (InvalidArgumentException) {
			return $this->invalid('extensions');
		} catch (FileWritePolicyUnavailable) {
			return $this->unavailable();
		}
	}

	/**
	 * @param string $field Fixed form field identifier, never submitted input.
	 * @return JSONResponse Machine-readable field error; the browser translates its own safe text.
	 */
	private function invalid(string $field): JSONResponse {
		return new JSONResponse(['error' => 'invalid_policy', 'field' => $field], 400);
	}

	/** @return JSONResponse No backend detail or policy value escapes a read/write failure. */
	private function unavailable(): JSONResponse {
		return new JSONResponse(['error' => 'policy_unavailable'], 503);
	}
}
