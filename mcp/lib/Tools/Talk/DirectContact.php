<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

/**
 * A user the caller is allowed to start a direct conversation with, named the way the user knows them.
 *
 * The display name is what a draft has to show: approving "olá Bob" means something, approving "olá bob" as a
 * raw account id does not.
 */
final class DirectContact {
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
    ) {}

    /** @return array{id:string, displayName:string} */
    public function describe(): array {
        return ['id' => $this->id, 'displayName' => $this->displayName];
    }
}
