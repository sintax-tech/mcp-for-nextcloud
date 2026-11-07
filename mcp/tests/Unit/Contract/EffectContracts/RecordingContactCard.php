<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;

/** Observes real card construction without changing its UID or bytes; only the transcript receives placeholders. */
final class RecordingContactCard extends ContactCard {
	/** @var array<string, string> actual generated UID => placeholder, in first-observed order */
	public array $literals = [];

	/** @param TestCase $test owner of shape and identity assertions */
	public function __construct(private TestCase $test) {
	}

	/**
	 * @param array<string, mixed> $arguments fields supplied to the real builder
	 * @param string $uid UID chosen or recovered by ContactCreatePlan
	 * @return VCard unchanged real card
	 */
	public function create(array $arguments, string $uid): VCard {
		$card = parent::create($arguments, $uid);
		$this->test->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $uid);
		$this->test->assertSame($uid, (string)$card->UID);
		$this->literals[$uid] ??= '<contact-uid#' . (count($this->literals) + 1) . '>';
		return $card;
	}
}
