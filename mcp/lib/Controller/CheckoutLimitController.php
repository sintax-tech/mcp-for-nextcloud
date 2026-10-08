<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Tools\Files\CheckoutService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Admin JSON API of the files_checkout upload limit, so it never needs occ. Admin-only and CSRF-protected by
 * Nextcloud's defaults. The limit is edited in whole MiB and stored in bytes under CheckoutService::MAX_BYTES_KEY;
 * the effective limit never exceeds PHP's post_max_size.
 */
class CheckoutLimitController extends Controller {
    /** Bytes per MiB. */
    private const MIB = 1024 * 1024;
    /** Highest limit accepted, in MiB (100 GiB); far above any sensible php.ini, it only bounds the input. */
    public const MAX_MIB = 102400;

    public function __construct(
        string $appName,
        IRequest $request,
        private CheckoutService $checkout,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return JSONResponse the limit (see state()) */
    public function show(): JSONResponse {
        return new JSONResponse($this->state());
    }

    /**
     * Body {mib: int}, a whole number of MiB from 1 to MAX_MIB.
     *
     * @return JSONResponse the new limit (see state()), or 400 without writing anything
     */
    public function update(): JSONResponse {
        $mib = $this->request->getParam('mib');
        if (!is_int($mib) || $mib < 1 || $mib > self::MAX_MIB) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        $this->config->setAppValue($this->appName, CheckoutService::MAX_BYTES_KEY, (string)($mib * self::MIB));
        return new JSONResponse($this->state());
    }

    /** @return array{configuredBytes:int, effectiveBytes:int, phpBytes:int, defaultBytes:int} sizes in bytes; phpBytes 0 = unlimited */
    private function state(): array {
        return [
            'configuredBytes' => $this->checkout->configuredMaxBytes(),
            'effectiveBytes' => $this->checkout->maxBytes(),
            'phpBytes' => $this->checkout->phpMaxBytes(),
            'defaultBytes' => CheckoutService::DEFAULT_MAX_BYTES,
        ];
    }
}
