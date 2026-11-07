<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

/** Declares only FO3's newly used core surfaces and receiver-name collisions, not exclusions from API checks. */
final class FormsCreationCoreApiUsage {
	public const CLASSES = ['OCP\\AppFramework\\Http\\DataResponse', 'OCP\\AppFramework\\Services\\IAppConfig',
		'OCP\\BackgroundJob\\IJobList', 'OCP\\Files\\IMimeTypeDetector', 'OCP\\Mail\\IEmailValidator', 'OCP\\Mail\\IMailer',
		'OCP\\Security\\Bruteforce\\IThrottler', 'OCP\\Security\\Bruteforce\\MaxDelayReached'];
	public const POSITIONAL = [
		'OCP\\AppFramework\\Http\\DataResponse::getData' => [], 'OCP\\AppFramework\\Http\\DataResponse::getStatus' => [],
		'OCP\\Security\\Bruteforce\\IThrottler::sleepDelayOrThrowOnMax' => ['ip', 'action'],
		'OCP\\Security\\Bruteforce\\IThrottler::registerAttempt' => ['action', 'ip', 'metadata'],
	];
	// Historical NC31 is still checked by the aggregate contract; the actual app floor is NC32. Forms5.3.0's graph
	// accepts IMailer, whereas later pins request IEmailValidator (present from NC32); neither is accessed on NC31.
	public const VERSION_GATED = ['OCP\\Mail\\IEmailValidator' => ['missing' => ['31'], 'fallback' => 'OCP\\Mail\\IMailer', 'by' => \OCA\Mcp\Modules\Forms\Integration\CreateNativeArguments::class]];
	public const NAME_CLASHES = [
		'OCP\\Files\\File::getData' => 'OCP\\AppFramework\\Http\\DataResponse::getData in Tools/Forms/Creation/CreateFormWriter',
		'OCP\\Files\\FileInfo::getData' => 'OCP\\AppFramework\\Http\\DataResponse::getData in Tools/Forms/Creation/CreateFormWriter',
		'OCP\\Files\\Folder::getData' => 'OCP\\AppFramework\\Http\\DataResponse::getData in Tools/Forms/Creation/CreateFormWriter',
		'OCP\\Files\\IRootFolder::getData' => 'OCP\\AppFramework\\Http\\DataResponse::getData in Tools/Forms/Creation/CreateFormWriter',
		'OCP\\Files\\Node::getData' => 'OCP\\AppFramework\\Http\\DataResponse::getData in Tools/Forms/Creation/CreateFormWriter',
	];
}
