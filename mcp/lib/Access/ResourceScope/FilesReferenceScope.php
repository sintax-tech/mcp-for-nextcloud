<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Platform\FileAccess\References\FileReferenceScope;
use OCP\Files\Node;

/**
 * The Files scope step of the structured-reference filter (contract A.5): the user's Files resource scope, read from
 * the operation's policy snapshot. It applies to references coming from Talk and Tasks as well; the Notes exception
 * (D4) belongs to the Notes module only.
 */
final class FilesReferenceScope implements FileReferenceScope {
    public function __construct(private FilesScope $scope) {}

    /** @throws PolicyUnavailable when the scope cannot be read: never answered as "unrestricted" */
    public function restricts(string $userId): bool {
        return $this->scope->restricts($userId);
    }

    /** @throws PolicyUnavailable when the scope cannot be read */
    public function allows(string $userId, Node $node): bool {
        return $this->scope->allows($userId, $node);
    }
}
