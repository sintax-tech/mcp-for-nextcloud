<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Mail\Integration\MailAttachmentProbe;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registers the optional attachment capability; all Mail work autowires without factories. */
final class MailRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
		$context->registerService(\OCA\Mcp\Modules\Mail\Creation\MailDraftHtmlPurifier::class,
			static fn () => new \OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine());
	}

	/** {@inheritDoc} */
	public function probes(): array { return [MailAttachmentProbe::class]; }
}
