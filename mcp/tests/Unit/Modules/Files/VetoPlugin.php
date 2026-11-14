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

/** A third-party DAV plugin that vetoes every new or removed tag relation, the way an app may add one. */
final class VetoPlugin extends ServerPlugin {
	/** @var list<string> the bind and unbind events it refused, as "event path" */
	public array $vetoed = [];

	/** @param Server $server the server the plugin joins @return void */
	public function initialize(Server $server): void {
		foreach (['beforeBind', 'beforeUnbind'] as $event) {
			$server->on($event, function (string $path) use ($event): void {
				if (str_starts_with($path, 'systemtags-relations/')) {
					$this->vetoed[] = $event . ' ' . $path;
					throw new Forbidden('Vetoed by an app');
				}
			});
		}
	}

	/** @return string plugin name */
	public function getPluginName(): string {
		return 'mcp-test-veto';
	}
}
