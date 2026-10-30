<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The three checkout routes as an HTTP client meets them: `checkout#download` (GET), `checkout#upload` (PUT) and
 * `checkout#uploadPost` (POST) on `/checkout/{token}`. Each call builds the controller of one request through
 * {@see FilesFlowWiring} with the route token and the raw body, and answers status, content type and body as sent.
 */
final class CheckoutRoutes {
	/** Controller action of each verb, as appinfo/routes.php binds them. */
	public const ACTIONS = ['GET' => 'download', 'PUT' => 'upload', 'POST' => 'uploadPost'];

	/**
	 * @param TestCase $test owner of the mocks
	 * @param FilesFlowWiring $wiring builds the controller of each request over the persistent fakes
	 */
	public function __construct(private TestCase $test, private FilesFlowWiring $wiring) {
	}

	/**
	 * @param string $verb GET, PUT or POST
	 * @param string $token the token in the URL
	 * @param string $body raw request body
	 * @param array<string, string> $headers request headers; Content-Length defaults to the body length on PUT/POST
	 * @return array{status:int, contentType:string, body:string} the answer as the client receives it
	 */
	public function call(string $verb, string $token, string $body = '', array $headers = []): array {
		if ($verb !== 'GET') {
			$headers += ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string)strlen($body)];
		}
		$request = (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');
		$request->method('getMethod')->willReturn($verb);
		$action = self::ACTIONS[$verb];
		$response = $this->wiring->controller($request, ['token' => $token], $body)->$action();
		return ['status' => $response->getStatus(), 'contentType' => (string)(self::headers($response)['Content-Type'] ?? ''),
			'body' => self::body($response)];
	}

	/**
	 * @param string $file absolute path of appinfo/routes.php
	 * @return list<string> every `verb url => controller#action` of the checkout controller
	 */
	public static function table(string $file): array {
		$routes = [];
		foreach ((require $file)['routes'] ?? [] as $route) {
			if (str_starts_with((string)$route['name'], 'checkout#')) {
				$routes[] = $route['verb'] . ' ' . $route['url'] . ' => ' . $route['name'];
			}
		}
		return $routes;
	}

	/**
	 * @param Response $response a controller answer
	 * @return array<string, string> its headers, read without the server container getHeaders() needs
	 */
	private static function headers(Response $response): array {
		return (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}

	/**
	 * @param Response $response a controller answer
	 * @return string the bytes it sends: the streamed file of a download, the text or JSON of any other answer
	 */
	private static function body(Response $response): string {
		if ($response instanceof StreamResponse) {
			$handle = (new \ReflectionProperty(StreamResponse::class, 'filePath'))->getValue($response);
			return is_resource($handle) ? (string)stream_get_contents($handle) : '';
		}
		return $response instanceof DataDisplayResponse ? (string)$response->getData() : '';
	}
}
