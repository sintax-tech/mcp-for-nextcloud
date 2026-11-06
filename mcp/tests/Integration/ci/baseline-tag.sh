#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Prints the workflow baseline unchanged unless the caller explicitly selects a locally available release tag.
set -eu
repo=$1
workflow_baseline=$2
if [ "${MCP_IT_BASELINE_TAG+x}" != x ]; then
	printf '%s\n' "$workflow_baseline"
	exit 0
fi
candidate=$MCP_IT_BASELINE_TAG
if ! printf '%s\n' "$candidate" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$'; then
	echo 'MCP_IT_BASELINE_TAG must have the form v<major>.<minor>.<patch>, optionally followed by -rc.<number>.' >&2
	exit 2
fi
if ! git -C "$repo" rev-parse --verify --quiet "refs/tags/$candidate" >/dev/null; then
	echo "MCP_IT_BASELINE_TAG names a release tag that is not available locally: $candidate" >&2
	exit 2
fi
printf '%s\n' "$candidate"
