#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Resolves the official runtime archive. The cell verifies its SHA before installation.
# Arguments: pinned Polls version, output file. A local run can require a durable, previously verified archive.
set -eu
version=${1:?version}
output=${2:?output}
if [ -n "${MCP_IT_POLLS_ARCHIVE:-}" ]; then
	cp "$MCP_IT_POLLS_ARCHIVE" "$output"
else
	curl -fsSL "https://github.com/nextcloud-releases/polls/releases/download/v$version/polls-$version.tar.gz" -o "$output"
fi
