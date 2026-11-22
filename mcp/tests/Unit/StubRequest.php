<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A ResourceUrl over a stubbed request to https://cloud.example.org at the webroot, the MCP endpoint as unit
 * tests see it; identity icon URLs then read https://cloud.example.org/apps/mcp/img/...
 */
final class StubRequest {
	/** Host the stubbed request answers for. */
	public const HOST = 'cloud.example.org';

	/**
	 * @param TestCase $test owner of the mock
	 * @return ResourceUrl over a stubbed IRequest at the webroot
	 */
	public static function urls(TestCase $test): ResourceUrl {
		$request = (new ReflectionMethod($test, 'createMock'))->invoke($test, IRequest::class);
		$request->method('getRequestUri')->willReturn('/apps/mcp/');
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn(self::HOST);
		return new ResourceUrl($request);
	}

	/** @return array{src:string, mimeType:string, theme?:string} the dark icon of {@see StubRequest::urls()} */
	public static function darkIcon(): array {
		return ['src' => 'https://' . self::HOST . '/apps/mcp/img/app-dark.svg', 'mimeType' => 'image/svg+xml', 'theme' => 'dark'];
	}

	/** @return array{src:string, mimeType:string} the light icon of {@see StubRequest::urls()} */
	public static function lightIcon(): array {
		return ['src' => 'https://' . self::HOST . '/apps/mcp/img/app.svg', 'mimeType' => 'image/svg+xml'];
	}
}
