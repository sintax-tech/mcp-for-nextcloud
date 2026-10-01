<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

/**
 * Builds the MCP result envelope the module answers with.
 *
 * The payload is JSON with indentation, so a client reading the transcript can show the structure of a reply
 * without a second parsing step, and a failure carries only a message written for the user, never a stack trace.
 */
final class ToolResult {
    private function __construct() {
    }

    /**
     * @param array<string, mixed> $payload Result data, already free of anything internal
     * @return array{content:list<array{type:string, text:string}>}
     */
    public static function success(array $payload): array {
        return [
            'content' => [
                ['type' => 'text', 'text' => self::encode($payload)],
            ],
        ];
    }

    /**
     * @param string $message localized message written for the user
     * @return array{content:list<array{type:string, text:string}>, isError:bool}
     */
    public static function error(string $message): array {
        return [
            'content' => [
                ['type' => 'text', 'text' => $message],
            ],
            'isError' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload Result data
     * @return string The payload as indented JSON
     */
    private static function encode(array $payload): string {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? Messages::unexpected() : $json;
    }
}
