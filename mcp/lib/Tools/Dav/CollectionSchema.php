<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Dav;

use OCA\Mcp\Tools\Calendar\ToolSchema;

/** Schema fragments shared by the personal DAV collection modules. */
final class CollectionSchema {
    public static function text(string $description, int $minimum = 0, int $maximum = 65536): array {
        return ToolSchema::text($description, $minimum, $maximum);
    }

    public static function definition(string $module, string $app, string $name, string $description, string $operation, array $properties, array $required = []): array {
        $definition = ToolSchema::definition($name, $description, $operation, $properties, $required);
        $definition['module'] = $module;
        $definition['app'] = $app;
        if ($operation !== 'read') {
            $definition['description'] .= ' Returns a detailed plan without writing. Show it to the user, wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. Shared collections also require confirm_shared=true.';
            $definition['inputSchema']['properties']->confirm_shared = ['type'=>'boolean','description'=>'Acknowledge changing a collection owned by another person after showing the shared notice.'];
        }
        return $definition;
    }

    public static function offset(): array { return ['type'=>'integer','minimum'=>0,'maximum'=>1000000,'default'=>0,'description'=>'Offset among matching results; use nextOffset to continue.']; }

    public static function limit(): array { return ['type'=>'integer','minimum'=>1,'maximum'=>200,'default'=>50,'description'=>'Maximum results (1-200).']; }
}
