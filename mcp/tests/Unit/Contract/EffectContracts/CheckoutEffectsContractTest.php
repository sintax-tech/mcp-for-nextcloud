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
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Modules\Files\Checkout\CheckoutService;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the local-edit checkout (RE-01): files_checkout frozen through the endpoint (the two links it
 * mints are its effect), then the three HTTP routes that spend them, frozen as a transcript of requests, answers and
 * effects (`effects/checkout.routes.json`). The tokens are random by nature: the store and the answers keep the real
 * values, and only the transcript replaces them with placeholders, after their shape is asserted.
 */
final class CheckoutEffectsContractTest extends TestCase {
	/** The file alice checks out, as she sees it. */
	private const PATH = '/Documentos/ata.md';
	/** Its absolute path in the fake tree. */
	private const NODE = '/alice/files/Documentos/ata.md';
	/** Its content before any upload. */
	private const ORIGINAL = "# Ata\nolá";
	/** Shape of a plain checkout token: prefix, kind initial, 32 random bytes as unpadded base64url. */
	private const TOKEN_SHAPE = '/^ncmcp_co_[du]_[A-Za-z0-9_-]{43}$/';
	/** Upload limit configured for the routes, small enough to cross. */
	private const LIMIT = 64;

	private FakeTree $tree;
	private EffectsGate $gate;
	private FilesFlowWiring $wiring;

	/** @return void builds the tree, the endpoint serving the Files module and the policy the routes re-read */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFile(self::NODE, self::ORIGINAL, 'text/markdown');
		$this->gate = new EffectsGate($this);
		$this->wiring = new FilesFlowWiring($this, $this->gate, $this->tree);
		$this->gate->data()->app['mcp'][CheckoutService::MAX_BYTES_KEY] = (string)self::LIMIT;
		// A link re-reads the connection of its owner, which the endpoint of the gate never asks.
		$this->gate->policy()->setGlobalEnabled(true);
		$this->gate->policy()->setEligible(EffectsGate::USER, true);
		$this->gate->policy()->setConnected(EffectsGate::USER, true);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void files_checkout: a plan that mints nothing, strict confirm, two links stored, every refusal */
	public function testCheckoutMintsTwoSingleUseLinks(): void {
		$file = $this->tree->nodes[self::NODE]['id'];
		$flow = (new CriticalFlow('checkout', 'files_checkout', 'files', 'edit',
			fn (): array => ['path' => self::PATH, 'etag' => $this->tree->nodes[self::NODE]['etag']],
			fn (): array => ['tokens' => FilesEffectState::tokens($this->wiring->tokens), 'issued' => count($this->wiring->issued),
				'tree' => FilesEffectState::tree($this->tree)]))
			->withoutPlanChanged('files_checkout carries no plan_state: a file changed after the plan is refused on the '
				. 'ETag the caller sends, never answered with a new plan')
			->hiding(fn () => $this->wiring->hidden = [$file], fn () => $this->wiring->hidden = [])
			->normalizing(fn (): array => $this->placeholders());
		(new FlowRunner($this, $this->gate, 'files'))->run($flow);

		// The confirmed and the guest checkout each stored one download and one upload link; nothing else was written.
		$rows = FilesEffectState::tokens($this->wiring->tokens);
		$this->assertSame(['download', 'upload', 'download', 'upload'], array_column($rows, 'kind'));
		$this->assertSame([FilesFlowWiring::NOW + CheckoutService::DOWNLOAD_TTL, FilesFlowWiring::NOW + CheckoutService::UPLOAD_TTL],
			array_slice(array_column($rows, 'expires_at'), 0, 2));
		$this->assertSame([$file], array_values(array_unique(array_column($rows, 'file_id'))));
		$this->assertSame([null], array_values(array_unique(array_column($rows, 'used_at'))), 'no link is spent by minting');
		$this->assertCount(4, $this->wiring->issued);
		$this->assertSame([], $this->tree->ops, 'a checkout writes no file');
	}

