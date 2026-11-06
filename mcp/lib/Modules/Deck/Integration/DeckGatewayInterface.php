<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

/** Preserves the full Deck gateway and the Talk reference port through composed query and write contracts. */
interface DeckGatewayInterface extends DeckGatewayReads, DeckGatewayWrites, DependencyNodeSource {
}
