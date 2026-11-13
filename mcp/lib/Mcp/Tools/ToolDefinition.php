<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/**
 * What a {@see Tool} publishes and what the registry needs to gate it. The title and the annotations are derived by
 * {@see ToolPresentation} from the name, the operation and {@see $destructiveHint}; a tool never declares them.
 */
final readonly class ToolDefinition {
	/**
	 * @param string $name technical name, `<module>_<verb>[_<object>]`, never renamed once published
	 * @param string $module module id in the grant catalog
	 * @param string $operation grant operation: `read`, or the write it performs
	 * @param string $description English description for the model
	 * @param array<string, mixed> $inputSchema JSON Schema of the arguments
	 * @param string|null $app optional Nextcloud app the tool needs enabled for the user
	 * @param list<string>|null $grantAnyOf grant alternatives when the kind of write depends on the arguments
	 * @param bool $destructiveHint whether the annotations mark the tool destructive beyond its operation's default
	 * @param list<string> $requires installation capabilities, `<module>.<capability>`, all needed for the tool to exist
	 * @param list<string> $apps Nextcloud apps a tool that combines several apps needs, every one enabled for the user;
	 *     published only when set, so a tool that needs one app keeps {@see $app} and its shape
	 */
	public function __construct(
		public string $name,
		public string $module,
		public string $operation,
		public string $description,
		public array $inputSchema,
		public ?string $app = null,
		public ?array $grantAnyOf = null,
		public bool $destructiveHint = false,
		public array $requires = [],
		public array $apps = [],
	) {
	}

	/**
	 * @param array{app?:string, apps?:list<string>} $definition a definition in its array shape
	 * @return list<string> every app the tool needs enabled for the user: its app, then each of its apps, once
	 */
	public static function requiredApps(array $definition): array {
		return array_values(array_unique([...(isset($definition['app']) ? [$definition['app']] : []), ...($definition['apps'] ?? [])]));
	}

	/**
	 * The definition in the array shape of a legacy {@see ToolModule}, which the registry and the guide read.
	 * Optional fields appear only when set, as a legacy module writes them.
	 *
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app?:string, grantAnyOf?:list<string>, destructiveHint?:true, requires?:list<string>, apps?:list<string>}
	 */
	public function toArray(): array {
		return [
			'name' => $this->name,
			'description' => $this->description,
			'inputSchema' => $this->inputSchema,
			'module' => $this->module,
			'operation' => $this->operation,
		] + ($this->app !== null ? ['app' => $this->app] : [])
			+ ($this->grantAnyOf !== null ? ['grantAnyOf' => $this->grantAnyOf] : [])
			+ ($this->destructiveHint ? ['destructiveHint' => true] : [])
			+ ($this->requires !== [] ? ['requires' => $this->requires] : [])
			+ ($this->apps !== [] ? ['apps' => $this->apps] : []);
	}
}
