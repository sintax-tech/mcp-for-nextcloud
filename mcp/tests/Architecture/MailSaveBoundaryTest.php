<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Mail\Integration\MailAttachmentProbe;
use OCA\Mcp\Modules\Mail\MailRegistration;
use OCA\Mcp\Modules\Mail\Tools\SaveAttachment;
use OCA\Mcp\Modules\Mail\Writing\MailSaveContext;
use OCA\Mcp\Platform\FileAccess\{FileReplacement, NewFileDestination};
use OCA\Mcp\Tests\Architecture\Construction\Registrations;
use OCA\Mcp\Tests\Architecture\Dependencies\BoundaryRules;

/**
 * mail_save_attachment (#36) keeps its contract: Mail reaches Files only through the two Platform ports, the tool
 * exists only where the Mail PEEK probe holds, and the save grant is opt-in.
 */
final class MailSaveBoundaryTest extends ArchitectureCase {
	/** No Mail source has a module-isolation edge to Files, with no ledger entry, and an import of Files is one. @return void */
	public function testMailNeverImportsTheFilesModule(): void {
		$edges = array_filter(BoundaryRules::violations(self::tree()), static fn (array $e): bool => $e['rule'] === 'module-isolation'
			&& str_starts_with($e['source'], 'lib/Modules/Mail/') && str_starts_with($e['target'], 'OCA\\Mcp\\Modules\\Files\\'));
		self::assertSame([], array_values($edges));
		$ledger = array_filter(self::json('boundary-exceptions')['exceptions'], static fn (array $e): bool => str_starts_with($e['source'] ?? '', 'lib/Modules/Mail/'));
		self::assertSame([], array_values($ledger));
		$fixture = self::fixture('lib/Modules/Mail/Writing/X.php', 'use OCA~Mcp~Modules~Files~Editing~ProtectedFileReplacement;');
		self::assertContains('module-isolation', array_column($fixture, 'rule'));
	}

	/** The write depends on exactly the Platform creation and replacement ports, each bound once by the composition root. @return void */
	public function testTheWriteDependsOnBothContractPorts(): void {
		$types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), (new \ReflectionMethod(MailSaveContext::class, '__construct'))->getParameters());
		self::assertContains(NewFileDestination::class, $types);
		self::assertContains(FileReplacement::class, $types);
		self::assertSame([], array_values(array_filter($types, static fn (string $type): bool => str_starts_with($type, 'OCA\\Mcp\\Modules\\Files\\'))));
		$factories = Registrations::capture($this);
		self::assertArrayHasKey(NewFileDestination::class, $factories);
		self::assertArrayHasKey(FileReplacement::class, $factories);
	}

	/** The tool requires the Mail probe that the module registers, and that probe is off below Mail 5.10.11. @return void */
	public function testTheProbeGatesTheToolBelowMail51011(): void {
		self::assertSame(['mail.attachments'], SaveAttachment::definition()->requires);
		self::assertContains(MailAttachmentProbe::class, (new MailRegistration())->probes());
		foreach (['5.10.10' => false, '5.10.11' => true] as $version => $supported) {
			$apps = $this->createStub(\OCP\App\IAppManager::class);
			$apps->method('getAppVersion')->willReturn($version);
			self::assertSame($supported, (new MailAttachmentProbe($apps))->supports('mail.attachments'), $version);
		}
	}

	/** The save operation is a separate grant, denied on a fresh install. @return void */
	public function testSaveIsBornDenied(): void {
		self::assertSame('save', SaveAttachment::definition()->operation);
		self::assertFalse(GrantPolicy::defaultGranted('mail', 'save'));
	}
}
