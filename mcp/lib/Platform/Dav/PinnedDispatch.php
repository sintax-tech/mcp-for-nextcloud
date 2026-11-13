<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Dav;

use RuntimeException;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * Runs one request through an embedded native DAV server as the actor the MCP endpoint already authenticated.
 *
 * The principal is pinned with `CustomPrincipalPlugin::setCurrentPrincipal` (apps/dav/lib/CalDAV/Auth/
 * CustomPrincipalPlugin.php:17-21, identical in v34.0.4 and v35.0.1), the switch Nextcloud's own embedded CalDAV
 * server uses. With a principal set, Sabre's `Auth\Plugin::beforeMethod()` returns before asking any backend
 * (sabre/dav 4.7.0 lib/DAV/Auth/Plugin.php:117-133), so no Sabre authentication backend runs: the authentication is
 * the MCP one, and the pin is refused unless the request session belongs to that same actor before and after the
 * dispatch. The full `OCA\DAV\Server` cannot be used instead: its `Connector\Sabre\Auth` backend reads the global
 * request, which inside an MCP call is the JSON-RPC POST without a CSRF token, and logs the session out
 * (apps/dav/lib/Connector/Sabre/Auth.php:167-179 of v34.0.4, 171-183 of v35.0.1). Calendar and Contacts writes
 * already run this way (`EmbeddedDavDispatcher`, `EmbeddedCardDavDispatcher`). Nothing here changes the global
 * request or the session.
 *
 * Permissions stay native: the tree nodes read `IUserSession`, the ACL plugin reads the pinned principal.
 */
final class PinnedDispatch {
	/** Principal prefix shared by the authenticated user and native DAV ACL. */
	private const PRINCIPAL_PREFIX = 'principals/users/';

	/** @param Session $session authenticated Nextcloud session */
	public function __construct(private Session $session) {}

	/**
	 * Dispatches one request on a fresh native server, as the session's own actor.
	 *
	 * @param Server $server fresh embedded server whose `auth` plugin can pin a principal
	 * @param string $actor UID the MCP endpoint authenticated
	 * @param Request $request native request, with its base URL already set
	 * @return Response the response the native pipeline filled
	 * @throws RuntimeException when the session does not belong to the actor, or the principal cannot be pinned
	 * @throws \Sabre\DAV\Exception when the native pipeline refuses the request
	 */
	public function send(Server $server, string $actor, Request $request): Response {
		$this->assertActor($actor);
		$auth = $server->getPlugin('auth');
		if (!$auth instanceof AuthPlugin || !method_exists($auth, 'setCurrentPrincipal')) {
			throw new RuntimeException('DAV principal cannot be pinned');
		}
		$principal = self::PRINCIPAL_PREFIX . $actor;
		$auth->setCurrentPrincipal($principal);
		if ($auth->getCurrentPrincipal() !== $principal) {
			throw new RuntimeException('DAV principal mismatch');
		}
		$response = new Response();
		$server->httpRequest = $request;
		$server->httpResponse = $response;
		$server->sapi = new CapturingSapi();
		CapturingSapi::reset();
		$server->invokeMethod($request, $response, false);
		$this->assertActor($actor);
		return $response;
	}

	/**
	 * @param string $actor UID the request must run as
	 * @return void
	 * @throws RuntimeException when there is no session, or it belongs to someone else
	 */
	private function assertActor(string $actor): void {
		if ($actor === '' || $this->session->uid() !== $actor) {
			throw new RuntimeException('DAV session mismatch');
		}
	}
}
