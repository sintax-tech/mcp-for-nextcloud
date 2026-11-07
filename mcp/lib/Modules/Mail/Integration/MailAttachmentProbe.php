<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Mcp\Tools\CapabilityProbe;
use OCP\App\IAppManager;

/** Reports the existing version boundary for PEEK-only attachment reads, without loading a Mail service. */
final class MailAttachmentProbe implements CapabilityProbe {
	/** @param IAppManager $apps installed Mail version */
	public function __construct(private IAppManager $apps) {}

	/** {@inheritDoc} */
	public function keys(): array { return ['mail.attachments']; }

	/** {@inheritDoc} */
	public function supports(string $key): bool { return $key === 'mail.attachments' && MailAttachmentSupport::available($this->apps); }
}
