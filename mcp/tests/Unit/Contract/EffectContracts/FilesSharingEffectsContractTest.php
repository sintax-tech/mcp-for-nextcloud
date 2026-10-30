<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCP\Constants;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Files sharing writes (RE-01) besides the share with a user: files_unshare of a user share
 * (files.share) and files_share creating a public link (files.link), frozen through the endpoint over a fake file tree
 * and a fake share store ({@see FilesContractWiring}), and the re-check of the concrete type grant both tools make after
 * the registry let them in with either grant.
 *
 * The flows name their target through a closure: once the confirmed step consumed the first file (its share removed, its
 * link created), the refusal steps that follow are asked against a sibling where the same call would still write.
 */
final class FilesSharingEffectsContractTest extends TestCase {
	/** Files alice owns, as she sees them; each flow consumes them in this order. */
	private const FILES = ['/Documentos/ata.md', '/Documentos/pauta.md', '/Documentos/resumo.md'];

	/** alice's files */
	private FakeTree $tree;
	/** the share store behind IShareManager */
	private FakeShares $shares;
	/** the endpoint */
	private EffectsGate $gate;
	/** the Files module of each request and its hidden tag */
	private FilesContractWiring $wiring;
	/** @var list<array<string, mixed>> transcript of the grant re-check test */
	private array $steps = [];

	/** @return void the tree, the share store and the endpoint serving the Files module */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		foreach (self::FILES as $path) {
			$this->tree->addFile('/alice/files' . $path, "# Ata\nolá", 'text/markdown');
		}
		$this->shares = new FakeShares($this);
		$this->gate = new EffectsGate($this);
		$this->wiring = new FilesContractWiring($this, $this->gate, $this->tree, $this->shares);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void files_unshare of a user share: plan, strict confirm, PlanChanged, removal and every refusal */
	public function testUnshareOfAUserShare(): void {
		// files_unshare is admitted by share OR link; alice holds share only, so revoking share removes the tool.
		$this->gate->grant('files', 'link', false);
		foreach (self::FILES as $path) {
			$this->shares->add(['node' => $this->idOf($path), 'with' => 'bruno', 'nodeObject' => $this->tree->node('/alice/files' . $path)]);
		}
		$flow = (new CriticalFlow('unshare-user', 'files_unshare', 'files', 'share',
			fn (): array => ['path' => $this->target(IShare::TYPE_USER), 'with' => 'user:bruno'], fn (): array => $this->state()))
			// The web interface gives bruno edit rights: the share the plan showed is no longer the one to remove.
			->changing(fn () => $this->shareOf($this->target(IShare::TYPE_USER), IShare::TYPE_USER)
				?->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE))
			->hiding(fn () => $this->wiring->hidden = [$this->idOf($this->target(IShare::TYPE_USER))], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		self::assertSame([['delete', '1'], ['delete', '2']], $this->writes(), 'the confirmed call and the guest confirmation removed one share each');
		self::assertSame([$this->idOf(self::FILES[2])], array_map(static fn (IShare $share): int => $share->getNodeId(), $this->shares->shares),
			'the share of the hidden file stays');
	}

