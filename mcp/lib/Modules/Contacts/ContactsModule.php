<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
final class ContactsModule implements \OCA\Mcp\Mcp\Tools\ToolModule, \OCA\Mcp\Mcp\Confirmation\PreviewsWrites, \OCA\Mcp\Mcp\Confirmation\RendersPlans, \OCA\Mcp\Mcp\Guide\ToolGuideNotes
{
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories)
    {
        $expected = array_keys(ContactsToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }
    public function definitions(): array
    {
        return array_values(ContactsToolCatalog::definitions());
    }
    private function resolve(string $name): Tool
    {
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        if (ContactsToolCatalog::definitions()[$name]['operation'] !== 'read' && !$tool instanceof WriteTool) {
            throw new \LogicException('Writes must implement WriteTool');
        }
        return $tool;
    }
    private function known(string $name): void
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
    }
    public function preview(
        string $name,
        array $arguments,
        string $userId,
    ): array
    {
        $this->known($name);
        if (ContactsToolCatalog::definitions()[$name]['operation'] === 'read') {
            throw new \InvalidArgumentException('Unknown tool');
        }
        return $this->resolve($name)->preview($arguments, new ToolContext($userId));
    }
    public function call(
        string $name,
        array $arguments,
        string $userId,
    ): array
    {
        $this->known($name);
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }
    public function guideNotes(): array
    {
        return [
            'List address books first; your own and shared address books are exposed, plus the ' . 'read-only accounts catalog when the admin allows it. Searching without an address ' . 'book also covers the accounts of the instance (colleagues, with a term of at least 2 ' . 'characters, following the admin user-enumeration settings); each account result has ' . 'accountId, the ID to use for invitations. Use users_search to look for accounts only. ' . 'Account contacts can never be created, edited or deleted.',
            'Search an address book to obtain contact object URIs, then read a contact before ' . 'editing it. Unknown vCard properties are preserved; the preview includes every ' . 'property before and after.',
            'Writes first show a plan without changing anything. Wait for an explicit yes, repeat ' . 'with confirm=true, and acknowledge another owner with confirm_shared=true. The ' . 'optional etag prevents overwriting a newer edit.',
            'Contact deletion is PERMANENT in Nextcloud: there is no contact trash. After ' . 'confirmation, a verified full .vcf copy is saved in /MCP backups/Contacts before ' . 'native CardDAV DELETE. Backup failure prevents deletion; the result gives the backup ' . 'path. Import the .vcf through Contacts to recover it.',
            'These tools are hidden when the Contacts app is disabled. Writing grants start disabled.',
        ];
    }
    public function renderPlan(string $name, array $plan): ?string
    {
        return (new Presentation\ContactsPlanRenderer())->render($name, $plan);
    }
}
