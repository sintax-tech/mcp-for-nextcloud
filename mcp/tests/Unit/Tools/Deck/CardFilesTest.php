<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Attachments\CardFiles; use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Attachments\CardFileHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Files\{File, Folder, IRootFolder};
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/** The real file/receipt/handler pipeline with controlled native boundaries, not a mock of its decisions. */
final class CardFilesTest extends TestCase {
	use DeckTestHelpers;
	private DeckGatewayInterface $gateway;
	private FilesAccessGuard $guard;
	private CardFiles $files;
	private IShare $share;
	private bool $present = true;
	private bool $visible = true;
	private bool $granted = true;
	private bool $readable = true;
	private bool $observationFails = false;
	private string $etag = 'version-one';
	private File $node;
	private ?Folder $unsupportedFolder = null;

	protected function setUp(): void {
		$this->gateway = $this->createMock(DeckGatewayInterface::class);
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('within')->willReturnCallback(static fn ($uid, $work) => $work());
		$policy->method('granted')->willReturnCallback(fn ($uid, $module, $op): bool => $module !== 'files' || ($op === 'read' && $this->granted));
		$this->guard = $this->createMock(FilesAccessGuard::class);
		$this->guard->method('isVisible')->willReturnCallback(fn (): bool => $this->visible);
		$this->share = $this->createMock(IShare::class);
		$this->share->method('getId')->willReturn('17');
		$this->share->method('getShareType')->willReturn(IShare::TYPE_DECK);
		$this->share->method('getSharedWith')->willReturn('7');
		$this->share->method('getNodeId')->willReturn(99);
		$this->share->method('getSharedBy')->willReturn('third-party');
		$this->share->method('getShareTime')->willReturn(new \DateTime('@100'));
		$this->node = $this->createMock(File::class);
		$this->node->method('getId')->willReturn(99);
		$this->node->method('getPath')->willReturn('/alice/files/visible.txt');
		$this->node->method('getName')->willReturn('visible.txt');
		$this->node->method('getSize')->willReturn(12);
		$this->node->method('getEtag')->willReturnCallback(fn (): string => $this->etag);
		$this->node->method('isReadable')->willReturnCallback(fn (): bool => $this->readable);
		foreach (['delete', 'move', 'copy'] as $method) {
			$this->node->expects(self::never())->method($method);
		}
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(fn ($id): array => $id === 99 ? [$this->unsupportedFolder ?? $this->node] : []);
		$folder->method('getRelativePath')->willReturn('/visible.txt');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$this->files = new CardFiles($this->gateway, $policy, $this->guard, $root);
		$this->gateway->method('cardFileContext')->willReturnCallback(fn ($uid, $cardId): array =>
			['card' => $this->card(['id' => $cardId, 'title' => 'Source']), 'boardId' => 20, 'board' => $this->ownershipOf('alice')]);
		$this->gateway->method('cardFileShare')->willReturnCallback(fn ($uid, $cardId, $id): ?IShare => $id === 17 && $this->present ? $this->share : null);
		$this->gateway->method('cardFileShareExists')->willReturnCallback(function (): bool {
			if ($this->observationFails) {
				throw new \RuntimeException('observation unavailable');
			}
			return $this->present;
		});
		$this->gateway->method('cardFileShares')->willReturnCallback(fn (): array => $this->present ? [$this->share] : []);
	}

	private function handler(bool $unlink = true): CardFileHandler {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getSystemValueString')->willReturn('unit-secret');
		return new CardFileHandler($this->gateway, $this->createMock(LoggerInterface::class), fn () => $this->files,
			$this->cardFormatter(), fn () => new PlanState($config), $unlink);
	}

	private function confirmed(CardFileHandler $handler): array {
		$args = ['cardId' => 7, 'attachmentId' => 17];
		$plan = $handler->preview($args, 'alice');
		return $args + ['confirm' => true, 'plan_state' => $plan['plan_state'], 'file_state' => $plan['file_state']];
	}

	public function testListingFiltersBeforeAnyMetadataAndNeverUsesNativeAttachmentCount(): void {
		self::assertSame(99, $this->files->list('alice', 7)['items'][0]['fileId']);
		self::assertSame(17, $this->files->list('alice', 7)['items'][0]['attachmentId']);
		$this->visible = false;
		self::assertSame(['items' => []], $this->files->list('alice', 7));
		$this->present = false;
		self::assertSame(['items' => []], $this->files->list('alice', 7));
	}

	public function testPreviewAndConfirmedUnlinkPreserveOriginalAndNeedNoShareGrant(): void {
		$this->gateway->expects(self::once())->method('unlinkCardFile')->with('alice', 7, 17, [
			'attachmentId' => 17, 'cardId' => 7, 'fileId' => 99, 'createdBy' => 'third-party', 'createdAt' => 100])
			->willReturnCallback(function (): void { $this->present = false; });
		$h = $this->handler();
		$args = $this->confirmed($h);
		self::assertTrue($this->present, 'Preview never mutates');
		$result = $this->payload($h->handle($args, 'alice'));
		self::assertTrue($result['confirmed']);
		self::assertTrue($result['unlinked']);
		self::assertArrayNotHasKey('file', $result, 'No old metadata after losing a last mount');
		self::assertFalse($this->payload($h->handle($args, 'alice'))['changed'], 'Original signed receipt permits a visible-file no-op');
	}

