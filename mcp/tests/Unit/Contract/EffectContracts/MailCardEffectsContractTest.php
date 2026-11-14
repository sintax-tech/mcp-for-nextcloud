<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Modules\Routines\RoutinesModule;
use OCA\Mcp\Modules\Routines\Tools\CreateMailCard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Modules\Routines\MailCardWorld;
use OCA\Mcp\Tests\Unit\Tools\Mail\{FakeMimePart, MailTestModule};
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Container\ContainerInterface;

/**
 * routines_create_mail_card through the real endpoint and the real Mail, Files and Deck ports over their doubles. The
 * observable effect is what Files and Deck hold: the files of the destination folder with the hash of their bytes, and
 * the cards created. The plan and every refusal leave both unchanged; a change of the email after the plan answers
 * the new plan; the confirmed call saves the attachment, then creates the card that references it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MailCardEffectsContractTest extends MailCardWorld {
	private EffectsGate $gate;

	/** The gate first, so the ports read its policy and Guests; then the endpoint serving the routines. @return void */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		parent::setUp();
		$apps = $this->createMock(\OCP\App\IAppManager::class);
		$apps->method('getAppVersion')->willReturn('5.10.11');
		$this->gate->serve(function (): array {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(fn (string $id): object => $id === CreateMailCard::class ? $this->tool
				: throw new \LogicException('Unexpected service ' . $id));
			return [new RoutinesModule($container)];
		}, static fn () => MailTestModule::capabilities($apps));
	}

	/** {@inheritDoc} */
	protected function grants(): \OCA\Mcp\Access\Grants\GrantPolicy {
		return $this->gate->policy();
	}

	/** {@inheritDoc} */
	protected function access(\OCP\App\IAppManager $apps, \OCP\IUserManager $users, \OCA\Mcp\Access\Guests\GuestRestrictions $guests): \OCA\Mcp\Mcp\Tools\Discovery\ToolAccess {
		return $this->gate->access(MailTestModule::capabilities($apps));
	}

	/** @return void the mail card: plan without effect, strict confirm, a changed email, both effects in order and every refusal */
	public function testCreatingAMailCard(): void {
		$flow = (new CriticalFlow('create-mail-card', 'routines_create_mail_card', 'routines', 'mailtask',
			// One new name per card already created, so every refusal step still plans a free name.
			fn (): array => self::arguments(['path' => sprintf('/Documentos/pedido-%d.pdf', count($this->created)), 'excerpt_chars' => 5]),
			fn (): array => $this->state()))
			->changing(function (): void {
				$this->mail->withMessage(1, ['id' => 7, 'subject' => 'Pedido 42 (corrigido)', 'size' => 2000, 'threadRootId' => 'root-7', 'body' => 'Segue o pedido.',
					'structure' => FakeMimePart::multipart('multipart/mixed', [new FakeMimePart('text/plain'), new FakeMimePart('application/pdf', 'pedido.pdf', 'attachment')]),
					'attachmentContents' => ['2' => self::BYTES], 'attachmentList' => [['id' => '2', 'fileName' => 'pedido.pdf', 'mime' => 'application/pdf', 'size' => strlen(self::BYTES)]]]);
			})
			->hiding(function (): void { $this->items = [(string)$this->tree->nodes['/alice/files/Outros']['id']]; }, function (): void { $this->items = null; });
		(new FlowRunner($this, $this->gate, 'routines'))->run($flow);

		[$card] = $this->created;
		$this->assertSame(self::BYTES, $this->tree->nodes['/alice/files/Documentos/pedido-0.pdf']['content']);
		$this->assertStringContainsString('Pedido 42 (corrigido)', $card[2]);
		$this->assertStringContainsString('https://cloud.example/f/' . $this->tree->nodes['/alice/files/Documentos/pedido-0.pdf']['id'], $card[2]);
	}

	/** @return array{files: array<string, string>, cards: list<array{int, string, string}>} the files of the destination folder by path with the hash of their bytes, and the cards created */
	private function state(): array {
		$files = [];
		foreach ($this->tree->nodes as $path => $node) {
			if (str_starts_with($path, '/alice/files/Documentos/') && ($node['type'] ?? '') !== 'dir') {
				$files[$path] = hash('sha256', (string)($node['content'] ?? ''));
			}
		}
		ksort($files);
		return ['files' => $files, 'cards' => $this->created];
	}
}
