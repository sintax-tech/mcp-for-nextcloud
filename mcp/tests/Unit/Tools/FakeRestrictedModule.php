<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Tools\ToolModule;

/** A read-only module only some users may see, recording the calls it gets and the outcomes the registry audits. */
final class FakeRestrictedModule implements ToolModule, RestrictedModule {
    /** @var list<string> users inside the role */
    public array $members = ['root'];
    /** @var list<array{string, array<string, mixed>, string}> calls that reached the module: tool, arguments, user */
    public array $calls = [];
    /** @var list<array{string, string, string, array<string, mixed>}> audited attempts: tool, user, outcome, arguments */
    public array $audits = [];
    /** Thrown by the next calls when set. */
    public ?\Throwable $throw = null;
    /** Thrown by audit() when set, as a failing audit backend would. */
    public ?\Throwable $auditThrow = null;

    public function permits(string $userId): bool {
        return in_array($userId, $this->members, true);
    }

    public function audit(string $tool, string $userId, string $outcome, array $arguments): void {
        $this->audits[] = [$tool, $userId, $outcome, $arguments];
        if ($this->auditThrow !== null) {
            throw $this->auditThrow;
        }
    }

    public function definitions(): array {
        return [['name' => 'logs_list', 'description' => 'l', 'inputSchema' => ToolRegistryTest::schema(), 'module' => 'logs', 'operation' => 'read']];
    }

    public function call(string $name, array $arguments, string $userId): array {
        $this->calls[] = [$name, $arguments, $userId];
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return ['content' => [['type' => 'text', 'text' => 'ok']]];
    }
}