	/** @return void files_share creating a public link: plan, strict confirm, PlanChanged, link created, every refusal */
	public function testCreationOfAPublicLink(): void {
		// files_share is admitted by share OR link; alice holds link only, so revoking link removes the tool.
		$this->gate->grant('files', 'share', false);
		$flow = (new CriticalFlow('share-link', 'files_share', 'files', 'link',
			fn (): array => ['path' => $this->target(IShare::TYPE_LINK, false), 'with' => 'link'], fn (): array => $this->state()))
			// The administrator sets a default validity for links: the link the plan showed would no longer be the one created.
			->changing(fn () => $this->shares->settings['shareApiLinkDefaultExpireDate'] = true)
			->hiding(fn () => $this->wiring->hidden = [$this->idOf($this->target(IShare::TYPE_LINK, false))], fn () => $this->wiring->hidden = []);
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);
		$links = $this->shares->shares;
		self::assertSame([$this->idOf(self::FILES[0]), $this->idOf(self::FILES[1])], array_map(static fn (IShare $s): int => $s->getNodeId(), $links));
		self::assertSame([IShare::TYPE_LINK, IShare::TYPE_LINK], array_map(static fn (IShare $s): int => $s->getShareType(), $links));
		self::assertSame(Constants::PERMISSION_READ, $links[0]->getPermissions(), 'a public link is read only');
		self::assertSame('tok1', $links[0]->getToken());
	}

	/**
	 * Both tools enter with share OR link; the type of the share decides which grant the call needs, checked again on the
	 * confirmed call. A confirmation valid a moment before, with the other grant only, writes nothing.
	 *
	 * @return void
	 */
	public function testTheConcreteShareTypeNeedsItsOwnGrant(): void {
		$link = $this->shares->add(['type' => IShare::TYPE_LINK, 'with' => null, 'node' => $this->idOf(self::FILES[1]),
			'nodeObject' => $this->tree->node('/alice/files' . self::FILES[1]), 'token' => 'tokantigo']);
		$create = ['path' => self::FILES[0], 'with' => 'link'];
		$remove = ['path' => self::FILES[1], 'with' => 'link'];
		$createPlan = $this->record('link-plan-with-both-grants', 'files_share', $create);
		$removePlan = $this->record('unshare-link-plan-with-both-grants', 'files_unshare', $remove);
		$this->gate->grant('files', 'link', false);
		$answers = [
			$this->record('link-plan-with-share-only', 'files_share', $create),
			$this->record('link-confirmed-with-share-only', 'files_share', $create + ['confirm' => true, 'plan_state' => $this->stateOf($createPlan)]),
			$this->record('unshare-link-confirmed-with-share-only', 'files_unshare',
				$remove + ['confirm' => true, 'plan_state' => $this->stateOf($removePlan)]),
		];
		foreach ($answers as $answer) {
			self::assertTrue($answer->result->isError ?? false, json_encode($answer));
			self::assertSame(FilesMessages::shareNotGranted(), $answer->result->content[0]->text);
		}
		self::assertSame([], $this->shares->writes, 'no link was created or removed with the share grant alone');
		self::assertSame([$link], $this->shares->shares);
		GoldenFile::assertMatches($this, __DIR__ . '/../effects/files.share-type-grant.json',
			VolatileFields::normalize(['tools' => ['files_share', 'files_unshare'], 'grantAnyOf' => ['share', 'link'], 'steps' => $this->steps]));
	}

	/**
	 * @param string $name step name
	 * @param string $tool tool called
	 * @param array<string, mixed> $arguments its arguments
	 * @return mixed the JSON-RPC body, after recording request, answer and share state
	 */
	private function record(string $name, string $tool, array $arguments): mixed {
		$response = $this->gate->call($tool, $arguments);
		$this->steps[] = ['step' => $name, 'tool' => $tool, 'arguments' => $arguments, 'response' => $response, 'state' => $this->state()];
		return $response['body'];
	}

	/**
	 * @param mixed $plan JSON-RPC body of a plan
	 * @return string its plan_state
	 */
	private function stateOf(mixed $plan): string {
		$state = $plan->result->structuredContent->plan_state ?? null;
		self::assertIsString($state, json_encode($plan));
		return $state;
	}

	/**
	 * @param int $type share type the flow works on
	 * @param bool $having true: the first file that has such a share (to remove); false: the first that has none (to create)
	 * @return string path of that file as alice sees it; the last file when none qualifies
	 */
	private function target(int $type, bool $having = true): string {
		foreach (self::FILES as $path) {
			if (($this->shareOf($path, $type) !== null) === $having) {
				return $path;
			}
		}
		return self::FILES[array_key_last(self::FILES)];
	}

	/**
	 * @param string $path file as alice sees it
	 * @param int $type share type
	 * @return IShare|null the share of that type alice holds on the file
	 */
	private function shareOf(string $path, int $type): ?IShare {
		foreach ($this->shares->shares as $share) {
			if ($share->getNodeId() === $this->idOf($path) && $share->getShareType() === $type) {
				return $share;
			}
		}
		return null;
	}

	/**
	 * @param string $path file as alice sees it
	 * @return int its id
	 */
	private function idOf(string $path): int {
		return $this->tree->nodes['/alice/files' . $path]['id'];
	}

	/** @return list<array{0:string, 1:string}> the share writes so far: kind and share id */
	private function writes(): array {
		return array_map(static fn (array $write): array => [$write[0], (string)$write[1]->getId()], $this->shares->writes);
	}

	/** @return array{shares: list<array<string, mixed>>, writes: list<array{0:string, 1:string}>} the stored shares and the write log */
	private function state(): array {
		return ['shares' => array_map(static function (IShare $share): array {
			$expires = $share->getExpirationDate();
			return ['id' => $share->getId(), 'node' => $share->getNodeId(), 'type' => $share->getShareType(), 'with' => $share->getSharedWith(),
				'by' => $share->getSharedBy(), 'owner' => $share->getShareOwner(), 'permissions' => $share->getPermissions(),
				'token' => $share->getToken(), 'hasPassword' => (string)$share->getPassword() !== '',
				'expires' => $expires instanceof \DateTimeInterface ? $expires->format('Y-m-d') : null, 'note' => $share->getNote()];
		}, $this->shares->shares), 'writes' => $this->writes()];
	}
}
