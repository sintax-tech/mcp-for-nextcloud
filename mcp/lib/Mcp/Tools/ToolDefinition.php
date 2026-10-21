<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/** Internal metadata; the existing publisher adds client-facing annotations. */
final readonly class ToolDefinition {
    /** @param array<string, mixed> $inputSchema */
    public function __construct(
        public string $name,
        public string $module,
        public string $operation,
        public string $description,
        public array $inputSchema,
        public ?string $app = null,
        public ?array $grantAnyOf = null,
        public ?bool $destructiveHint = null,
    ) {}

    /** @return array<string, mixed> the legacy ToolModule definition shape */
    public function toArray(): array {
        $definition = [
            'name' => $this->name,
            'module' => $this->module,
            'operation' => $this->operation,
        ];
        if ($this->app !== null) {
            $definition['app'] = $this->app;
        }
        if ($this->grantAnyOf !== null) {
            $definition['grantAnyOf'] = $this->grantAnyOf;
        }
        if ($this->destructiveHint !== null) {
            $definition['destructiveHint'] = $this->destructiveHint;
        }
        $definition['description'] = $this->description;
        $definition['inputSchema'] = $this->inputSchema;
        return $definition;
    }
}
