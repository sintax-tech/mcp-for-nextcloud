<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use Closure;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** A Sabre authentication backend that only records being asked, and never authenticates. */
final class RecordingBackend implements BackendInterface {
	/** @param Closure(): void $record called on every check */
	public function __construct(private Closure $record) {}

	/**
	 * @param RequestInterface $request request being authenticated
	 * @param ResponseInterface $response response being built
	 * @return array{false, string} always refuses
	 */
	public function check(RequestInterface $request, ResponseInterface $response): array {
		($this->record)();
		return [false, 'recording backend never authenticates'];
	}

	/**
	 * @param RequestInterface $request request being refused
	 * @param ResponseInterface $response response being built
	 * @return void no challenge
	 */
	public function challenge(RequestInterface $request, ResponseInterface $response): void {}
}
