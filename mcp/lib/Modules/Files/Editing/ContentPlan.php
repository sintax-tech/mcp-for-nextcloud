<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Node;

final class ContentPlan {
    public function __construct(
        private NodeAccessInfo $accessInfo,
        private SharedWriteGuard $guard,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * The fields every content write shares: who owns the file, whether other people are reached and the
     * backup the write takes before it writes.
     *
     * @param File $file file about to be overwritten
     * @param string $userId authenticated user
     * @param string $path normalized user-relative path of $file
     * @param array<string, mixed> $plan what the specific tool would do
     * @return array<string, mixed>
     * @throws ToolFailure when Nextcloud refuses the update, with or without the shared confirmation
     */
    public function describe(File $file, string $userId, string $path, array $plan): array {
        $shared = $this->guard->guard($file, $userId, $path, false);
        return $plan + [
            'path' => $path,
            'etag' => (string)$file->getEtag(),
            'backup' => '/' . BackupLocation::FOLDER,
            'access' => $this->accessInfo->describe($file, $userId),
            'shared' => $shared === null ? [] : [$shared],
            'requiresSharedConfirmation' => $shared !== null,
            'recoverable' => true,
            'consequence' => EditingMessages::planBackupConsequence(),
        ] + $this->lockNotice($file, $userId, $path);
    }

    /**
     * @param Node $node the file a plan would change
     * @param string $userId authenticated user
     * @param string $path its user-relative path
     * @param bool $asUser false for the plan of a checkout, whose upload runs without the user's session
     * @return array<string, mixed> the lock and its warning for the plan, nothing for a free file
     */
    private function lockNotice(Node $node, string $userId, string $path, bool $asUser = true): array {
        return $this->locks?->planNotice($node, $userId, $path, $asUser) ?? [];
    }
}
