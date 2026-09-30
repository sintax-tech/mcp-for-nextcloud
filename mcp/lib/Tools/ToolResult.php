<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

final class ToolResult {
    public static function text(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    public static function json(mixed $data): array {
        return self::text(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public static function error(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
