<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Standard envelopes returned by tools. Keeps encoding consistent and guarantees every tool answers
 * in the format the MCP transport and the clients expect.
 */
final class ToolResult {
    /**
     * @param mixed $data serializable structure
     * @return array{content: list<array{type:string, text:string}>}
     * @throws \JsonException when $data cannot be encoded
     */
    public static function json(mixed $data): array {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            ]],
        ];
    }

    /**
     * Tool result carrying the same answer twice: markdown the model reads and structured content a client
     * can take apart without parsing it.
     *
     * @param string $markdown the answer as readable markdown
     * @param array<string, mixed> $data the same answer as structured content
     * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
     */
    public static function structured(string $markdown, array $data): array {
        return ['content' => [['type' => 'text', 'text' => $markdown]], 'structuredContent' => $data];
    }

    /**
     * @param string $message client-safe message
     * @return array{content: list<array{type:string, text:string}>, isError: true}
     */
    public static function error(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /**
     * Single MCP image content, optionally followed by a metadata JSON text block.
     *
     * @param string $bytes binary image bytes
     * @param string $mimeType e.g. 'image/jpeg'
     * @param array<string, mixed>|null $metadata optional metadata JSON
     * @return array{content: list<array<string, mixed>>}
     * @throws \JsonException when the metadata cannot be encoded
     */
    public static function image(string $bytes, string $mimeType, ?array $metadata = null): array {
        $item = ['bytes' => $bytes, 'mimeType' => $mimeType];
        if ($metadata !== null) {
            $item['metadata'] = $metadata;
        }
        return self::images([$item]);
    }

    /**
     * MCP image content, optionally followed by metadata JSON text blocks. Bytes are base64-encoded here;
     * $metadata is appended as pretty JSON text so the model can place and attribute the image.
     *
     * @param list<array{bytes:string, mimeType:string, metadata?:array<string, mixed>}> $items ordered image blocks
     * @param array<string, mixed>|null $summary optional overall JSON metadata appended at the end
     * @return array{content: list<array<string, mixed>>}
     * @throws \JsonException when the metadata cannot be encoded
     */
    public static function images(array $items, ?array $summary = null): array {
        $content = [];
        foreach ($items as $item) {
            $content[] = [
                'type' => 'image',
                'data' => base64_encode($item['bytes']),
                'mimeType' => $item['mimeType'],
            ];
            if (isset($item['metadata'])) {
                $content[] = ['type' => 'text', 'text' => json_encode($item['metadata'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)];
            }
        }
        if ($summary !== null) {
            $content[] = ['type' => 'text', 'text' => json_encode($summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)];
        }
        return ['content' => $content];
    }
}
