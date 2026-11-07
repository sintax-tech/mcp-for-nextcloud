<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCP\IUserSession;

/** Builds the actual native named arguments consumed by the fresh factory, preserving resolution and value order. */
final class CreateNativeArguments {
	/** Builds the version-specific submission collaborator arguments, without constructing or caching services.
	 * @param string $version Exact admitted pin.
	 * @param callable(string):object $get Forms-scoped native dependency resolver.
	 * @param IUserSession $session Actual authenticated session.
	 * @param object $service Exact proven FormsService.
	 * @return array<string,object> Actual named arguments, in the factory's original resolution order.
	 */
	public static function submission(string $version, callable $get, IUserSession $session, object $service): array {
		$native = ['questionMapper' => $get(\OCA\Forms\Db\QuestionMapper::class), 'submissionMapper' => $get(\OCA\Forms\Db\SubmissionMapper::class),
			'answerMapper' => $get(\OCA\Forms\Db\AnswerMapper::class), 'uploadedFileMapper' => $get(\OCA\Forms\Db\UploadedFileMapper::class),
			'rootFolder' => $get(\OCP\Files\IRootFolder::class), 'config' => $get(\OCP\IConfig::class), 'l10n' => $get(\OCP\IL10N::class),
			'logger' => $get(\Psr\Log\LoggerInterface::class), 'userManager' => $get(\OCP\IUserManager::class), 'userSession' => $session,
			'tempManager' => $get(\OCP\ITempManager::class), 'formsService' => $service, 'urlGenerator' => $get(\OCP\IURLGenerator::class), 'optionMapper' => $get(\OCA\Forms\Db\OptionMapper::class)];
		if ($version === '5.3.0') { $native['mailer'] = $get(\OCP\Mail\IMailer::class); }
		else { $native['emailValidator'] = $get(\OCP\Mail\IEmailValidator::class); }
		if (in_array($version, ['5.4.0', '5.4.1'], true)) { $native['userConfig'] = $get(\OCP\Config\IUserConfig::class); }
		return $native;
	}

	/** Builds the controller arguments after the fresh config and submission services have been constructed.
	 * @param string $version Exact admitted pin.
	 * @param callable(string):object $get Forms-scoped native dependency resolver.
	 * @param IUserSession $session Actual authenticated session.
	 * @param array{service:object,mapper:object} $read Proven read graph.
	 * @param object $config Fresh Forms-scoped ConfigService.
	 * @param object $submission Fresh SubmissionService.
	 * @param array<string,object> $native Previously resolved submission arguments; identities are reused.
	 * @return array<string,object|string> Actual named arguments, in the factory's original resolution order.
	 */
	public static function controller(string $version, callable $get, IUserSession $session, array $read, object $config, object $submission, array $native): array {
		$args = ['appName' => 'forms', 'request' => $get(\OCP\IRequest::class), 'userSession' => $session,
			'answerMapper' => $native['answerMapper'], 'formMapper' => $read['mapper'], 'optionMapper' => $native['optionMapper'],
			'questionMapper' => $native['questionMapper'], 'submissionMapper' => $native['submissionMapper'],
			'confirmationEmailService' => $get(\OCA\Forms\Service\ConfirmationEmailService::class), 'configService' => $config,
			'formsService' => $read['service'], 'submissionService' => $submission, 'l10n' => $native['l10n'], 'logger' => $native['logger'],
			'userManager' => $native['userManager'], 'rootFolder' => $native['rootFolder'], 'uploadedFileMapper' => $native['uploadedFileMapper'],
			'mimeTypeDetector' => $get(\OCP\Files\IMimeTypeDetector::class), 'jobList' => $get(\OCP\BackgroundJob\IJobList::class)];
		if ($version === '5.3.0') { $args['shareMapper'] = $get(\OCA\Forms\Db\ShareMapper::class); }
		else { $args['secureRandom'] = $get(\OCP\Security\ISecureRandom::class); }
		return $args;
	}
}
