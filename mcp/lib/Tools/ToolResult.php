<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/** Builders for MCP tool results. */
final class ToolResult {
    /** @return array{content: list<array{type:string, text:string}>} */
    public static function text(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param mixed $data value rendered as indented JSON text
     * @return array{content: list<array{type:string, text:string}>}
     * @throws \JsonException when the value cannot be encoded
     */
    public static function json(mixed $data): array {
        return self::text(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $message client-safe message
     * @return array{content: list<array{type:string, text:string}>, isError: bool}
     */
    public static function error(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
