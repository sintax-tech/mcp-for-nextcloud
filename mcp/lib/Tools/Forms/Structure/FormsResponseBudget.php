<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Structure;

use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Tools\Forms\FormsText;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Bounds an already authorized, whitelisted response after native fetch; never a native-fetch memory cap. */
final class FormsResponseBudget {
	/** Maximum returned characters of one user-text field. */
	public const FIELD_CHARS = 8000;
	/** Shared returned user-text character budget. */
	public const RESPONSE_CHARS = 100000;
	/** Maximum kept members of each flat extra-setting list, with an explicit cut report. */
	public const SETTING_ITEMS = 200;
	/** Maximum complete combined question/option count before whole-response refusal. */
	public const STRUCTURE_ITEMS = 2000;
	/** Maximum bytes of the pretty-printed tool JSON payload, after native fetch. */
	public const RESPONSE_BYTES = 1048576;

	/** @var int Characters of user text still available across this response. */
	private int $left = self::RESPONSE_CHARS;
	/** @var int Questions and options encountered across this response. */
	private int $items = 0;
	/** @var list<array{path:string,totalChars:int}> Explicitly shortened text fields, never their content. */
	private array $fields = [];
	/** @var list<array{path:string,totalItems:int}> Explicitly shortened extra-setting lists. */
	private array $lists = [];

	/** Applies one fresh budget per response without altering candidate entities or cursor digests.
	 * @param array<string,mixed> $data Fully authorized and whitelisted form or forms.
	 * @param array<string,mixed> $metadata Trusted pagination metadata, also counted in the serialized budget.
	 * @param string|null $payloadLimitMessage Handler-specific serialized payload refusal; structure keeps its own message.
	 * @return array<string,mixed> Untrusted envelope with explicit limits and cut locations.
	 * @throws ToolFailure When complete structure or serialized payload exceeds the response limit.
	 */
	public static function envelope(array $data, array $metadata = [], ?string $payloadLimitMessage = null): array {
		$budget = new self();
		if (isset($data['form'])) { $data['form'] = $budget->form($data['form'], 'form'); }
		foreach ($data['forms'] ?? [] as $index => $form) { $data['forms'][$index] = $budget->form($form, 'forms.' . $index); }
		$result = FormsText::envelope($data) + $metadata + [
			'limits' => ['fieldChars' => self::FIELD_CHARS, 'responseChars' => self::RESPONSE_CHARS,
				'settingItems' => self::SETTING_ITEMS, 'structureItems' => self::STRUCTURE_ITEMS, 'responseBytes' => self::RESPONSE_BYTES],
			'truncation' => ['fields' => $budget->fields, 'lists' => $budget->lists],
		];
		// Same JSON formatting as ToolResult::json; this is payload size, before the MCP transport wraps the text.
		if (strlen(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)) > self::RESPONSE_BYTES) {
			throw new ToolFailure($payloadLimitMessage ?? FormsMessages::responseLimit());
		}
		return $result;
	}

	/** Budgets only user text and flat settings; all identifiers, native permissions and links remain intact.
	 * @param array<string,mixed> $form Authorized form shape.
	 * @param string $path Fixed schema path, never an owner or recipient name.
	 * @return array<string,mixed> Same form and complete question/option membership, or refusal.
	 */
	private function form(array $form, string $path): array {
		foreach (['title', 'description', 'submissionMessage', 'confirmationEmailSubject', 'confirmationEmailBody'] as $key) {
			if (isset($form[$key])) { $form[$key] = $this->text($form[$key], $path . '.' . $key); }
		}
		foreach ($form['questions'] ?? [] as $index => $question) {
			$this->structureItem();
			$at = $path . '.questions.' . $index;
			foreach (['text', 'description'] as $key) {
				if (isset($question[$key])) { $question[$key] = $this->text($question[$key], $at . '.' . $key); }
			}
			foreach ($question['extraSettings'] ?? [] as $key => $value) {
				$settingPath = $at . '.extraSettings.' . $key;
				if (is_string($value)) { $value = $this->text($value, $settingPath); }
				elseif (is_array($value)) {
					if (count($value) > self::SETTING_ITEMS) { $this->lists[] = ['path' => $settingPath, 'totalItems' => count($value)]; }
					$value = array_slice($value, 0, self::SETTING_ITEMS);
					foreach ($value as $item => $text) { $value[$item] = $this->text($text, $settingPath . '.' . $item); }
				}
				$question['extraSettings'][$key] = $value;
			}
			foreach ($question['options'] ?? [] as $optionIndex => $option) {
				$this->structureItem();
				$option['text'] = $this->text($option['text'], $at . '.options.' . $optionIndex . '.text');
				$question['options'][$optionIndex] = $option;
			}
			$form['questions'][$index] = $question;
		}
		return $form;
	}

	/** Records every text cut with its original cleaned length, preserving UTF-8 and sharing the response budget.
	 * @param string $text Already cleaned untrusted text.
	 * @param string $path Schema location of that value.
	 * @return string Full or explicitly reported shortened text.
	 */
	private function text(string $text, string $path): string {
		$length = mb_strlen($text, 'UTF-8');
		$keep = min($length, self::FIELD_CHARS, $this->left);
		$this->left -= $keep;
		if ($keep === $length) { return $text; }
		$this->fields[] = ['path' => $path, 'totalChars' => $length];
		return mb_substr($text, 0, $keep, 'UTF-8');
	}

	/** Refuses the entire response rather than returning a silently incomplete question/option structure.
	 * @return void
	 * @throws ToolFailure When the post-fetch structure budget is exceeded.
	 */
	private function structureItem(): void {
		if (++$this->items > self::STRUCTURE_ITEMS) { throw new ToolFailure(FormsMessages::responseLimit()); }
	}
}