	/** @return void the download and upload routes: valid, spent, wrong kind, bad body, expired, hidden, revoked, policy down */
	public function testTheRoutesSpendTheLinksOnce(): void {
		$routes = new CheckoutRoutes($this, $this->wiring);
		$transcript = new EffectTranscript($this, 'checkout.routes');
		$transcript->record('route-table', 'appinfo/routes.php', CheckoutRoutes::table(__DIR__ . '/../../../../appinfo/routes.php'), null);
		[$download, $upload] = $this->checkout();
		$unknown = 'ncmcp_co_u_' . str_repeat('A', 43);
		$step = function (string $name, string $verb, string $token, string $body = '', array $headers = []) use ($routes, $transcript): array {
			$this->wiring->tokens->ops = [];
			$temp = $this->wiring->tempFiles;
			$answer = $routes->call($verb, $token, $body, $headers);
			$transcript->record($name, ['verb' => $verb, 'token' => $token, 'headers' => $headers, 'body' => $body], $answer,
				$this->routeState($this->wiring->tempFiles - $temp));
			return $answer;
		};

		$this->assertSame(200, $step('download', 'GET', $download)['status']);
		$this->assertSame(410, $step('download-spent', 'GET', $download)['status']);
		$this->assertSame(404, $step('download-with-upload-link', 'GET', $upload)['status']);
		$this->assertSame(404, $step('upload-with-download-link', 'PUT', $download, 'novo')['status']);
		$this->assertSame(404, $step('upload-unknown-link', 'PUT', $unknown, 'novo')['status']);
		$this->assertSame(400, $step('upload-multipart', 'PUT', $upload, 'novo', ['Content-Type' => 'multipart/form-data; boundary=x'])['status']);
		$this->assertSame(400, $step('upload-empty', 'PUT', $upload, '')['status']);
		$this->assertSame(413, $step('upload-too-large', 'PUT', $upload, str_repeat('x', self::LIMIT + 1))['status']);
		$this->wiring->now = FilesFlowWiring::NOW + CheckoutService::UPLOAD_TTL;
		$expired = $step('upload-expired', 'PUT', $upload, 'novo');
		$this->wiring->now = FilesFlowWiring::NOW;
		$this->assertSame(410, $expired['status']);
		$this->wiring->hidden = [$this->tree->nodes[self::NODE]['id']];
		$this->assertSame(404, $step('upload-hidden', 'PUT', $upload, 'novo')['status']);
		$this->wiring->hidden = [];
		$this->gate->grant('files', 'edit', false);
		$this->assertSame(403, $step('upload-grant-revoked', 'PUT', $upload, 'novo')['status']);
		$this->policyUnavailable();
		$this->assertSame(503, $step('upload-policy-unavailable', 'PUT', $upload, 'novo')['status']);
		$this->policyRestored();
		$this->assertSame([], $this->tree->ops, 'no refusal wrote anything');
		$this->assertFalse($this->wiring->tokens->ofKind('upload')->used, 'no refusal spent the upload link');

		$this->assertSame(200, $step('upload', 'PUT', $upload, "# Ata\nnova versão")['status']);
		$this->assertSame("# Ata\nnova versão", $this->tree->nodes[self::NODE]['content']);
		$this->assertSame(self::ORIGINAL, $this->tree->nodes['/alice/files/MCP backups/Documentos/ata.md.20260921-141320.bak']['content'] ?? null);
		$this->assertSame(410, $step('upload-spent', 'PUT', $upload, 'de novo')['status']);
		[, $second] = $this->checkout();
		$this->assertSame(200, $step('upload-post', 'POST', $second, "# Ata\nterceira")['status']);
		$this->assertSame("# Ata\nterceira", $this->tree->nodes[self::NODE]['content']);
		$transcript->assertGolden($this->placeholders());
	}

	/** @return array{0:string, 1:string} the download and upload tokens of a checkout confirmed through the endpoint */
	private function checkout(): array {
		$before = count($this->wiring->issued);
		$answer = $this->gate->call('files_checkout', ['path' => self::PATH, 'etag' => $this->tree->nodes[self::NODE]['etag'], 'confirm' => true]);
		$this->assertFalse($answer['body']->result->isError ?? false, json_encode($answer['body']));
		$this->assertCount($before + 2, $this->wiring->issued);
		return array_slice($this->wiring->issued, $before, 2);
	}

	/**
	 * @param int $tempFiles request bodies copied to disk by this request
	 * @return array<string, mixed> the file, the links and what the request did to the store and the disk
	 */
	private function routeState(int $tempFiles): array {
		return ['tree' => FilesEffectState::tree($this->tree), 'tokens' => FilesEffectState::tokens($this->wiring->tokens),
			'storeOps' => $this->wiring->tokens->ops, 'tempFiles' => $tempFiles];
	}

	/** @return array<string, string> every issued token => a numbered placeholder, after checking its shape */
	private function placeholders(): array {
		$map = [];
		foreach ($this->wiring->issued as $n => $token) {
			$this->assertMatchesRegularExpression(self::TOKEN_SHAPE, $token);
			$map[$token] = '<checkout-token#' . ($n + 1) . '>';
		}
		$this->assertCount(count($this->wiring->issued), array_unique($this->wiring->issued), 'every token is drawn anew');
		return $map;
	}

	/** @return void the files.edit grant held only through a group whose policy is undetermined: the owner's policy cannot be read */
	private function policyUnavailable(): void {
		unset($this->gate->data()->user[EffectsGate::USER]['mcp']['grant_files_edit']);
		$this->gate->data()->groupBackends = [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['team' => [EffectsGate::USER]])];
		InMemoryConfig::groupPolicy($this->gate->config(), 'team', ['files' => ['edit']], 'needs_confirm');
	}

	/** @return void the individual grant back and the group gone */
	private function policyRestored(): void {
		$this->gate->data()->groupBackends = [];
		$this->gate->grant('files', 'edit', true);
	}
}
