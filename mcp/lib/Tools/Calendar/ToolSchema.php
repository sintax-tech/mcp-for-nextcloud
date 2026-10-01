<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Shared JSON Schema fragments, definition envelope and result encoding of the calendar tools.
 */
final class ToolSchema {
    /** Grant module of every calendar tool. */
    public const MODULE = 'calendar';
    /** Nextcloud app that must be enabled for the user. */
    public const APP = 'calendar';

    /**
     * @param string $name tool name
     * @param string $description Portuguese description
     * @param string $operation grant operation
     * @param array<string, array<string, mixed>> $properties JSON Schema properties
     * @param list<string> $required required property names
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public static function definition(string $name, string $description, string $operation, array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => (object)$properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
            'module' => self::MODULE,
            'operation' => $operation,
            'app' => self::APP,
        ];
    }

    /**
     * @param string $description Portuguese description
     * @return array<string, mixed> calendar path property
     */
    public static function calendar(string $description = CalendarMessages::PROP_CALENDAR): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'description' => $description];
    }

    /**
     * @return array<string, mixed> event UID property
     */
    public static function uid(): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => CalendarMessages::PROP_UID];
    }

    /**
     * @return array<string, mixed> optional ETag property for optimistic concurrency
     */
    public static function etag(): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => CalendarMessages::PROP_ETAG];
    }

    /**
     * @param string $description Portuguese description
     * @return array<string, mixed> date or timestamp property
     */
    public static function date(string $description): array {
        return ['type' => 'string', 'minLength' => 10, 'maxLength' => 40, 'description' => $description];
    }

    /**
     * @param string $description Portuguese description
     * @param int $minLength minimum length
     * @param int $maxLength maximum length
     * @return array<string, mixed> text property
     */
    public static function text(string $description, int $minLength, int $maxLength): array {
        return ['type' => 'string', 'minLength' => $minLength, 'maxLength' => $maxLength, 'description' => $description];
    }

    /**
     * @param bool $allowEmpty update accepts [] to remove every attendee
     * @return array<string, mixed> guest list property: internal account ids only
     */
    public static function attendees(bool $allowEmpty = false): array {
        return [
            'type' => 'array',
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'minItems' => $allowEmpty ? 0 : 1,
            'maxItems' => AttendeeResolver::MAX_ATTENDEES,
            'uniqueItems' => true,
            'description' => CalendarMessages::PROP_ATTENDEES,
        ];
    }

    /**
     * @return array<string, mixed> opt-in invitation property, defaulting to sending nothing
     */
    public static function sendInvitations(): array {
        return [
            'type' => 'boolean',
            'default' => false,
            'description' => CalendarMessages::PROP_SEND_INVITATIONS,
        ];
    }

    /**
     * @param mixed ...$blocks values encoded as indented JSON, one text block each
     * @return array{content: list<array{type:string, text:string}>} MCP result
     */
    public static function result(mixed ...$blocks): array {
        $content = [];
        foreach ($blocks as $block) {
            $content[] = ['type' => 'text', 'text' => json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)];
        }
        return ['content' => $content];
    }

    /**
     * @param string $message Portuguese message safe to show
     * @return array{content: list<array{type:string, text:string}>, isError: bool} MCP error result
     */
    public static function error(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