	public function testAlteredReceiptReplayStaleStateAndRevokedVisibilityHaveTheSameMissingRefusal(): void {
		$h = $this->handler();
		$args = $this->confirmed($h);
		$this->gateway->expects(self::never())->method('unlinkCardFile');
		$errors = [];
		foreach (['fileId' => 1000, 'createdBy' => 'forged', 'createdAt' => 101] as $field => $value) {
			$bad = $args;
			$bad['file_state'][$field] = $value;
			$errors[] = $h->handle($bad, 'alice');
		}
		$errors[] = $h->handle($args, 'bob');
		$bad = $args;
		$bad['cardId'] = 8;
		$errors[] = $h->handle($bad, 'alice');
		$this->etag = 'changed';
		$errors[] = $h->handle($args, 'alice');
		$this->etag = 'version-one';
		$this->visible = false;
		$errors[] = $h->handle($args, 'alice');
		$this->visible = true;
		$this->present = false;
		$bad = $args;
		$bad['file_state']['createdBy'] = 'forged';
		$errors[] = $h->handle($bad, 'alice');
		$bad = $args;
		$bad['attachmentId'] = 999;
		$errors[] = $h->handle($bad, 'alice');
		foreach ($errors as $error) {
			self::assertTrue($error['isError']);
			self::assertSame($errors[0], $error);
			self::assertStringNotContainsString('visible.txt', json_encode($error));
		}
	}

	public function testGrantAndNativeNodeReadRevocationBlockBothListingAndUnlink(): void {
		$h = $this->handler();
		$args = $this->confirmed($h);
		$this->gateway->expects(self::never())->method('unlinkCardFile');
		$this->granted = false;
		self::assertTrue($h->handle($args, 'alice')['isError']);
		self::assertTrue($this->handler(false)->handle(['cardId' => 7], 'alice')['isError']);
		$this->granted = true;
		$this->readable = false;
		self::assertTrue($h->handle($args, 'alice')['isError']);
		self::assertSame(['items' => []], $this->files->list('alice', 7));
	}

	public function testResponseLostAfterPersistenceIsObservedWithoutRepeating(): void {
		$h = $this->handler();
		$args = $this->confirmed($h);
		$this->gateway->expects(self::once())->method('unlinkCardFile')->willReturnCallback(function (): void {
			$this->present = false;
			throw new \RuntimeException('lost response');
		});
		$r = $this->payload($h->handle($args, 'alice'));
		self::assertTrue($r['confirmed']);
		self::assertSame('observed-state', $r['verification']);
		self::assertStringContainsString('Another actor', $r['warnings'][0]);
	}

	public function testUnknownReadBackNeverClaimsSuccessOrRetriesTheWrite(): void {
		$h = $this->handler();
		$args = $this->confirmed($h);
		$this->gateway->expects(self::once())->method('unlinkCardFile')->willReturnCallback(function (): void {
			$this->present = false;
			$this->observationFails = true;
			throw new \RuntimeException('lost response');
		});
		$r = $this->payload($h->handle($args, 'alice'));
		self::assertFalse($r['confirmed']);
		self::assertArrayNotHasKey('unlinked', $r);
		self::assertArrayNotHasKey('file', $r);
		self::assertStringNotContainsString('visible.txt', json_encode($r));
	}

	public function testCleanNativeFailureRemainsAnErrorWhenReadBackProvesNoChange(): void {
		$h = $this->handler();
		$args = $this->confirmed($h);
		$this->gateway->expects(self::once())->method('unlinkCardFile')
			->willThrowException(new DeckRefusalException('native refused'));
		$r = $h->handle($args, 'alice');
		self::assertTrue($r['isError']);
		self::assertTrue($this->present);
	}

	public function testFolderAndScopeFailureCannotExposeMetadataOrDeleteNodes(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects(self::never())->method('getName');
		$folder->expects(self::never())->method('delete');
		$this->unsupportedFolder = $folder;
		self::assertSame(['items' => []], $this->files->list('alice', 7));
		$this->gateway->expects(self::never())->method('unlinkCardFile');
		try {
			$this->handler()->preview(['cardId' => 7, 'attachmentId' => 17], 'alice');
			self::fail('Folder accepted');
		} catch (DeckRefusalException) {
			self::assertTrue(true);
		}
		$this->guard->method('assertScopeReadable')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable());
		$this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
		$this->handler(false)->handle(['cardId' => 7], 'alice');
	}

	public function testReceiptIsNotAcceptedOnPreview(): void {
		$h = $this->handler();
		$args = $this->confirmed($h);
		$this->expectException(\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException::class);
		$h->preview($args, 'alice');
	}
}
