<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;



/** Files tools in their existing discovery order. */
final class FilesToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> ordered public tools */
	public const TOOLS = [
		Tools\ListFiles::class,
		Tools\SearchFiles::class,
		Tools\TreeFiles::class,
		Tools\Mkdir::class,
		Tools\CopyFile::class,
		Tools\MoveFile::class,
		Tools\MoveBatch::class,
		Tools\UndoBatch::class,
		Tools\ReadFile::class,
		Tools\EditFile::class,
		Tools\ReplaceFile::class,
		Tools\CheckoutFile::class,
		Tools\ListVersions::class,
		Tools\ReadVersion::class,
		Tools\RestoreVersion::class,
		Tools\ViewImage::class,
		Tools\ViewImages::class,
		Tools\SearchImages::class,
		Tools\ListShares::class,
		Tools\ShareFile::class,
		Tools\UnshareFile::class,
		Tools\UploadFile::class,
		Tools\CreateFile::class,
		Tools\DeleteFile::class,
	];
	/** Static catalog only. */
	private function __construct() {
	}
}
