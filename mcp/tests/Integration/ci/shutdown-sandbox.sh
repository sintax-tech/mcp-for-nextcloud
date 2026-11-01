#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copies the real shutdown entrypoint into a test-owned checkout; fakes never see real run manifests.
# Usage: shutdown-sandbox.sh <temporary checkout root>
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
root=$1
ci="$root/mcp/tests/Integration/ci"
mkdir -p "$ci"
for script in run-local.sh engine-queue.sh shared-engine.sh cleanup-local-runs.sh local-artifact-root.sh; do
	cp "$HERE/$script" "$ci/$script"
done
sh "$ci/run-local.sh" --shutdown
