<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\EffectContracts\TalkWritesWiring;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Frozen before RE-20: native effects, raw answers, rendered plans, malformed arguments and sanitized logs. */
final class TalkCharacterizationTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../../../fixtures/talk-characterization.json';

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	#[DataProvider('locales')]
	public function testTalkAnswersPlansEffectsAndLogsStayIdentical(string $locale): void {
		$recorded = [];
		foreach ([$locale] as $locale) {
			Translator::use(new JsonL10n($locale));
			foreach (self::steps() as $case => [$tool, $arguments]) {
				foreach (in_array($tool, ['talk_list_conversations', 'talk_read_messages'], true) ? ['call'] : ['preview', 'call'] as $kind) {
					$gate = new EffectsGate($this);
					$wiring = new TalkWritesWiring($this, $gate);
					if ($tool === 'talk_quote_file') { $wiring->seedAttachment(); }
					$module = $wiring->module();
					$recorded[$locale]['definitions'] = $module->definitions();
					try {
						$result = $module->$kind($tool, $arguments, 'alice');
						if ($kind === 'preview') { $result = ['plan' => $result, 'text' => $module->renderPlan($tool, $result)]; }
					} catch (Throwable $error) {
						$result = ['failure' => $error::class, 'message' => $error->getMessage(), 'code' => $error->getCode()];
					}
					$recorded[$locale][$case][$kind] = ['result' => $result, 'state' => $wiring->state(), 'logs' => $wiring->logged];
				}
			}
		}
		Translator::reset();
		// Only FQCN relocation is normalized; user paths, ids, text, call order and native state remain literal.
		array_walk_recursive($recorded, static function (&$value): void {
			if (is_string($value)) {
				$value = preg_replace('/OCA\\\\Mcp\\\\(?:Tools|Modules)\\\\Talk\\\\(?:[A-Za-z]+\\\\)*([A-Za-z]+)/', 'Talk/$1', $value);
			}
		});
		$json = json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('MCP_TALK_CHARACTERIZE_WRITE') === '1') {
			$all = is_file(self::FIXTURE) ? json_decode((string)file_get_contents(self::FIXTURE), true) : [];
			$all[$locale] = json_decode($json, true)[$locale];
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame($expected[$locale], json_decode($json, true)[$locale]);
	}

	/** @return array<string,array{string}> locale per isolated process keeps mock serialization bounded */
	public static function locales(): array {
		return ['en' => ['en'], 'pt_BR' => ['pt_BR'], 'es' => ['es'], 'de' => ['de']];
	}

	/** @return array<string,array{string,array}> requests frozen against the original module */
	private static function steps(): array {
		$room = ['conversation_token' => 'abcd1234'];
		$cases = [
			'list' => ['talk_list_conversations', []],
			'read' => ['talk_read_messages', $room],
			'read-invalid-limit' => ['talk_read_messages', $room + ['limit' => 0]],
			'reply' => ['talk_reply', $room + ['message' => " Hello\n**world** "]],
			'attach' => ['talk_attach_file', $room + ['path' => '/report.pdf', 'message' => 'Caption']],
			'attach-no-caption' => ['talk_attach_file', $room + ['path' => '/report.pdf']],
			'quote' => ['talk_quote_file', $room + ['attachment_id' => 15, 'message' => 'See report']],
			'direct' => ['talk_message_user', ['user' => 'pedro', 'message' => 'Hello']],
			'group' => ['talk_create_group', ['name' => 'Team **Alfa**', 'participants' => ['pedro']]],
			'group-empty' => ['talk_create_group', ['name' => 'Empty']],
			'batch' => ['talk_send_batch', $room + ['messages' => [['message' => 'First'], ['message' => 'Second']]]],
			'batch-partial' => ['talk_send_batch', $room + ['messages' => [['message' => 'First'], ['message' => ' ']]]],
			'blank' => ['talk_reply', $room + ['message' => ' ']],
			'traversal' => ['talk_attach_file', $room + ['path' => '../report.pdf']],
			'missing-room' => ['talk_reply', ['conversation_token' => 'none', 'message' => 'Hello']],
			'missing-user' => ['talk_message_user', ['user' => 'nobody', 'message' => 'Hello']],
			'malformed-reference' => ['talk_reply', $room + ['message' => 'Hello', 'reference' => 'wrong']],
		];
		// Two invalid inputs expose the intentionally different evaluation order of call versus preview.
		foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $tool) {
			$cases[$tool . '-missing-arguments'] = [$tool, []];
			$cases[$tool . '-invalid-room'] = [$tool, ['conversation_token' => 'INVALID']];
		}
		return $cases;
	}
}
