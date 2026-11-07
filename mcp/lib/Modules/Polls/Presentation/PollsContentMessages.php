<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

/** Fixed descriptions/warnings contain no native credentials, audience counts or exception messages. */
final class PollsContentMessages {
	/** Votes are native own/all, not a guarantee of an atomic complete snapshot. */
	public const VOTES = 'Read authorized native votes. scope describes observed native permission (own or all), not an atomic completeness guarantee. Anonymous identities are reduced within each poll; no identity lookup is performed.';
	/** Native aggregates cannot be fabricated from masked zeros or own votes. */
	public const RESULTS = 'Read native option aggregates only when native results are visible. Hidden results return options:null, never fabricated zeros or totals from own votes. Native fetch-all and post-fetch output limits apply.';
	/** This warning is fixed regardless of how many shares were omitted. */
	public const LINKS = 'Read existing direct participation links only with native edit permission. Links may contain personal credentials or identifiers; disclose only to authorized people. Retrieval creates or sends nothing. Inherited links without URLs and resolvable containers are not covered; opening a link later can have native effects.';
	/** Honest cost/concurrency boundary for all three read tools. */
	public const COST = 'Native services fetch complete collections before output pagination, and vote serialization may resolve users internally. There is no pre-allocation memory cap or transactional snapshot; an unobserved policy ABA can yield an incomplete all-scope collection.';
}
