<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Mail\Integration\MailAttachmentProbe;
use OCA\Mcp\Modules\Mail\Reading\MailMessageReading;
use OCA\Mcp\Platform\Meetings\MeetingMailRead;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the optional attachment capability and binds the Mail reader of a meeting preparation and the Mail steps
 * of a mail card (its source and the attachment save); all Mail work autowires without factories.
 */
final class MailRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
		$context->registerService(\OCA\Mcp\Modules\Mail\Creation\MailDraftHtmlPurifier::class,
			static fn () => new \OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine());
		$context->registerService(MeetingMailRead::class, static fn (ContainerInterface $c): MeetingMailRead => new MailMeetingSearch(
			$c->get(ToolAccess::class),
			$c->get(MailMessageReading::class),
		));
		$context->registerService(\OCA\Mcp\Platform\MailCards\MailCardSource::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\MailCards\MailCardSource => $c->get(MailCardSourceSteps::class));
		$context->registerService(\OCA\Mcp\Platform\MailCards\MailAttachmentSave::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\MailCards\MailAttachmentSave => $c->get(MailAttachmentSaveSteps::class));
	}

	/** {@inheritDoc} */
	public function probes(): array { return [MailAttachmentProbe::class]; }
}
