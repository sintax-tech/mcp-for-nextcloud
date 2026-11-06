<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Formats creation plan text. */
final class CreationPlanText {
	/**
	 * The plan of files_upload: the new file, where it goes, how long the link lasts and what it may weigh.
	 *
	 * @return list<string>|null
	 	 * @param array<string, mixed> $plan plan
	 */
	public static function upload(array $plan): ?array {
		$path = PlanFormatting::text($plan, 'path');
		if ($path === null) {
			return null;
		}
		$lines = [Translator::t('Create the new file %s with the content you send.', [PlanFormatting::bold($path)]), ''];
		$lines = array_merge($lines, self::folderLine($plan));
		if (isset($plan['size'])) {
			$lines[] = '- ' . Translator::t('Size: %s', [PlanFormatting::bytes((int)$plan['size'])]);
		}
		if (isset($plan['uploadTtlSeconds'])) {
			$lines[] = '- ' . Translator::t('The upload link works once, for %s minutes.', [(int)ceil((int)$plan['uploadTtlSeconds'] / 60)]);
		}
		if (isset($plan['uploadMaxBytes'])) {
			$lines[] = '- ' . Translator::t('The file can weigh up to %s.', [PlanFormatting::bytes((int)$plan['uploadMaxBytes'])]);
		}
		$lines[] = '- ' . \OCA\Mcp\Modules\Files\Presentation\CreationMessages::planNewFileConsequence();
		return array_merge($lines, PlanConsequences::sharing($plan));
	}

	/**
	 * The plan of files_create: the new text file, where it goes, its size and the beginning of its text as a quote.
	 *
	 * @return list<string>|null
	 	 * @param array<string, mixed> $plan plan
	 */
	public static function create(array $plan): ?array {
		$path = PlanFormatting::text($plan, 'path');
		if ($path === null) {
			return null;
		}
		$lines = [Translator::t('Create the text file %s.', [PlanFormatting::bold($path)]), ''];
		$lines = array_merge($lines, self::folderLine($plan));
		if (isset($plan['size'])) {
			$lines[] = '- ' . Translator::t('Size: %s', [PlanFormatting::bytes((int)$plan['size'])]);
		}
		$lines[] = '- ' . \OCA\Mcp\Modules\Files\Presentation\CreationMessages::planNewFileConsequence();
		$lines = array_merge($lines, PlanConsequences::sharing($plan));
		$preview = is_string($plan['preview'] ?? null) ? PlanText::quote($plan['preview'], \OCA\Mcp\Modules\Files\FileCreation::PREVIEW_CHARS) : '';
		if ($preview === '') {
			return array_merge($lines, ['', Translator::t('The file is empty.')]);
		}
		$cut = ($plan['previewTruncated'] ?? false) === true;
		$lines[] = '';
		$lines[] = $cut ? Translator::t('Beginning of the content:') : Translator::t('Content:');
		$lines[] = $preview . ($cut && !str_ends_with($preview, '…') ? '…' : '');
		return $lines;
	}

	/** @return list<string> the folder that receives a new file, when the plan names it @param array<string, mixed> $plan plan */
	private static function folderLine(array $plan): array {
		$folder = PlanFormatting::text($plan, 'folder');
		return $folder === null ? [] : ['- ' . Translator::t('Inside: %s', [PlanFormatting::bold($folder)])];
	}

}
