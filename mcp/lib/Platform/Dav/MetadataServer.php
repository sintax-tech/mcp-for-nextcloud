<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Dav;

use Sabre\DAV\Server;

/**
 * Builds the embedded native DAV server for file comments and system tags.
 *
 * Implemented by the Files module, the only place allowed to name the DAV app; without a binding the container fails.
 */
interface MetadataServer {
	/**
	 * Builds a fresh server for one request: native tree, native comments and system-tag plugins, native ACL, and an
	 * `auth` plugin that only takes a pinned principal ({@see PinnedDispatch}).
	 *
	 * @param string $path request path below the DAV base, e.g. `comments/files/42`; the comments plugin only
	 *                     listens when the request URI already starts with `comments/` while it is being added
	 * @return Server a server whose request URL is already `$path`, no request dispatched yet
	 */
	public function create(string $path): Server;
}
