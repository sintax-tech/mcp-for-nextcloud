<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Creation;

/** Plans one Forms write without running its effect. */
final class CreateFormPreview {
	/**
	 * @param CreateFormFlow $flow native admission and complete plan
	 */
	public function __construct(private CreateFormFlow $flow) {
	}

	/**
	 * @param array<string,mixed> $arguments validated request
	 * @param string $userId authenticated actor
	 * @return array<string,mixed> the complete inert plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->flow->preview($arguments, $userId);
	}
}
