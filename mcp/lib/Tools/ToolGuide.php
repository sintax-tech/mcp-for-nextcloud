<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * The `mcp_guide` tool: what the model needs to know about the other tools, built from the very
 * definitions tools/list is built from.
 *
 * Everything printed here comes from a ToolModule::definitions() entry the registry already filtered for
 * this user — the title from ToolPresentation, the description, the inputSchema and the annotations — so
 * a guide can never describe a tool that changed: it changes with it. What no schema can carry is the
 * behaviour around the tools, and that is what {@see ToolGuideNotes} adds, one short note per module, next
 * to the module itself.
 *
 * The guide only reads: it never calls a module, it only renders what the module declared.
 */
final class ToolGuide {
    /** Technical name of the guide tool. */
    public const TOOL = 'mcp_guide';

    /**
     * Opened every answer with: the guide is written in English for the model, not for the user reading it.
     *
     * The guide is not translated on purpose. What a tool does is read by the model, which is the one that
     * talks to the user, and a translation here would arrive at the reader in a language nobody chose while
     * still being read by a model that was told to answer in the user's language. So the guide says it is in
     * English and asks for the relay, and {@see ToolPresentation::INSTRUCTIONS} says the same for the whole
     * server.
     */
    private const LANGUAGE_NOTE = 'This guide is in English: relay it to the user in their own language.';

    /**
     * Arguments a module may declare besides the confirmation every write gets. They are read from the
     * schema the registry handed over, so a module that adds a gate of its own shows up here on its own.
     */
    private const EXTRA_GATES = ['confirm_shared'];

    /** @param list<ToolModule> $modules modules in tools/list order, to read their notes */
    public function __construct(private array $modules) {
    }

