<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

/** Declares the native creation graph whose constructor and behavior contracts must be regenerated from pins. */
final class FormsCreationApiUsage {
	public const CLASSES = ['OCA\\Forms\\Controller\\ApiController', 'OCA\\Forms\\Service\\ConfigService', 'OCA\\Forms\\Service\\SubmissionService',
		'OCA\\Forms\\Service\\ConfirmationEmailService', 'OCA\\Forms\\Helper\\FilePathHelper', 'OCA\\Forms\\Activity\\ActivityManager', 'OCA\\Forms\\Db\\ShareMapper', 'OCA\\Forms\\Db\\UploadedFileMapper'];
	public const SOURCES = ['Controller/ApiController', 'Service/ConfigService', 'Service/SubmissionService', 'Service/ConfirmationEmailService',
		'Helper/FilePathHelper', 'Activity/ActivityManager', 'Db/ShareMapper', 'Db/UploadedFileMapper'];
}
