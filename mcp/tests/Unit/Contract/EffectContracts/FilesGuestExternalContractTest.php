<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\GoldenFile;
use OCA\Mcp\Tests\Unit\Contract\Oracle\VolatileFields;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares;
use PHPUnit\Framework\TestCase;

/**
 * Guests always allows Files, but hides external storage from a guest while "allow external storage" is off
 * (GuestRestrictions::hidesExternalStorage). The Files guard applies it to every node the tool resolves, so a guest's
 * write into an external mount is refused even with a confirmation that a regular account just used, while the same
 * guest still writes in her own home (RE-01). Frozen in `effects/files.guest-external-storage.json`.
 */
final class FilesGuestExternalContractTest extends TestCase {
	/** alice's files */
	private FakeTree $tree;
	/** the endpoint */
	private EffectsGate $gate;
	/** the Files module of each request and its switches */
	private GuardedFilesWiring $wiring;
	/** @var list<array<string, mixed>> transcript of the test */
	private array $steps = [];

	/** @return void alice's home with an external mount, and the endpoint serving the Files module */
	protected function setUp(): void {
		$this->tree = new FakeTree($this);
		$this->tree->addFolder('/alice/files/Documentos');
		$this->tree->addFolder('/alice/files/Externo', ['scope' => 'external']);
		$this->gate = new EffectsGate($this);
		$this->wiring = new GuardedFilesWiring($this, $this->gate, fn () => $this->tree->rootFolder(), fn () => $this->tree->filesystemRoot(),
			(new FakeShares($this))->manager());
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void a guest's plan and confirmed write on external storage are refused and write nothing */
	public function testAGuestCannotWriteOnExternalStorage(): void {
		$first = $this->create('/Externo/primeiro.txt');
		$this->record('regular-confirmed', $this->confirmation($this->record('regular-plan', $first), $first));
		self::assertArrayHasKey('/alice/files/Externo/primeiro.txt', $this->tree->nodes, 'a regular account writes on external storage');
		$second = $this->create('/Externo/segundo.txt');
		$valid = $this->confirmation($this->gate->call('files_create', $second)['body'], $second);
		$personal = $this->create('/Documentos/proprio.txt');
		$this->gate->guest(['files']);
		$this->wiring->guestHidesExternal = true;
		try {
			$before = $this->tree->ops;
			$answers = [$this->record('guest-plan', $second), $this->record('guest-confirmed', $valid)];
			self::assertSame($before, $this->tree->ops, 'the guest wrote nothing on external storage');
			self::assertArrayNotHasKey('/alice/files/Externo/segundo.txt', $this->tree->nodes);
			$this->record('guest-own-home-confirmed', $this->confirmation($this->record('guest-own-home-plan', $personal), $personal));
		} finally {
			$this->gate->guest(null);
			$this->wiring->guestHidesExternal = false;
		}
		foreach ($answers as $answer) {
			self::assertTrue($answer->result->isError ?? false, json_encode($answer));
		}
		self::assertArrayHasKey('/alice/files/Documentos/proprio.txt', $this->tree->nodes, 'Guests allows Files in the guest\'s own home');
		GoldenFile::assertMatches($this, __DIR__ . '/../effects/files.guest-external-storage.json',
			VolatileFields::normalize(['tool' => 'files_create', 'guest' => ['apps' => ['files'], 'hidesExternalStorage' => true], 'steps' => $this->steps]));
	}

	/**
	 * @param string $path new file as alice sees it
	 * @return array<string, mixed> arguments of files_create for it; writing on external storage needs confirm_shared
	 */
	private function create(string $path): array {
		return ['path' => $path, 'content' => "linha\n", 'confirm_shared' => true];
	}

	/**
	 * @param mixed $plan JSON-RPC body of the plan
	 * @param array<string, mixed> $arguments plan arguments
	 * @return array<string, mixed> the confirmed call of that plan
	 */
	private function confirmation(mixed $plan, array $arguments): array {
		self::assertTrue($plan->result->structuredContent->requiresConfirmation ?? false, json_encode($plan));
		return $arguments + ['confirm' => true];
	}

	/**
	 * @param string $name step name
	 * @param array<string, mixed> $arguments files_create arguments
	 * @return mixed the JSON-RPC body, after recording request, answer and the files with the write log
	 */
	private function record(string $name, array $arguments): mixed {
		$response = $this->gate->call('files_create', $arguments);
		$files = array_keys(array_filter($this->tree->nodes, static fn (array $node): bool => $node['type'] === 'file'));
		$this->steps[] = ['step' => $name, 'arguments' => $arguments, 'response' => $response,
			'state' => ['files' => $files, 'ops' => $this->tree->ops]];
		return $response['body'];
	}
}
