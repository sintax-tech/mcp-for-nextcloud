<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use OCA\Mcp\Tools\Common\CommonMessages;

/**
 * The one place where "this tool changes something" is decided.
 *
 * A definition whose operation is not `read` is a write, whatever the module says: the registry adds
 * the `confirm` property to the published schema and refuses to run the call without it. The refusal is
 * a normal result carrying the plan, not an error, because the model is expected to read it, show it
 * to the user and ask.
 *
 * `confirm` is deliberately never required and never `const: true`: a call without it is the preview,
 * which is the state every write starts from. A module may implement {@see PreviewsWrites} to answer
 * with a plan of its own; without it the caller gets the generic plan, which still says what the tool
 * is and which arguments it was called with.
 */
final class WriteGate {
    /** Argument every writing tool accepts, and the only thing that lets it run. */
    public const CONFIRM = 'confirm';
    /** The one operation that does not change anything. */
    private const READ_OPERATION = 'read';

    private function __construct() {
    }

    /**
     * @param array{operation?:string} $definition tool definition
     * @return bool whether this tool may change data
     */
    public static function isWrite(array $definition): bool {
        return ($definition['operation'] ?? self::READ_OPERATION) !== self::READ_OPERATION;
    }

    /**
     * The `confirm` property every writing tool is published with.
     *
     * @return array{type: string, description: string}
     */
    public static function confirmProperty(): array {
        return ['type' => 'boolean', 'description' => CommonMessages::confirmParameter()];
    }

    /**
     * Publishes a definition: a write always carries `confirm`, and never demands it.
     *
     * @param array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string} $definition
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string} the definition as the client sees it
     */
    public static function publish(array $definition): array {
        if (!self::isWrite($definition)) {
            return $definition;
        }
        $schema = $definition['inputSchema'];
        $properties = (array)($schema['properties'] ?? []);
        // The property is set, never merged: one wording for every module is what tells the model the rule once.
        $properties[self::CONFIRM] = self::confirmProperty();
        $schema['properties'] = $properties === [] ? new \stdClass() : $properties;
        if (isset($schema['required'])) {
            $required = array_values(array_diff((array)$schema['required'], [self::CONFIRM]));
            if ($required === []) {
                unset($schema['required']);
            } else {
                $schema['required'] = $required;
            }
        }
        $definition['inputSchema'] = $schema;
        return $definition;
    }

    /**
     * @param array<string, mixed> $arguments validated arguments
     * @return bool whether the caller confirmed the write
     */
    public static function confirmed(array $arguments): bool {
        return ($arguments[self::CONFIRM] ?? false) === true;
    }

    /**
     * The plan a call without `confirm: true` answers with.
     *
     * The envelope is written here, so a module's own plan cannot answer without saying that nothing
     * was changed; `+` keeps the module's keys, `requiresConfirmation` first so it is never the one lost.
     *
     * @param ToolModule $module module that serves the tool
     * @param array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string} $definition
     * @param array<string, mixed> $arguments validated arguments
     * @param string $userId authenticated user
     * @return array<string, mixed> the structured content of a non-error result
     */
    public static function plan(ToolModule $module, array $definition, array $arguments, string $userId): array {
        $plan = $module instanceof PreviewsWrites
            ? $module->preview($definition['name'], $arguments, $userId)
            : self::generic($definition, $arguments);
        return [
            'requiresConfirmation' => true,
            'tool' => $definition['name'],
            'title' => ToolPresentation::title($definition['name']),
        ] + $plan;
    }

    /**
     * The plan of a module that does not describe its own writes: the tool, its arguments and the
     * instruction to show them to the user. Enough for the model to ask; the confirmed call then runs.
     *
     * @param array{name:string} $definition
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function generic(array $definition, array $arguments): array {
        unset($arguments[self::CONFIRM]);
        return [
            'action' => $definition['name'],
            'arguments' => $arguments,
            'message' => CommonMessages::planNothingChanged(),
        ];
    }
}