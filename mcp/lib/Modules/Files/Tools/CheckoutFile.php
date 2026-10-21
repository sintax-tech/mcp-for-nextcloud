<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Checkout\CheckoutMessages;
use OCA\Mcp\Modules\Files\Checkout\FileCheckout;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;

final class CheckoutFile implements WriteTool {
    public function __construct(
        private FileCheckout $checkout,
    ) {}

    public static function definition(): ToolDefinition {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()];
        $etag = ['type' => 'string', 'description' => EditingMessages::etag()];
        $confirmShared = ['type' => 'boolean', 'description' => EditingMessages::confirmShared()];
        return new ToolDefinition(
            name: 'files_checkout',
            module: 'files',
            operation: 'edit',
            description: CheckoutMessages::checkoutTool(),
            inputSchema: self::schema(['path' => $path, 'etag' => $etag, 'confirm_shared' => $confirmShared], ['path']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        return $this->checkout->execute($arguments, $userId);
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        return $this->checkout->preview($arguments, $userId);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        return $required === [] ? $schema : $schema + ['required' => $required];
    }
}
