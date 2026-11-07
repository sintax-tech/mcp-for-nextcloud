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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/** Every remaining Talk write tool, through the real module and native service boundaries. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TalkWritesEffectsContractTest extends TestCase {
	private EffectsGate $gate;
	private TalkWritesWiring $wiring;

	/** @return void one fixture and real module per request */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->wiring = new TalkWritesWiring($this, $this->gate);
		$this->gate->serve(fn (): array => [$this->wiring->module()]);
	}

	/** @return void the direct conversation is opened only on confirm and receives the message */
	public function testMessageToAUser(): void {
		$this->runFlow('message-user', 'talk_message_user', 'reply', ['user' => 'pedro', 'message' => 'Hello']);
		$this->assertCount(1, $this->wiring->creation->created);
		$this->assertSame('Hello', $this->wiring->chat->messages[0]['message']);
	}

	/** @return void every batch item reaches the native chat in order; dropping even one fails */
	public function testEveryBatchItemIsSent(): void {
		$this->runFlow('send-batch', 'talk_send_batch', 'reply', ['conversation_token' => 'abcd1234',
			'messages' => [['message' => 'First'], ['message' => 'Second']]]);
		$this->assertSame(['First', 'Second'], array_column($this->wiring->chat->messages, 'message'));
	}

	/** @return void the native group and each native invitation are observed */
	public function testCreatingAGroup(): void {
		$this->runFlow('create-group', 'talk_create_group', 'create', ['name' => 'Team', 'participants' => ['pedro']]);
		$this->assertCount(1, $this->wiring->creation->created);
		$this->assertCount(1, $this->wiring->creation->invited);
	}

	/** @return void quoting a visible existing room share creates a message, and a hidden node refuses */
	public function testQuotingAFile(): void {
		$this->wiring->seedAttachment();
		$this->runFlow('quote-file', 'talk_quote_file', 'quote', ['conversation_token' => 'abcd1234', 'attachment_id' => 15, 'message' => 'See report'], true);
		$this->assertNotEmpty($this->wiring->chat->messages);
	}

	/**
	 * @param string $name golden suffix
	 * @param string $tool name
	 * @param string $grant operation
	 * @param array $args arguments
	 * @param bool $file touches a file
	 * @return void
	 */
	private function runFlow(string $name, string $tool, string $grant, array $args, bool $file = false): void {
		$flow = (new CriticalFlow($name, $tool, 'talk', $grant, $args, fn (): array => $this->wiring->state()))
			->withoutPlanChanged('Talk does not sign a PlanState; it revalidates the conversation and arguments at execution.');
		if ($file) {
			$id = $this->wiring->files->tree->nodes['/alice/files/report.pdf']['id'];
			$flow->hiding(fn () => $this->wiring->files->hidden = [$id], fn () => $this->wiring->files->hidden = []);
		} else {
			$flow->withoutVisibility('Room membership and account reachability apply; this operation has no Files node.');
		}
		(new FlowRunner($this, $this->gate, 'talk'))->run($flow);
	}
}
