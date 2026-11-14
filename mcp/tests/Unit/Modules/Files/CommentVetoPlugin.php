<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

/**
 * A third-party DAV plugin that vetoes every new comment, the way an app may add one. A comment POST never reaches
 * createFile, so beforeBind never fires for it: the veto sits on the POST itself, before the comments plugin.
 */
final class CommentVetoPlugin extends ServerPlugin {
	/** @var list<string> the POSTs it refused, as "method path" */
	public array $vetoed = [];

	/** @param Server $server the server the plugin joins @return void */
	public function initialize(Server $server): void {
		$server->on('method:POST', function (RequestInterface $request): void {
			if (str_starts_with($request->getPath(), 'comments/')) {
				$this->vetoed[] = 'POST ' . $request->getPath();
				throw new Forbidden('Vetoed by an app');
			}
		}, 10);
	}

	/** @return string plugin name */
	public function getPluginName(): string {
		return 'mcp-test-comment-veto';
	}
}
