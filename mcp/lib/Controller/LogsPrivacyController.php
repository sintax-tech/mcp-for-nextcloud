<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Logs\LogFileNames;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Whether the server log tools hide the text of log messages, from the "Server log" block of the admin page.
 * Admin-only and CSRF-protected by Nextcloud defaults.
 */
class LogsPrivacyController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private LogFileNames $fileNames,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return JSONResponse {hideFileNames: bool, active: bool} active: whether it takes effect now (hidden tags set) */
    public function show(): JSONResponse {
        return new JSONResponse($this->state());
    }

    /**
     * Body {hideFileNames: bool}.
     *
     * @return JSONResponse the same shape as show(), or 400 {error: "Invalid request"}
     */
    public function update(): JSONResponse {
        $value = $this->request->getParam('hideFileNames');
        if (!is_bool($value)) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        $this->fileNames->setEnabled($value);
        return new JSONResponse($this->state());
    }

    /** @return array{hideFileNames: bool, active: bool} */
    private function state(): array {
        return ['hideFileNames' => $this->fileNames->enabled(), 'active' => $this->fileNames->active()];
    }
}
