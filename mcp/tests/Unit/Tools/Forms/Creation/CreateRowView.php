<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms\Creation;

/** Minimal native mapper row shape for stateful writer tests, not a production entity. */
final class CreateRowView {
	/** Receives a synthetic native row.
	 * @param array<string,mixed> $data Field values.
	 */
	public function __construct(private array $data) {
	}
	/** Returns exact synthetic fields.
	 * @return array<string,mixed> Row data.
	 */
	public function read(): array { return $this->data; }
	/** Returns native-style row identity.
	 * @return int Fresh row ID.
	 */
	public function getId(): int { return $this->data['id']; }
	/** Returns the parent form identity for a question.
	 * @return int Form ID.
	 */
	public function getFormId(): int { return $this->data['formId']; }
	/** Returns the parent question identity for an option.
	 * @return int Question ID.
	 */
	public function getQuestionId(): int { return $this->data['questionId']; }
}
