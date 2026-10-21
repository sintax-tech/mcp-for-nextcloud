<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Tools\CheckoutFile;
use OCA\Mcp\Modules\Files\Tools\CopyFile;
use OCA\Mcp\Modules\Files\Tools\CreateFile;
use OCA\Mcp\Modules\Files\Tools\EditFile;
use OCA\Mcp\Modules\Files\Tools\ListFiles;
use OCA\Mcp\Modules\Files\Tools\ListShares;
use OCA\Mcp\Modules\Files\Tools\ListVersions;
use OCA\Mcp\Modules\Files\Tools\MakeDirectory;
use OCA\Mcp\Modules\Files\Tools\MoveBatch;
use OCA\Mcp\Modules\Files\Tools\MoveFile;
use OCA\Mcp\Modules\Files\Tools\ReadFile;
use OCA\Mcp\Modules\Files\Tools\ReadTree;
use OCA\Mcp\Modules\Files\Tools\ReadVersion;
use OCA\Mcp\Modules\Files\Tools\ReplaceText;
use OCA\Mcp\Modules\Files\Tools\RestoreVersion;
use OCA\Mcp\Modules\Files\Tools\SearchFiles;
use OCA\Mcp\Modules\Files\Tools\SearchImages;
use OCA\Mcp\Modules\Files\Tools\ShareFile;
use OCA\Mcp\Modules\Files\Tools\UndoBatch;
use OCA\Mcp\Modules\Files\Tools\UnshareFile;
use OCA\Mcp\Modules\Files\Tools\UploadFile;
use OCA\Mcp\Modules\Files\Tools\ViewImage;
use OCA\Mcp\Modules\Files\Tools\ViewImages;

/** Explicit metadata only: no service lookup or tool construction during discovery. */
final class FilesToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
        return [
            ListFiles::class,
            SearchFiles::class,
            ReadTree::class,
            MakeDirectory::class,
            CopyFile::class,
            MoveFile::class,
            MoveBatch::class,
            UndoBatch::class,
            ReadFile::class,
            EditFile::class,
            ReplaceText::class,
            CheckoutFile::class,
            ListVersions::class,
            ReadVersion::class,
            RestoreVersion::class,
            ViewImage::class,
            ViewImages::class,
            SearchImages::class,
            ListShares::class,
            ShareFile::class,
            UnshareFile::class,
            UploadFile::class,
            CreateFile::class,
        ];
    }

    /**
     * @param list<class-string<Tool>>|null $classes null selects the production catalog
     * @return array<string, array<string, mixed>> definitions keyed by their public name
     */
    public static function definitions(?array $classes = null): array {
        $definitions = [];
        foreach ($classes ?? self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException($class . ' must implement Tool');
            }
            $definition = $class::definition();
            if ($definition->operation !== 'read' && !is_a($class, WriteTool::class, true)) {
                throw new \LogicException($class . ' must implement WriteTool');
            }
            if (isset($definitions[$definition->name])) {
                throw new \LogicException('Duplicate tool name: ' . $definition->name);
            }
            $definitions[$definition->name] = $definition->toArray();
        }
        return $definitions;
    }
}
