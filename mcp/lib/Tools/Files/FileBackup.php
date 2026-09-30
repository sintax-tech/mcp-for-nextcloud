<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;

/**
 * Guards a file edit: versioning must be on, the file writable and unchanged (etag), and a verified
 * copy of the original must exist in the user's backup folder before the caller may write.
 */
class FileBackup {
    /** Folder in the user's root that receives a copy of every file before files_edit writes it. */
    public const FOLDER = 'MCP backups';

    public function __construct(
        private IAppManager $appManager,
        private IUserManager $userManager,
        private ITimeFactory $time,
    ) {}

    /**
     * @param string $path normalized user-relative path
     * @return bool whether the path is the backup folder or inside it
     */
    public static function isBackupPath(string $path): bool {
        return $path === '/' . self::FOLDER || str_starts_with($path, '/' . self::FOLDER . '/');
    }

    /**
     * Runs every precondition in order; any failure leaves the original untouched.
     *
     * @param Folder $root the user's folder
     * @param File $file file about to be overwritten
     * @param string $path normalized user-relative path of $file
     * @param string $userId authenticated user
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return string user-relative path of the verified backup copy
     * @throws ToolFailure when versioning is off, the file is not writable, the etag differs or the copy fails
     */
    public function prepare(Folder $root, File $file, string $path, string $userId, ?string $etag): string {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_versions', $user)) {
            throw new ToolFailure('Edição bloqueada: o versionamento de arquivos (files_versions) não está ativo.');
        }
        if (!$file->isUpdateable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        NodeAccess::checkEtag($file, $etag);
        return $this->copy($root, $file, $path);
    }

    /** Copies the original next to its mirrored path in the backup folder and checks the copy's size. */
    private function copy(Folder $root, File $file, string $path): string {
        try {
            $folder = NodeAccess::ensureFolder($root, self::FOLDER . dirname($path));
            $base = $file->getName() . '.' . gmdate('Ymd-His', $this->time->getTime());
            $name = $base . '.bak';
            for ($i = 2; $folder->nodeExists($name); $i++) {
                $name = $base . '-' . $i . '.bak';
            }
            $copy = $file->copy($folder->getPath() . '/' . $name);
            $valid = $copy instanceof File && $copy->getSize() === $file->getSize();
        } catch (\Throwable) {
            $valid = false;
        }
        if (!$valid) {
            throw new ToolFailure('Edição bloqueada: não foi possível criar a cópia de segurança do original.');
        }
        return $root->getRelativePath($copy->getPath()) ?? '';
    }
}