    /**
     * Definition of the guide itself, in the same shape a module returns, so the registry presents and
     * lists it exactly like every other tool.
     *
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string}
     */
    public function definition(): array {
        return [
            'name' => self::TOOL,
            'description' => 'Describes the tools this user can call. Without arguments it lists every module available '
                . 'and the tools of each one; with module it details every tool of that module; with tool it details a '
                . 'single tool. Use it before acting with a tool you do not already know. Reads only: it changes nothing.',
            'module' => 'guide',
            'operation' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'module' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                        'description' => 'Module to detail, as named in the list: files, notes, calendar, contacts, tasks, deck, talk, people, logs. '
                            . 'Only the modules available to this user are accepted.'],
                    'tool' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                        'description' => 'Single tool to detail, by its technical name (for example files_edit). '
                            . 'Takes precedence over module.'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $definitions tools the user may call now, as registry::definitions() returns them
     * @param array<string, mixed> $arguments validated arguments of the guide call
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
     * @throws ToolFailure when the caller names a module or a tool it cannot see
     */
    public function result(array $definitions, array $arguments): array {
        return isset($arguments['tool'])
            ? $this->oneTool($definitions, (string)$arguments['tool'])
            : (isset($arguments['module'])
                ? $this->oneModule($definitions, (string)$arguments['module'])
                : $this->overview($definitions));
    }

    /** @param list<array<string, mixed>> $definitions @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>} */
    private function overview(array $definitions): array {
        $modules = $this->group($definitions);
        $lines = ['# Tool guide', ''];
        if ($modules === []) {
            $lines[] = 'No module is available to you on this server right now.';
        } else {
            $lines[] = 'Grants and disabled apps are already applied: what is missing from this list you cannot call. '
                . 'The modules available to you are ' . implode(', ', array_keys($modules)) . '.';
            $lines[] = '';
            foreach ($modules as $module => $tools) {
                $titles = array_map(static fn (array $tool): string => (string)$tool['title'], $tools);
                $lines[] = sprintf('- **%s** (`%s`): %d tools — %s.', ToolPresentation::moduleTitle((string)$module),
                    $module, count($tools), implode(', ', $titles));
            }
            $lines[] = '';
            $lines[] = 'Call this tool again with `module` set to one of those names to get every parameter and every '
                . 'behaviour note of that module, or with `tool` to detail a single tool.';
        }
        return $this->render(implode("\n", $lines), [
            'modules' => array_map(fn (string $module, array $tools): array => [
                'module' => $module,
                'title' => ToolPresentation::moduleTitle($module),
                'count' => count($tools),
                'notes' => $this->notes($module),
                'tools' => array_map($this->summary(...), $tools),
            ], array_keys($modules), $modules),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
     * @throws ToolFailure for a module this user cannot see
     */
    private function oneModule(array $definitions, string $module): array {
        $modules = $this->group($definitions);
        if (!isset($modules[$module])) {
            $available = array_keys($modules);
            throw new ToolFailure(sprintf(
                'There is no module "%s" available to you. %s',
                $module,
                $available === []
                    ? 'No module is available to you on this server right now.'
                    : 'The modules you can use are: ' . implode(', ', $available) . '.',
            ));
        }
        $tools = $modules[$module];
        $lines = ['# ' . ToolPresentation::moduleTitle($module) . ' (`' . $module . '`)', ''];
        $lines[] = sprintf('%d tools available to you in this module.', count($tools));
        $lines = array_merge($lines, $this->notesSection($module), ['']);
        foreach ($tools as $tool) {
            $lines = array_merge($lines, $this->section($tool), ['']);
        }
        return $this->render(rtrim(implode("\n", $lines), "\n"), [
            'module' => $module,
            'title' => ToolPresentation::moduleTitle($module),
            'notes' => $this->notes($module),
            'tools' => array_map(fn (array $tool): array => $this->summary($tool) + ['parameters' => $this->parameters($tool['inputSchema'])], $tools),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
     * @throws ToolFailure for a tool this user cannot see
     */
    private function oneTool(array $definitions, string $name): array {
        foreach ($definitions as $definition) {
            if ($definition['name'] !== $name) {
                continue;
            }
            $module = (string)$definition['module'];
            $lines = ['# ' . $definition['title'] . ' (`' . $name . '`)', ''];
            $lines[] = 'Module: ' . ToolPresentation::moduleTitle($module) . ' (`' . $module . '`). Grant operation: `'
                . implode('` or `', $definition['grantAnyOf'] ?? [$definition['operation']]) . '`.' . (isset($definition['app']) ? ' Needs the `' . $definition['app'] . '` app enabled.' : '');
            $lines = array_merge($lines, [''], $this->body($definition), [''], $this->notesSection($module));
            return $this->render(rtrim(implode("\n", $lines), "\n"), [
                'module' => $module,
                'title' => ToolPresentation::moduleTitle($module),
                'name' => $name,
                'tool' => $this->summary($definition) + ['parameters' => $this->parameters($definition['inputSchema'])],
                'notes' => $this->notes($module),
            ]);
        }
        throw new ToolFailure(sprintf(
            'There is no tool "%s" available to you. Call this guide without arguments to see the tools you can use, '
            . 'or with `module` to see the tools of one module.', $name));
    }

    /**
     * Markdown of one tool inside a module: its heading and its body.
     *
     * @param array<string, mixed> $definition enriched definition of registry::definitions()
     * @return list<string>
     */
    private function section(array $definition): array {
        return array_merge(['## ' . $definition['title'] . ' — `' . $definition['name'] . '`', ''], $this->body($definition));
    }

    /**
     * What the tool does, whether it writes, its parameters and the gate it asks for.
     *
     * @param array<string, mixed> $definition enriched definition of registry::definitions()
     * @return list<string>
     */
    private function body(array $definition): array {
        $annotations = $definition['annotations'];
        $readOnly = $annotations['readOnlyHint'] === true;
        $lines = [];
        $lines[] = ($readOnly ? 'Reads only.' : 'Writes: it changes something in this Nextcloud.')
            . ' ' . $definition['description'];
        if ($annotations['destructiveHint'] === true) {
            $lines[] = 'Flagged as destructive: the client is expected to ask the user before it runs.';
        }
        $parameters = $this->parameters($definition['inputSchema']);
        if ($parameters !== []) {
            $lines[] = '';
            $lines[] = 'Parameters';
            foreach ($parameters as $parameter) {
                $lines[] = $this->parameterLine($parameter);
            }
        }
        $gates = $this->gates($definition);
        if ($gates !== []) {
            $lines[] = '';
            foreach ($gates as $gate) {
                $lines[] = sprintf('Needs `%s` (%s) before it writes.', $gate['name'], $gate['summary']);
            }
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $schema inputSchema of a tool
     * @return list<array{name:string, type:string, required:bool, description:string, constraints:list<string>}>
     */
    private function parameters(array $schema): array {
        $properties = $schema['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array)$properties : (array)$properties;
        $required = array_map(strval(...), (array)($schema['required'] ?? []));
        $parameters = [];
        foreach ($properties as $name => $rule) {
            $rule = is_array($rule) ? $rule : [];
            $parameters[] = [
                'name' => (string)$name,
                'type' => $this->type($rule),
                'required' => in_array((string)$name, $required, true),
                'description' => (string)($rule['description'] ?? ''),
                'constraints' => $this->constraints($rule),
            ];
        }
        return $parameters;
    }

    /** @param array{name:string, type:string, required:bool, description:string, constraints:list<string>} $parameter */
    private function parameterLine(array $parameter): string {
        $between = [$parameter['type'], $parameter['required'] ? 'required' : 'optional'];
        if ($parameter['constraints'] !== []) {
            $between[] = implode(', ', $parameter['constraints']);
        }
        $line = sprintf('- `%s` (%s)', $parameter['name'], implode(', ', $between));
        return $parameter['description'] === '' ? $line : $line . ' — ' . $parameter['description'];
    }

    /**
     * The write gates of a tool.
     *
     * Every tool that is not a read asks for `confirm: true`, and the schema says so: the registry
     * injects the argument into the write tools and refuses the call without it, so the guide derives the
     * rule from the operation instead of listing which tools happen to have the property today.
     *
     * @param array<string, mixed> $definition enriched definition of registry::definitions()
     * @return list<array{name:string, summary:string}>
     */
    private function gates(array $definition): array {
        $gates = [];
        if ($definition['operation'] !== 'read') {
            $gates[] = ['name' => 'confirm', 'summary' => 'boolean, must be true'];
        }
        $properties = $definition['inputSchema']['properties'] ?? [];
        $properties = $properties instanceof \stdClass ? (array)$properties : (array)$properties;
        foreach (self::EXTRA_GATES as $name) {
            if (!isset($properties[$name]) || !is_array($properties[$name])) {
                continue;
            }
            $gates[] = ['name' => $name, 'summary' => (string)($properties[$name]['type'] ?? 'boolean')];
        }
        return $gates;
    }

    /**
     * @param array<string, mixed> $definition enriched definition of registry::definitions()
     * @return array{name:string, title:string, description:string, read_only:bool, destructive:bool, confirmation:list<string>}
     */
    private function summary(array $definition): array {
        return [
            'name' => (string)$definition['name'],
            'title' => (string)$definition['title'],
            'description' => (string)$definition['description'],
            'read_only' => $definition['annotations']['readOnlyHint'] === true,
            'destructive' => $definition['annotations']['destructiveHint'] === true,
            'confirmation' => array_column($this->gates($definition), 'name'),
        ];
    }

    /**
     * @param array<string, mixed> $rule one property schema
     * @return string the type as the guide shows it, unfolding the item type of an array
     */
    private function type(array $rule): string {
        $type = implode('|', array_map(strval(...), (array)($rule['type'] ?? 'any')));
        if ($type !== 'array' || !is_array($rule['items'] ?? null)) {
            return $type;
        }
        $item = $this->type($rule['items']);
        $properties = $rule['items']['properties'] ?? null;
        if ($item !== 'object' || $properties === null) {
            return 'array of ' . $item;
        }
        $properties = $properties instanceof \stdClass ? (array)$properties : (array)$properties;
        return 'array of objects (' . implode(', ', array_map(strval(...), array_keys($properties))) . ')';
    }

    /**
     * @param array<string, mixed> $rule one property schema
     * @return list<string> the limits of the parameter, in the wording of the schema
     */
    private function constraints(array $rule): array {
        $out = [];
        if (array_key_exists('default', $rule)) {
            $out[] = 'default ' . $this->value($rule['default']);
        }
        if (array_key_exists('const', $rule)) {
            $out[] = 'must be ' . $this->value($rule['const']);
        }
        if (isset($rule['enum']) && is_array($rule['enum'])) {
            $out[] = 'one of ' . implode(', ', array_map($this->value(...), $rule['enum']));
        }
        // A string is measured in characters and a number in its own unit, so the two are not read alike.
        $string = in_array($rule['type'] ?? null, ['string', 'array'], true);
        $min = $string ? ($rule['minLength'] ?? null) : ($rule['minimum'] ?? null);
        $max = $string ? ($rule['maxLength'] ?? null) : ($rule['maximum'] ?? null);
        if ($min !== null && $max !== null) {
            $out[] = ($string ? 'length ' : '') . $min . '..' . $max;
        } elseif ($min !== null) {
            $out[] = ($string ? 'length from ' : 'from ') . $min;
        } elseif ($max !== null) {
            $out[] = ($string ? 'length up to ' : 'up to ') . $max;
        }
        if (isset($rule['minItems'])) {
            $out[] = 'at least ' . $rule['minItems'] . ' items';
        }
        if (isset($rule['maxItems'])) {
            $out[] = 'at most ' . $rule['maxItems'] . ' items';
        }
        return $out;
    }

    /** @param mixed $value a default or a const from the schema */
    private function value(mixed $value): string {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return array<string, list<array<string, mixed>>> the tools of each module, in declaration order
     */
    private function group(array $definitions): array {
        $modules = [];
        foreach ($definitions as $definition) {
            $modules[(string)$definition['module']][] = $definition;
        }
        return $modules;
    }

    /** @return list<string> */
    private function notes(string $module): array {
        foreach ($this->modules as $candidate) {
            if ($candidate instanceof ToolGuideNotes && $this->moduleOf($candidate) === $module) {
                return $candidate->guideNotes();
            }
        }
        return [];
    }

    /** @return list<string> markdown of the behaviour notes of a module, empty when it declares none */
    private function notesSection(string $module): array {
        $notes = $this->notes($module);
        if ($notes === []) {
            return [];
        }
        $lines = ['', 'Behaviour notes', ''];
        foreach ($notes as $note) {
            $lines[] = '- ' . $note;
        }
        return array_merge($lines, ['']);
    }

    /** @return string|null the grant module a ToolModule declares, or null when it declares no tool */
    private function moduleOf(ToolModule $module): ?string {
        foreach ($module->definitions() as $definition) {
            return (string)$definition['module'];
        }
        return null;
    }

    /**
     * @param string $markdown the guide as readable markdown
     * @param array<string, mixed> $data the same guide as structured content
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
     */
    private function render(string $markdown, array $data): array {
        // Every text of the guide is English, on purpose: it is written for the model, which translates it
        // for the user. Saying so in the answer itself keeps a client that relays the text from dropping the
        // only hint the reader has that the text was not written for their eyes.
        return ToolResult::structured(self::LANGUAGE_NOTE . "\n\n"
            . 'Write confirmation plans arrive as readable text in the user’s language, with the complete plan in structuredContent. Show that text to the user exactly as received.'
            . "\n\n" . $markdown, ['language_note' => self::LANGUAGE_NOTE] + $data);
    }
}